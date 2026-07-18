<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ConnectedAccountStatus;
use App\Enums\WorkflowStatus;
use App\Models\Classification;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Models\Workflow;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ConnectedAccount $account;

    private Workflow $workflow;

    private Classification $classification;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->account = ConnectedAccount::factory()->create([
            'user_id' => $this->user->id,
            'gmail_email' => 'test@gmail.com',
            'status' => ConnectedAccountStatus::Connected,
            'access_token' => 'fake-token',
            'token_expires_at' => now()->addHour(),
        ]);

        $this->workflow = Workflow::create([
            'connected_account_id' => $this->account->id,
            'thread_id' => 'thread123',
            'latest_message_id' => 'msg123',
            'status' => WorkflowStatus::DraftCreated,
            'draft_id' => 'draft999',
            'correlation_id' => 'corr-123',
        ]);

        $this->classification = Classification::create([
            'workflow_id' => $this->workflow->id,
            'classification' => 'interested',
            'confidence' => 0.95,
            'risk' => 0.05,
            'generated_draft' => 'Database original draft response body.',
            'prompt_version' => '1.0',
            'model_version' => 'gemini-2.5-flash',
        ]);
    }

    public function test_workflow_index(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson(route('workflows.index', ['account_id' => $this->account->id]));

        $response->assertStatus(200);
        $response->assertJsonFragment(['thread_id' => 'thread123']);
    }

    public function test_workflow_show(): void
    {
        $threadResponse = json_encode([
            'id' => 'thread123',
            'messages' => [
                [
                    'id' => 'msg123',
                    'payload' => [
                        'headers' => [
                            ['name' => 'From', 'value' => 'prospect@example.com'],
                            ['name' => 'To', 'value' => 'test@gmail.com'],
                            ['name' => 'Subject', 'value' => 'Revreply Inquiry'],
                        ],
                        'mimeType' => 'text/plain',
                        'body' => ['data' => base64_encode('Interested in demo')],
                    ],
                ],
            ],
        ]);

        $this->mockGmailClient([
            new Response(200, [], $threadResponse),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson(route('workflows.show', ['id' => $this->workflow->id]));

        $response->assertStatus(200);
        $response->assertJsonStructure(['workflow', 'messages']);
        $response->assertJsonFragment(['body' => 'Interested in demo']);
    }

    public function test_workflow_approve_happy_path(): void
    {
        $draftResponse = json_encode([
            'id' => 'draft999',
            'message' => [
                'id' => 'msg999',
                'payload' => [
                    'mimeType' => 'text/plain',
                    'body' => ['data' => base64_encode('Database original draft response body.')],
                ],
            ],
        ]);

        $this->mockGmailClient([
            new Response(200, [], $draftResponse), // get draft
            new Response(200, [], '{}'),           // send draft
        ]);

        $response = $this->actingAs($this->user)
            ->postJson(route('workflows.approve', ['id' => $this->workflow->id]));

        $response->assertStatus(200);
        $this->workflow->refresh();
        $this->assertSame(WorkflowStatus::Sent, $this->workflow->status);

        $this->assertDatabaseHas('audit_logs', [
            'workflow_id' => $this->workflow->id,
            'event' => 'draft_approved_and_sent',
        ]);
    }

    public function test_workflow_approve_synced_user_edits(): void
    {
        // Body returned from Gmail has edits
        $draftResponseWithEdits = json_encode([
            'id' => 'draft999',
            'message' => [
                'id' => 'msg999',
                'payload' => [
                    'mimeType' => 'text/plain',
                    'body' => ['data' => base64_encode('Gmail user edited draft response body.')],
                ],
            ],
        ]);

        $this->mockGmailClient([
            new Response(200, [], $draftResponseWithEdits), // get draft
            new Response(200, [], '{}'),                    // send draft
        ]);

        $response = $this->actingAs($this->user)
            ->postJson(route('workflows.approve', ['id' => $this->workflow->id]));

        $response->assertStatus(200);

        // Assert DB is updated with edits
        $this->classification->refresh();
        $this->assertSame('Gmail user edited draft response body.', $this->classification->generated_draft);

        $this->workflow->refresh();
        $this->assertSame(WorkflowStatus::Sent, $this->workflow->status);

        $this->assertDatabaseHas('audit_logs', [
            'workflow_id' => $this->workflow->id,
            'event' => 'draft_sent_with_user_edits',
        ]);
    }

    public function test_workflow_send_edit_draft(): void
    {
        $messageResponse = json_encode([
            'id' => 'msg123',
            'payload' => [
                'headers' => [
                    ['name' => 'Message-ID', 'value' => '<msg-id-123@gmail.com>'],
                    ['name' => 'Subject', 'value' => 'Revreply Inquiry'],
                    ['name' => 'From', 'value' => 'prospect@example.com'],
                ],
            ],
        ]);

        $this->mockGmailClient([
            new Response(200, [], $messageResponse), // getMessage
            new Response(200, [], '{}'),             // update draft
            new Response(200, [], '{}'),             // send draft
        ]);

        $response = $this->actingAs($this->user)
            ->postJson(route('workflows.send', ['id' => $this->workflow->id]), [
                'body' => 'Manually customized draft response body.',
            ]);

        $response->assertStatus(200);

        $this->classification->refresh();
        $this->assertSame('Manually customized draft response body.', $this->classification->generated_draft);

        $this->workflow->refresh();
        $this->assertSame(WorkflowStatus::Sent, $this->workflow->status);

        $this->assertDatabaseHas('audit_logs', [
            'workflow_id' => $this->workflow->id,
            'event' => 'draft_edited_and_sent',
        ]);
    }

    public function test_workflow_send_escalated_scratch(): void
    {
        // 1. Mark workflow as Failed (Escalated)
        $this->workflow->update([
            'status' => WorkflowStatus::Failed,
            'draft_id' => null,
            'failure_reason' => 'escalated_for_manual_review',
        ]);

        $messageResponse = json_encode([
            'id' => 'msg123',
            'payload' => [
                'headers' => [
                    ['name' => 'Message-ID', 'value' => '<msg-id-123@gmail.com>'],
                    ['name' => 'Subject', 'value' => 'Revreply Inquiry'],
                    ['name' => 'From', 'value' => 'prospect@example.com'],
                ],
            ],
        ]);

        $this->mockGmailClient([
            new Response(200, [], $messageResponse), // getMessage
            new Response(200, [], '{}'),             // send direct message
        ]);

        $response = $this->actingAs($this->user)
            ->postJson(route('workflows.send', ['id' => $this->workflow->id]), [
                'body' => 'Direct manual response to escalation.',
            ]);

        $response->assertStatus(200);

        $this->workflow->refresh();
        $this->assertSame(WorkflowStatus::Sent, $this->workflow->status);

        $this->assertDatabaseHas('audit_logs', [
            'workflow_id' => $this->workflow->id,
            'event' => 'email_sent',
            'metadata->recipient' => 'prospect@example.com',
            'metadata->subject' => 'Re: Revreply Inquiry',
            'metadata->mode' => 'manual_resolution',
        ]);
    }

    public function test_workflow_reject(): void
    {
        $this->mockGmailClient([
            new Response(200, [], '{}'), // delete draft
        ]);

        $response = $this->actingAs($this->user)
            ->postJson(route('workflows.reject', ['id' => $this->workflow->id]));

        $response->assertStatus(200);

        $this->workflow->refresh();
        $this->assertSame(WorkflowStatus::Ignored, $this->workflow->status);

        $this->assertDatabaseHas('audit_logs', [
            'workflow_id' => $this->workflow->id,
            'event' => 'workflow_rejected',
        ]);
    }
}
