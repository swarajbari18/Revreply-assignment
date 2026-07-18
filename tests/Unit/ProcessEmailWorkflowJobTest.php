<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Jobs\ProcessEmailWorkflowJob;
use App\Models\Workflow;
use App\Services\ActionExecutor;
use App\Services\AIUnderstandingService;
use App\Services\ContextBuilderService;
use App\Services\DecisionEngine;
use App\Services\Gmail\GmailTokenService;
use Google\Client;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProcessEmailWorkflowJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_can_be_instantiated_with_ids(): void
    {
        $job = new ProcessEmailWorkflowJob(1, 2);

        $this->assertSame(1, $job->workflowId);
        $this->assertSame(2, $job->connectedAccountId);
    }

    public function test_job_implements_should_queue(): void
    {
        $job = new ProcessEmailWorkflowJob(1, 2);

        $this->assertInstanceOf(ShouldQueue::class, $job);
    }

    public function test_job_has_tries_and_backoff(): void
    {
        $job = new ProcessEmailWorkflowJob(1, 2);

        $this->assertSame(5, $job->tries);
        $this->assertSame([5, 10, 20, 40, 80], $job->backoff());
    }

    public function test_job_logs_picked_up_audit_event(): void
    {
        config(['gemini.api_key' => 'fake-gemini-key']);
        config(['services.workflow.run_full_pipeline' => true]);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => json_encode([
                                'classification' => 'unclear',
                                'confidence' => 0.5,
                                'risk' => 0.5,
                                'suggested_draft' => 'Draft body',
                            ]),
                        ]],
                    ],
                ]],
            ]),
        ]);

        $workflow = Workflow::factory()->create();

        $threadResponse = json_encode([
            'id' => $workflow->thread_id,
            'messages' => [
                [
                    'id' => $workflow->latest_message_id,
                    'internalDate' => (string) (now()->getTimestamp() * 1000),
                    'payload' => [
                        'headers' => [
                            ['name' => 'From', 'value' => 'sender@example.com'],
                            ['name' => 'To', 'value' => 'test@gmail.com'],
                        ],
                        'mimeType' => 'text/plain',
                        'body' => [
                            'data' => base64_encode('Hello!'),
                        ],
                    ],
                ],
            ],
        ]);

        $this->mockGmailClient([
            new Response(200, [], $threadResponse),
        ]);

        $job = new ProcessEmailWorkflowJob($workflow->id, $workflow->connected_account_id);
        $job->handle(
            $this->app->make(ContextBuilderService::class),
            $this->app->make(AIUnderstandingService::class),
            $this->app->make(DecisionEngine::class),
            $this->app->make(ActionExecutor::class),
            $this->app->make(GmailTokenService::class),
            $this->app->make(Client::class)
        );

        $this->assertDatabaseHas('audit_logs', [
            'workflow_id' => $workflow->id,
            'event' => 'workflow_picked_up',
        ]);
    }
}
