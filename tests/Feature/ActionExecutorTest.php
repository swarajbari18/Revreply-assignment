<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ConnectedAccountStatus;
use App\Enums\WorkflowStatus;
use App\Models\Classification;
use App\Models\ConnectedAccount;
use App\Models\Workflow;
use App\Services\ActionExecutor;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActionExecutorTest extends TestCase
{
    use RefreshDatabase;

    private ActionExecutor $executor;

    private ConnectedAccount $account;

    private Workflow $workflow;

    private Classification $classification;

    protected function setUp(): void
    {
        parent::setUp();
        $this->executor = $this->app->make(ActionExecutor::class);

        $this->account = ConnectedAccount::factory()->create([
            'gmail_email' => 'test@gmail.com',
            'status' => ConnectedAccountStatus::Connected,
            'access_token' => 'fake-token',
            'token_expires_at' => now()->addHour(),
        ]);

        $this->workflow = Workflow::create([
            'connected_account_id' => $this->account->id,
            'thread_id' => 'thread123',
            'latest_message_id' => 'msg123',
            'status' => WorkflowStatus::DecisionComplete,
            'correlation_id' => 'corr-123',
        ]);

        $this->classification = Classification::create([
            'workflow_id' => $this->workflow->id,
            'classification' => 'interested',
            'confidence' => 0.95,
            'risk' => 0.05,
            'generated_draft' => 'I would love to help you with that.',
            'prompt_version' => '1.0',
            'model_version' => 'gemini-2.5-flash',
        ]);
    }

    public function test_action_executor_ignore(): void
    {
        $this->executor->execute($this->workflow, $this->classification, 'Ignore');

        $this->workflow->refresh();
        $this->assertSame(WorkflowStatus::Ignored, $this->workflow->status);

        $this->assertDatabaseHas('audit_logs', [
            'workflow_id' => $this->workflow->id,
            'event' => 'workflow_ignored',
        ]);
    }

    public function test_action_executor_escalate(): void
    {
        $this->executor->execute($this->workflow, $this->classification, 'Escalate');

        $this->workflow->refresh();
        $this->assertSame(WorkflowStatus::Failed, $this->workflow->status);
        $this->assertSame('escalated_for_manual_review', $this->workflow->failure_reason);

        $this->assertDatabaseHas('audit_logs', [
            'workflow_id' => $this->workflow->id,
            'event' => 'workflow_escalated',
        ]);
    }

    public function test_action_executor_auto_reply_happy_path(): void
    {
        $threadResponse = json_encode([
            'id' => 'thread123',
            'messages' => [
                ['id' => 'msg123'],
            ],
        ]);

        $messageResponse = json_encode([
            'id' => 'msg123',
            'payload' => [
                'headers' => [
                    ['name' => 'Message-ID', 'value' => '<msg-id-123@gmail.com>'],
                    ['name' => 'Subject', 'value' => 'Interested in Revreply'],
                    ['name' => 'From', 'value' => 'prospect@example.com'],
                    ['name' => 'To', 'value' => 'test@gmail.com'],
                ],
            ],
        ]);

        $sendResponse = json_encode([
            'id' => 'sent123',
            'threadId' => 'thread123',
        ]);

        $this->mockGmailClient([
            new Response(200, [], $threadResponse),  // getThread
            new Response(200, [], $messageResponse), // getMessage
            new Response(200, [], $sendResponse),    // send
        ]);

        $this->executor->execute($this->workflow, $this->classification, 'AutoReply');

        $this->workflow->refresh();
        $this->assertSame(WorkflowStatus::Sent, $this->workflow->status);
        $this->assertNotNull($this->workflow->completed_at);

        $this->assertDatabaseHas('audit_logs', [
            'workflow_id' => $this->workflow->id,
            'event' => 'email_sent',
        ]);
    }

    public function test_action_executor_auto_reply_aborts_on_stale_context(): void
    {
        // Thread returns a newer message ID (msg456) compared to msg123 in the database
        $threadResponse = json_encode([
            'id' => 'thread123',
            'messages' => [
                ['id' => 'msg123'],
                ['id' => 'msg456'],
            ],
        ]);

        $this->mockGmailClient([
            new Response(200, [], $threadResponse), // getThread
        ]);

        $this->executor->execute($this->workflow, $this->classification, 'AutoReply');

        $this->workflow->refresh();
        $this->assertSame(WorkflowStatus::Ignored, $this->workflow->status);

        $this->assertDatabaseHas('audit_logs', [
            'workflow_id' => $this->workflow->id,
            'event' => 'execution_aborted_stale_context',
        ]);
    }

    public function test_action_executor_draft_happy_path(): void
    {
        $threadResponse = json_encode([
            'id' => 'thread123',
            'messages' => [
                ['id' => 'msg123'],
            ],
        ]);

        $messageResponse = json_encode([
            'id' => 'msg123',
            'payload' => [
                'headers' => [
                    ['name' => 'Message-ID', 'value' => '<msg-id-123@gmail.com>'],
                    ['name' => 'Subject', 'value' => 'Interested in Revreply'],
                    ['name' => 'From', 'value' => 'prospect@example.com'],
                ],
            ],
        ]);

        $draftResponse = json_encode([
            'id' => 'draft123',
            'message' => [
                'id' => 'msg789',
                'threadId' => 'thread123',
            ],
        ]);

        $this->mockGmailClient([
            new Response(200, [], $threadResponse),  // getThread
            new Response(200, [], $messageResponse), // getMessage
            new Response(200, [], $draftResponse),   // create draft
        ]);

        $this->executor->execute($this->workflow, $this->classification, 'Draft');

        $this->workflow->refresh();
        $this->assertSame(WorkflowStatus::DraftCreated, $this->workflow->status);
        $this->assertSame('draft123', $this->workflow->draft_id);

        $this->assertDatabaseHas('audit_logs', [
            'workflow_id' => $this->workflow->id,
            'event' => 'draft_created',
        ]);
    }

    public function test_action_executor_auth_revoked(): void
    {
        // Simulate a 401 Unauthorized response from Gmail
        $this->mockGmailClient([
            new Response(401, [], json_encode([
                'error' => [
                    'code' => 401,
                    'message' => 'invalid_grant',
                    'status' => 'UNAUTHENTICATED',
                ],
            ])),
        ]);

        $this->executor->execute($this->workflow, $this->classification, 'Draft');

        $this->workflow->refresh();
        $this->assertSame(WorkflowStatus::Failed, $this->workflow->status);
        $this->assertSame('gmail_auth_revoked', $this->workflow->failure_reason);

        $this->account->refresh();
        $this->assertSame(ConnectedAccountStatus::Disconnected, $this->account->status);
        $this->assertNull($this->account->access_token);
        $this->assertNull($this->account->refresh_token);
    }
}
