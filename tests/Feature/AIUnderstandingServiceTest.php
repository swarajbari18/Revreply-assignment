<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DataTransferObjects\EmailContext;
use App\Enums\WorkflowStatus;
use App\Exceptions\GeminiRateLimitException;
use App\Models\ConnectedAccount;
use App\Models\Workflow;
use App\Services\AIUnderstandingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AIUnderstandingServiceTest extends TestCase
{
    use RefreshDatabase;

    private AIUnderstandingService $service;

    private ConnectedAccount $account;

    private Workflow $workflow;

    private EmailContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        config(['gemini.api_key' => 'fake-gemini-key']);

        $this->service = new AIUnderstandingService;

        $this->account = ConnectedAccount::factory()->create([
            'gmail_email' => 'test@gmail.com',
        ]);

        $this->workflow = Workflow::create([
            'connected_account_id' => $this->account->id,
            'thread_id' => 'thread123',
            'latest_message_id' => 'msg123',
            'status' => WorkflowStatus::ContextBuilt,
            'correlation_id' => 'corr-123',
        ]);

        $this->context = new EmailContext(
            threadId: 'thread123',
            latestMessageId: 'msg123',
            gmailEmail: 'test@gmail.com',
            messages: [
                [
                    'role' => 'user',
                    'body' => 'I would like to schedule a call next Tuesday.',
                    'timestamp' => now()->toIso8601String(),
                    'from' => 'sender@example.com',
                    'to' => 'test@gmail.com',
                    'subject' => 'Meeting scheduling',
                ],
            ],
            attachmentTexts: [],
            preferredTone: 'professional',
            autoSendEnabled: false,
            autoSendConfidenceThreshold: 0.90,
            autoSendRiskThreshold: 0.10,
            ignoredSenderPatterns: ['noreply@'],
            correlationId: 'corr-123'
        );
    }

    public function test_ai_understanding_happy_path(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => json_encode([
                                    'classification' => 'meeting_request',
                                    'confidence' => 0.95,
                                    'risk' => 0.05,
                                    'suggested_draft' => 'Let us schedule a meeting!',
                                ])],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $classification = $this->service->understand($this->context, $this->workflow);

        $this->assertSame('meeting_request', $classification->classification);
        $this->assertSame(0.95, $classification->confidence);
        $this->assertSame(0.05, $classification->risk);
        $this->assertSame('Let us schedule a meeting!', $classification->generated_draft);

        $this->workflow->refresh();
        $this->assertSame(WorkflowStatus::AiComplete, $this->workflow->status);

        $this->assertDatabaseHas('classifications', [
            'workflow_id' => $this->workflow->id,
            'classification' => 'meeting_request',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'workflow_id' => $this->workflow->id,
            'event' => 'ai_classification_completed',
        ]);
    }

    public function test_ai_understanding_rate_limit_429(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([], 429),
        ]);

        $this->expectException(GeminiRateLimitException::class);
        $this->service->understand($this->context, $this->workflow);
    }

    public function test_ai_understanding_malformed_json_retry_success(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(['candidates' => [['content' => ['parts' => [['text' => 'invalid-json']]]]]], 200)
                ->push(['candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => json_encode([
                                    'classification' => 'interested',
                                    'confidence' => 0.98,
                                    'risk' => 0.02,
                                    'suggested_draft' => 'Retry works!',
                                ])],
                            ],
                        ],
                    ],
                ]], 200),
        ]);

        $classification = $this->service->understand($this->context, $this->workflow);

        $this->assertSame('interested', $classification->classification);
        $this->assertSame(0.98, $classification->confidence);
        $this->assertSame(0.02, $classification->risk);
        $this->assertSame('Retry works!', $classification->generated_draft);
    }

    public function test_ai_understanding_malformed_json_fallback_failure(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'persistently-malformed-json'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $classification = $this->service->understand($this->context, $this->workflow);

        $this->assertSame('unclear', $classification->classification);
        $this->assertSame(0.0, $classification->confidence);
        $this->assertSame(1.0, $classification->risk);
        $this->assertSame('', $classification->generated_draft);

        $this->workflow->refresh();
        $this->assertSame(WorkflowStatus::AiComplete, $this->workflow->status);
    }
}
