<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\WorkflowStatus;
use App\Exceptions\GeminiRateLimitException;
use App\Models\AuditLog;
use App\Models\Workflow;
use App\Services\ActionExecutor;
use App\Services\AIUnderstandingService;
use App\Services\ContextBuilderService;
use App\Services\DecisionEngine;
use App\Services\Gmail\GmailTokenService;
use Google\Client;
use Google\Service\Gmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessEmailWorkflowJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(
        public int $workflowId,
        public int $connectedAccountId
    ) {}

    public function handle(
        ContextBuilderService $contextBuilder,
        AIUnderstandingService $aiUnderstanding,
        DecisionEngine $decisionEngine,
        ActionExecutor $actionExecutor,
        GmailTokenService $tokenService,
        Client $client
    ): void {
        $workflow = Workflow::findOrFail($this->workflowId);

        if (in_array($workflow->status, [WorkflowStatus::Sent, WorkflowStatus::Ignored, WorkflowStatus::Failed], true)) {
            Log::info("ProcessEmailWorkflowJob: workflow {$this->workflowId} is already in a terminal status, exiting cleanly.");

            return;
        }

        Log::info('ProcessEmailWorkflowJob started', [
            'workflow_id' => $this->workflowId,
            'connected_account_id' => $this->connectedAccountId,
        ]);

        AuditLog::create([
            'workflow_id' => $workflow->id,
            'correlation_id' => $workflow->correlation_id,
            'event' => 'workflow_picked_up',
            'metadata' => [
                'processed_at' => now()->toIso8601String(),
            ],
        ]);

        // Step 1: Context building (resolves threadId and updates status to ContextBuilt)
        $context = $contextBuilder->build($this->workflowId, $this->connectedAccountId);

        if ($context === null) {
            Log::info('ProcessEmailWorkflowJob: context building returned null, exiting cleanly.', [
                'workflow_id' => $this->workflowId,
            ]);

            return;
        }

        $threadId = $context->threadId;

        // Step 2: Thread-Level Redis Lock (looping protection)
        $lockKey = "thread_lock:{$threadId}";
        $lock = Cache::store('redis')->lock($lockKey, 60);

        if (! $lock->acquire()) {
            Log::info("ProcessEmailWorkflowJob: thread lock could not be acquired for thread {$threadId}. Releasing job back to queue.");
            $this->release(5);

            return;
        }

        try {
            // Refresh workflow to ensure we have the latest state inside the lock
            $workflow->refresh();

            if (in_array($workflow->status, [WorkflowStatus::Sent, WorkflowStatus::Ignored, WorkflowStatus::Failed], true)) {
                Log::info("ProcessEmailWorkflowJob: workflow {$this->workflowId} was marked terminal while waiting for lock, exiting.");

                return;
            }

            // Step 3: Check for newer workflows on the same thread (Double notifications concurrency protection)
            $newerWorkflowExists = Workflow::where('connected_account_id', $this->connectedAccountId)
                ->where('thread_id', $threadId)
                ->where('id', '>', $workflow->id)
                ->whereIn('status', [
                    WorkflowStatus::Queued,
                    WorkflowStatus::Processing,
                    WorkflowStatus::ContextBuilt,
                ])
                ->exists();

            if ($newerWorkflowExists) {
                Log::info("ProcessEmailWorkflowJob: a newer workflow exists for thread {$threadId}. Superseding current workflow {$workflow->id}.");

                // Discard draft if already created
                if (! empty($workflow->draft_id)) {
                    try {
                        $account = $workflow->connectedAccount;
                        $accessToken = $tokenService->getValidAccessToken($account);
                        $client->setAccessToken(['access_token' => $accessToken]);
                        $gmailService = new Gmail($client);
                        $gmailService->users_drafts->delete('me', $workflow->draft_id);
                    } catch (\Exception $e) {
                        Log::warning("ProcessEmailWorkflowJob: failed to delete draft {$workflow->draft_id} while superseding: ".$e->getMessage());
                    }
                }

                $workflow->update([
                    'status' => WorkflowStatus::Ignored,
                ]);

                AuditLog::create([
                    'workflow_id' => $workflow->id,
                    'correlation_id' => $workflow->correlation_id,
                    'event' => 'workflow_superceded',
                    'metadata' => [
                        'reason' => 'newer_message_received',
                    ],
                ]);

                return;
            }

            // Step 4: AI Understanding
            if (! config('services.workflow.run_full_pipeline', true)) {
                return;
            }

            $classification = $workflow->classification;
            if (! $classification) {
                $classification = $aiUnderstanding->understand($context, $workflow);
            }

            // Step 5: Decision Engine
            $decision = $decisionEngine->decide($context, $classification, $workflow);

            // Step 6: Action Executor
            $actionExecutor->execute($workflow, $classification, $decision);

        } catch (GeminiRateLimitException $e) {
            Log::warning("ProcessEmailWorkflowJob: Gemini API rate limit hit (429) for workflow {$workflow->id}. Releasing job with backoff.");
            $this->release(30);
        } catch (\Throwable $e) {
            Log::error('ProcessEmailWorkflowJob error: '.$e->getMessage(), [
                'exception' => $e,
            ]);
            throw $e;
        } finally {
            $lock->release();
        }
    }

    public function backoff(): array
    {
        return [5, 10, 20, 40, 80];
    }
}
