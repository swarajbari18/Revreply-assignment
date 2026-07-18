<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ConnectedAccountStatus;
use App\Enums\WorkflowStatus;
use App\Jobs\ProcessEmailWorkflowJob;
use App\Models\Classification;
use App\Models\ConnectedAccount;
use App\Models\Workflow;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProcessEmailWorkflowJobTest extends TestCase
{
    use RefreshDatabase;

    private ConnectedAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        config(['gemini.api_key' => 'fake-gemini-key']);
        config(['services.workflow.run_full_pipeline' => true]);
        // Ensure user preferences are mocked or cache is flushed
        Cache::flush();

        $this->account = ConnectedAccount::factory()->create([
            'gmail_email' => 'test@gmail.com',
            'status' => ConnectedAccountStatus::Connected,
            'access_token' => 'fake-token',
            'token_expires_at' => now()->addHour(),
        ]);
    }

    public function test_orchestrator_job_happy_path_auto_reply(): void
    {
        $workflow = Workflow::create([
            'connected_account_id' => $this->account->id,
            'thread_id' => 'thread123',
            'latest_message_id' => 'msg123',
            'status' => WorkflowStatus::Queued,
            'correlation_id' => 'corr-123',
        ]);

        // Guzzle mock responses for ActionExecutor/ContextBuilder:
        // 1. Thread get (ContextBuilder)
        // 2. Thread get (ActionExecutor stale context check)
        // 3. Message get (ActionExecutor get headers)
        // 4. Message send (ActionExecutor send reply)
        $threadResponse = json_encode([
            'id' => 'thread123',
            'messages' => [
                [
                    'id' => 'msg123',
                    'threadId' => 'thread123',
                    'payload' => [
                        'mimeType' => 'text/plain',
                        'headers' => [
                            ['name' => 'From', 'value' => 'prospect@example.com'],
                            ['name' => 'To', 'value' => 'test@gmail.com'],
                            ['name' => 'Subject', 'value' => 'Meeting Interest'],
                            ['name' => 'Message-ID', 'value' => '<msg-id-123@gmail.com>'],
                        ],
                        'body' => ['data' => base64_encode('I am interested, please respond')],
                    ],
                ],
            ],
        ]);

        $this->mockGmailClient([
            new Response(200, [], $threadResponse),  // ContextBuilder getThread
            new Response(200, [], $threadResponse),  // ActionExecutor getThread (stale check)
            new Response(200, [], $threadResponse),  // ActionExecutor getMessage (headers)
            new Response(200, [], json_encode(['id' => 'sent123', 'threadId' => 'thread123'])), // send
        ]);

        // Mock Gemini classification: high confidence, low risk -> AutoReply
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => json_encode([
                                'classification' => 'interested',
                                'confidence' => 0.95,
                                'risk' => 0.05,
                                'suggested_draft' => 'Sounds great!',
                            ]),
                        ]],
                    ],
                ]],
            ], 200),
        ]);

        // Set user preferences to enable auto-send
        Cache::put("user_prefs:{$this->account->user_id}", [
            'preferredTone' => 'professional',
            'autoSendEnabled' => true,
            'autoSendConfidenceThreshold' => 0.90,
            'autoSendRiskThreshold' => 0.10,
            'ignoredSenderPatterns' => ['noreply@'],
        ], 300);

        ProcessEmailWorkflowJob::dispatchSync($workflow->id, $this->account->id);

        $workflow->refresh();
        $this->assertSame(WorkflowStatus::Sent, $workflow->status);
        $this->assertDatabaseHas('classifications', [
            'workflow_id' => $workflow->id,
            'classification' => 'interested',
        ]);
    }

    public function test_orchestrator_job_supersedes_if_newer_workflow_queued(): void
    {
        $workflowOld = Workflow::create([
            'connected_account_id' => $this->account->id,
            'thread_id' => 'thread123',
            'latest_message_id' => 'msg121',
            'status' => WorkflowStatus::Queued,
            'correlation_id' => 'corr-121',
        ]);

        // Newer workflow queued on same thread
        $workflowNew = Workflow::create([
            'connected_account_id' => $this->account->id,
            'thread_id' => 'thread123',
            'latest_message_id' => 'msg122',
            'status' => WorkflowStatus::Queued,
            'correlation_id' => 'corr-122',
        ]);

        $threadResponse = json_encode([
            'id' => 'thread123',
            'messages' => [
                [
                    'id' => 'msg121',
                    'threadId' => 'thread123',
                    'payload' => [
                        'mimeType' => 'text/plain',
                        'headers' => [
                            ['name' => 'From', 'value' => 'prospect@example.com'],
                            ['name' => 'To', 'value' => 'test@gmail.com'],
                            ['name' => 'Subject', 'value' => 'Meeting Interest'],
                        ],
                        'body' => ['data' => base64_encode('First message')],
                    ],
                ],
            ],
        ]);

        $this->mockGmailClient([
            new Response(200, [], $threadResponse),  // ContextBuilder getThread
        ]);

        ProcessEmailWorkflowJob::dispatchSync($workflowOld->id, $this->account->id);

        $workflowOld->refresh();
        // Should be Ignored (superseded)
        $this->assertSame(WorkflowStatus::Ignored, $workflowOld->status);
        $this->assertDatabaseHas('audit_logs', [
            'workflow_id' => $workflowOld->id,
            'event' => 'workflow_superceded',
        ]);
    }

    public function test_orchestrator_job_pending_draft_sync(): void
    {
        // 1. Previous workflow in draft_created state
        $workflowOld = Workflow::create([
            'connected_account_id' => $this->account->id,
            'thread_id' => 'thread123',
            'latest_message_id' => 'msg121',
            'status' => WorkflowStatus::DraftCreated,
            'draft_id' => 'draft999',
            'correlation_id' => 'corr-121',
        ]);

        // 2. New workflow queued
        $workflowNew = Workflow::create([
            'connected_account_id' => $this->account->id,
            'thread_id' => 'thread123',
            'latest_message_id' => 'msg122',
            'status' => WorkflowStatus::Queued,
            'correlation_id' => 'corr-122',
        ]);

        // Mock response list:
        // - getThread (ContextBuilder new workflow)
        // - getDraft (ContextBuilder reading draft999)
        // - deleteDraft (ContextBuilder deleting draft999)
        // - getThread (ActionExecutor stale context check)
        // - getMessage (ActionExecutor message details for headers)
        // - createDraft (ActionExecutor create draft)
        $threadResponse = json_encode([
            'id' => 'thread123',
            'messages' => [
                [
                    'id' => 'msg121',
                    'threadId' => 'thread123',
                    'payload' => [
                        'mimeType' => 'text/plain',
                        'headers' => [
                            ['name' => 'From', 'value' => 'prospect@example.com'],
                            ['name' => 'To', 'value' => 'test@gmail.com'],
                            ['name' => 'Subject', 'value' => 'Meeting Interest'],
                        ],
                        'body' => ['data' => base64_encode('First message')],
                    ],
                ],
                [
                    'id' => 'msg122',
                    'threadId' => 'thread123',
                    'payload' => [
                        'mimeType' => 'text/plain',
                        'headers' => [
                            ['name' => 'From', 'value' => 'prospect@example.com'],
                            ['name' => 'To', 'value' => 'test@gmail.com'],
                            ['name' => 'Subject', 'value' => 'Meeting Interest'],
                        ],
                        'body' => ['data' => base64_encode('Second message')],
                    ],
                ],
            ],
        ]);

        $draftResponse = json_encode([
            'id' => 'draft999',
            'message' => [
                'id' => 'msg999',
                'payload' => [
                    'mimeType' => 'text/plain',
                    'body' => ['data' => base64_encode('This is the old pending draft body')],
                ],
            ],
        ]);

        $createdDraftResponse = json_encode([
            'id' => 'draft1000',
            'message' => [
                'id' => 'msg1000',
                'threadId' => 'thread123',
            ],
        ]);

        $this->mockGmailClient([
            new Response(200, [], $threadResponse),  // ContextBuilder getThread
            new Response(200, [], $draftResponse),   // ContextBuilder getDraft
            new Response(200, [], '{}'),            // ContextBuilder deleteDraft
            new Response(200, [], $threadResponse),  // ActionExecutor getThread (stale check)
            new Response(200, [], $threadResponse),  // ActionExecutor getMessage (headers)
            new Response(200, [], $createdDraftResponse), // ActionExecutor create draft
        ]);

        // Mock Gemini response: high confidence, high risk -> Draft
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => json_encode([
                                'classification' => 'interested',
                                'confidence' => 0.95,
                                'risk' => 0.45,
                                'suggested_draft' => 'Revised draft based on old response.',
                            ]),
                        ]],
                    ],
                ]],
            ], 200),
        ]);

        ProcessEmailWorkflowJob::dispatchSync($workflowNew->id, $this->account->id);

        $workflowOld->refresh();
        $this->assertSame(WorkflowStatus::Ignored, $workflowOld->status);

        $workflowNew->refresh();
        $this->assertSame(WorkflowStatus::DraftCreated, $workflowNew->status);
        $this->assertSame('draft1000', $workflowNew->draft_id);
    }
}
