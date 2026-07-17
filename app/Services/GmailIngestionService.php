<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\WorkflowStatus;
use App\Jobs\ProcessEmailWorkflowJob;
use App\Models\AuditLog;
use App\Models\ConnectedAccount;
use App\Models\ProcessedNotification;
use App\Models\Workflow;
use App\Services\Gmail\GmailTokenService;
use Google\Client;
use Google\Service\Gmail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GmailIngestionService
{
    public function __construct(
        private readonly Client $client,
        private readonly GmailTokenService $tokenService
    ) {}

    public function ingest(array $payload): void
    {
        $parsed = $this->parseEnvelope($payload);
        if ($parsed === null) {
            Log::warning('Malformed Pub/Sub payload received', $payload);
            return;
        }

        $gmailEmail = $parsed['emailAddress'];
        $historyId = (string) $parsed['historyId'];

        if ($this->isAlreadyProcessed($gmailEmail, $historyId)) {
            Log::info('Notification already processed', [
                'gmail_email' => $gmailEmail,
                'history_id' => $historyId,
            ]);
            return;
        }

        $account = $this->resolveConnectedAccount($gmailEmail);
        if ($account === null) {
            Log::warning('Unknown Gmail account notification received', [
                'gmail_email' => $gmailEmail,
            ]);
            return;
        }

        if (!$account->isConnected()) {
            Log::info('Notification received for disconnected account', [
                'gmail_email' => $gmailEmail,
            ]);
            return;
        }

        $lastHistoryId = $account->last_history_id;
        if ($lastHistoryId !== null && (int) $historyId > (int) $lastHistoryId + 1) {
            Log::info('Gap detected, triggering inline recovery', [
                'account_id' => $account->id,
                'last_history_id' => $lastHistoryId,
                'incoming_history_id' => $historyId,
            ]);
            $this->recoverGap($account, $lastHistoryId);

            if ($this->isAlreadyProcessed($gmailEmail, $historyId)) {
                $account->refresh();
                $currentLastHistoryId = $account->last_history_id;
                if ($currentLastHistoryId === null || (int) $historyId > (int) $currentLastHistoryId) {
                    $account->forceFill([
                        'last_history_id' => $historyId,
                    ])->save();
                    $this->clearAccountCache($account->gmail_email);
                }
                return;
            }
        }

        $correlationId = (string) Str::uuid();

        $workflow = Workflow::create([
            'connected_account_id' => $account->id,
            'thread_id' => null,
            'latest_message_id' => null,
            'status' => WorkflowStatus::Received,
            'correlation_id' => $correlationId,
            'started_at' => now(),
        ]);

        ProcessEmailWorkflowJob::dispatch($workflow->id, $account->id);

        $workflow->update([
            'status' => WorkflowStatus::Queued,
        ]);

        DB::transaction(function () use ($workflow, $account, $gmailEmail, $historyId, $correlationId) {
            ProcessedNotification::create([
                'idempotency_key' => $this->buildIdempotencyKey($gmailEmail, $historyId),
                'connected_account_id' => $account->id,
                'processed_at' => now(),
            ]);

            AuditLog::create([
                'workflow_id' => $workflow->id,
                'correlation_id' => $correlationId,
                'event' => 'notification_received',
                'metadata' => [
                    'history_id' => $historyId,
                    'connected_account_id' => $account->id,
                ],
            ]);
        });

        $account->refresh();
        $currentLastHistoryId = $account->last_history_id;
        if ($currentLastHistoryId === null || (int) $historyId > (int) $currentLastHistoryId) {
            $account->forceFill([
                'last_history_id' => $historyId,
            ])->save();
            $this->clearAccountCache($account->gmail_email);
        }
    }

    private function parseEnvelope(array $payload): ?array
    {
        if (!isset($payload['message']['data'])) {
            return null;
        }

        $data = base64_decode($payload['message']['data'], true);
        if ($data === false) {
            return null;
        }

        $decoded = json_decode($data, true);
        if (!is_array($decoded) || !isset($decoded['emailAddress']) || !isset($decoded['historyId'])) {
            return null;
        }

        return [
            'emailAddress' => $decoded['emailAddress'],
            'historyId' => $decoded['historyId'],
        ];
    }

    private function isAlreadyProcessed(string $gmailEmail, string $historyId): bool
    {
        $key = $this->buildIdempotencyKey($gmailEmail, $historyId);
        return ProcessedNotification::where('idempotency_key', $key)->exists();
    }

    private function buildIdempotencyKey(string $gmailEmail, string $historyId): string
    {
        return $gmailEmail . '|' . $historyId;
    }

    public function resolveConnectedAccount(string $gmailEmail): ?ConnectedAccount
    {
        $cacheKey = 'ingestion_account:' . $gmailEmail;

        $cachedAttributes = Cache::remember($cacheKey, 300, function () use ($gmailEmail) {
            $account = ConnectedAccount::where('gmail_email', $gmailEmail)->first();
            return $account ? $account->getRawOriginal() : null;
        });

        if ($cachedAttributes === null) {
            return null;
        }

        return (new ConnectedAccount)->newFromBuilder($cachedAttributes);
    }

    public function clearAccountCache(string $gmailEmail): void
    {
        Cache::forget('ingestion_account:' . $gmailEmail);
    }

    public function recoverGap(ConnectedAccount $account, string $startHistoryId): void
    {
        try {
            $accessToken = $this->tokenService->getValidAccessToken($account);
            $this->client->setAccessToken(['access_token' => $accessToken]);
            $gmailService = new Gmail($this->client);

            $response = $gmailService->users_history->listUsersHistory('me', [
                'startHistoryId' => $startHistoryId,
            ]);

            $histories = $response->getHistory();
            $maxHistoryId = $startHistoryId;

            if (!empty($histories)) {
                foreach ($histories as $history) {
                    $historyId = (string) $history->getId();
                    if ((int) $historyId > (int) $maxHistoryId) {
                        $maxHistoryId = $historyId;
                    }

                    if ($this->isAlreadyProcessed($account->gmail_email, $historyId)) {
                        continue;
                    }

                    $messagesAdded = $history->getMessagesAdded();
                    if (!empty($messagesAdded)) {
                        foreach ($messagesAdded as $messageAdded) {
                            $message = $messageAdded->getMessage();
                            $this->ingestRecoveredMessage($account, $message, $historyId);
                        }
                    }
                }
            }

            if ((int) $maxHistoryId > (int) ($account->last_history_id ?? 0)) {
                $account->forceFill([
                    'last_history_id' => $maxHistoryId,
                ])->save();
                $this->clearAccountCache($account->gmail_email);
            }
        } catch (\Google\Service\Exception $exception) {
            if ($exception->getCode() === 404) {
                $gmailService = new Gmail($this->client);
                $profile = $gmailService->users->getProfile('me');
                $currentHistoryId = (string) $profile->getHistoryId();

                $account->forceFill([
                    'last_history_id' => $currentHistoryId,
                ])->save();

                $this->clearAccountCache($account->gmail_email);

                Log::warning('History too stale, reset last_history_id', [
                    'account_id' => $account->id,
                    'new_history_id' => $currentHistoryId,
                ]);

                \App\Jobs\RenewGmailWatchJob::dispatch($account);
                return;
            }
            throw $exception;
        }
    }

    private function ingestRecoveredMessage(ConnectedAccount $account, \Google\Service\Gmail\Message $message, string $historyId): void
    {
        $correlationId = (string) Str::uuid();

        $workflow = Workflow::create([
            'connected_account_id' => $account->id,
            'thread_id' => $message->getThreadId(),
            'latest_message_id' => $message->getId(),
            'status' => WorkflowStatus::Received,
            'correlation_id' => $correlationId,
            'started_at' => now(),
        ]);

        ProcessEmailWorkflowJob::dispatch($workflow->id, $account->id);

        $workflow->update([
            'status' => WorkflowStatus::Queued,
        ]);

        DB::transaction(function () use ($workflow, $account, $historyId, $correlationId) {
            ProcessedNotification::create([
                'idempotency_key' => $this->buildIdempotencyKey($account->gmail_email, $historyId),
                'connected_account_id' => $account->id,
                'processed_at' => now(),
            ]);

            AuditLog::create([
                'workflow_id' => $workflow->id,
                'correlation_id' => $correlationId,
                'event' => 'notification_received',
                'metadata' => [
                    'history_id' => $historyId,
                    'connected_account_id' => $account->id,
                ],
            ]);
        });
    }
}
