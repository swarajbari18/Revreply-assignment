<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ConnectedAccountStatus;
use App\Enums\WorkflowStatus;
use App\Jobs\ProcessEmailWorkflowJob;
use App\Models\AuditLog;
use App\Models\ConnectedAccount;
use App\Models\Workflow;
use Google\Client;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ContextBuilderServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test Case 1 — Happy path: standard push notification (thread_id is NULL)
     */
    public function test_happy_path_standard_push(): void
    {
        $account = ConnectedAccount::factory()->create([
            'gmail_email' => 'test@gmail.com',
            'status' => ConnectedAccountStatus::Connected,
            'last_history_id' => '100',
            'access_token' => 'fake-token',
            'token_expires_at' => now()->addHour(),
        ]);

        $workflow = Workflow::create([
            'connected_account_id' => $account->id,
            'thread_id' => null,
            'latest_message_id' => null,
            'status' => WorkflowStatus::Queued,
            'correlation_id' => 'corr-123',
            'started_at' => now(),
        ]);

        $historyResponse = json_encode([
            'history' => [
                [
                    'id' => '101',
                    'messagesAdded' => [
                        [
                            'message' => [
                                'id' => 'msg123',
                                'threadId' => 'thread123',
                            ],
                        ],
                    ],
                ],
            ],
            'historyId' => '101',
        ]);

        $threadResponse = json_encode([
            'id' => 'thread123',
            'messages' => [
                [
                    'id' => 'msg122',
                    'internalDate' => (string) (now()->subMinutes(10)->getTimestamp() * 1000),
                    'payload' => [
                        'headers' => [
                            ['name' => 'From', 'value' => 'sender@example.com'],
                            ['name' => 'To', 'value' => 'test@gmail.com'],
                            ['name' => 'Subject', 'value' => 'Testing context builder'],
                            ['name' => 'Date', 'value' => now()->subMinutes(10)->toRfc2822String()],
                        ],
                        'mimeType' => 'text/plain',
                        'body' => [
                            'data' => base64_encode('Hello, this is the first message.'),
                        ],
                    ],
                ],
                [
                    'id' => 'msg123',
                    'internalDate' => (string) (now()->getTimestamp() * 1000),
                    'payload' => [
                        'headers' => [
                            ['name' => 'From', 'value' => 'test@gmail.com'],
                            ['name' => 'To', 'value' => 'sender@example.com'],
                            ['name' => 'Subject', 'value' => 'Re: Testing context builder'],
                            ['name' => 'Date', 'value' => now()->toRfc2822String()],
                        ],
                        'mimeType' => 'text/plain',
                        'body' => [
                            'data' => base64_encode('And this is the reply.'),
                        ],
                    ],
                ],
            ],
        ]);

        $this->mockGmailClient([
            new GuzzleResponse(200, [], $historyResponse),
            new GuzzleResponse(200, [], $threadResponse),
        ]);

        ProcessEmailWorkflowJob::dispatch($workflow->id, $account->id);

        $workflow->refresh();
        $this->assertSame(WorkflowStatus::ContextBuilt, $workflow->status);
        $this->assertSame('thread123', $workflow->thread_id);
        $this->assertSame('msg123', $workflow->latest_message_id);

        $this->assertDatabaseHas('audit_logs', [
            'workflow_id' => $workflow->id,
            'event' => 'workflow_processing_started',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'workflow_id' => $workflow->id,
            'event' => 'context_built',
        ]);

        // Assert Redis lock is released
        $lock = Cache::lock("workflow_lock:{$workflow->id}", 300);
        $this->assertTrue($lock->get(), 'Lock was not released');
        $lock->release();
    }

    /**
     * Test Case 2 — Happy path: gap recovery (thread_id is already populated)
     */
    public function test_happy_path_gap_recovery(): void
    {
        $account = ConnectedAccount::factory()->create([
            'gmail_email' => 'test@gmail.com',
            'status' => ConnectedAccountStatus::Connected,
            'access_token' => 'fake-token',
            'token_expires_at' => now()->addHour(),
        ]);

        $workflow = Workflow::create([
            'connected_account_id' => $account->id,
            'thread_id' => 'thread123',
            'latest_message_id' => 'msg123',
            'status' => WorkflowStatus::Queued,
            'correlation_id' => 'corr-123',
            'started_at' => now(),
        ]);

        $threadResponse = json_encode([
            'id' => 'thread123',
            'messages' => [
                [
                    'id' => 'msg123',
                    'internalDate' => (string) (now()->getTimestamp() * 1000),
                    'payload' => [
                        'headers' => [
                            ['name' => 'From', 'value' => 'sender@example.com'],
                            ['name' => 'To', 'value' => 'test@gmail.com'],
                            ['name' => 'Subject', 'value' => 'Testing gap recovery'],
                        ],
                        'mimeType' => 'text/plain',
                        'body' => [
                            'data' => base64_encode('Hello from gap recovery.'),
                        ],
                    ],
                ],
            ],
        ]);

        // Only thread fetch is mocked; history is skipped
        $this->mockGmailClient([
            new GuzzleResponse(200, [], $threadResponse),
        ]);

        ProcessEmailWorkflowJob::dispatch($workflow->id, $account->id);

        $workflow->refresh();
        $this->assertSame(WorkflowStatus::ContextBuilt, $workflow->status);
        $this->assertSame('thread123', $workflow->thread_id);
    }

    /**
     * Test Case 3 — Thread with attachments
     */
    public function test_thread_with_attachments(): void
    {
        $account = ConnectedAccount::factory()->create([
            'gmail_email' => 'test@gmail.com',
            'status' => ConnectedAccountStatus::Connected,
            'access_token' => 'fake-token',
            'token_expires_at' => now()->addHour(),
        ]);

        $workflow = Workflow::create([
            'connected_account_id' => $account->id,
            'thread_id' => 'thread123',
            'latest_message_id' => 'msg123',
            'status' => WorkflowStatus::Queued,
            'correlation_id' => 'corr-123',
            'started_at' => now(),
        ]);

        $threadResponse = json_encode([
            'id' => 'thread123',
            'messages' => [
                [
                    'id' => 'msg123',
                    'internalDate' => (string) (now()->getTimestamp() * 1000),
                    'payload' => [
                        'headers' => [
                            ['name' => 'From', 'value' => 'sender@example.com'],
                            ['name' => 'To', 'value' => 'test@gmail.com'],
                        ],
                        'mimeType' => 'multipart/mixed',
                        'parts' => [
                            [
                                'mimeType' => 'text/plain',
                                'body' => [
                                    'data' => base64_encode('Hello, please see attachment.'),
                                ],
                            ],
                            [
                                'mimeType' => 'text/plain',
                                'filename' => 'sample.txt',
                                'body' => [
                                    'attachmentId' => 'att123',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $attachmentResponse = json_encode([
            'size' => 20,
            'data' => base64_encode('This is the attachment text'),
        ]);

        $this->mockGmailClient([
            new GuzzleResponse(200, [], $threadResponse),
            new GuzzleResponse(200, [], $attachmentResponse),
        ]);

        ProcessEmailWorkflowJob::dispatch($workflow->id, $account->id);

        $workflow->refresh();
        $this->assertSame(WorkflowStatus::ContextBuilt, $workflow->status);

        $auditLog = AuditLog::where('workflow_id', $workflow->id)
            ->where('event', 'context_built')
            ->first();
        $this->assertNotNull($auditLog);
        $this->assertSame(1, $auditLog->metadata['attachment_count']);
    }

    /**
     * Test Case 4 — One attachment fails to download, workflow still completes
     */
    public function test_attachment_download_fails(): void
    {
        $account = ConnectedAccount::factory()->create([
            'gmail_email' => 'test@gmail.com',
            'status' => ConnectedAccountStatus::Connected,
            'access_token' => 'fake-token',
            'token_expires_at' => now()->addHour(),
        ]);

        $workflow = Workflow::create([
            'connected_account_id' => $account->id,
            'thread_id' => 'thread123',
            'latest_message_id' => 'msg123',
            'status' => WorkflowStatus::Queued,
            'correlation_id' => 'corr-123',
            'started_at' => now(),
        ]);

        $threadResponse = json_encode([
            'id' => 'thread123',
            'messages' => [
                [
                    'id' => 'msg123',
                    'internalDate' => (string) (now()->getTimestamp() * 1000),
                    'payload' => [
                        'headers' => [
                            ['name' => 'From', 'value' => 'sender@example.com'],
                            ['name' => 'To', 'value' => 'test@gmail.com'],
                        ],
                        'mimeType' => 'multipart/mixed',
                        'parts' => [
                            [
                                'mimeType' => 'text/plain',
                                'body' => [
                                    'data' => base64_encode('Body text'),
                                ],
                            ],
                            [
                                'mimeType' => 'text/plain',
                                'filename' => 'sample1.txt',
                                'body' => [
                                    'attachmentId' => 'att1',
                                ],
                            ],
                            [
                                'mimeType' => 'text/plain',
                                'filename' => 'sample2.txt',
                                'body' => [
                                    'attachmentId' => 'att2',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $attachment1Response = json_encode([
            'size' => 10,
            'data' => base64_encode('File 1'),
        ]);

        $this->mockGmailClient([
            new GuzzleResponse(200, [], $threadResponse),
            new GuzzleResponse(200, [], $attachment1Response),
            new GuzzleResponse(500, [], 'Internal Server Error'),
        ]);

        ProcessEmailWorkflowJob::dispatch($workflow->id, $account->id);

        $workflow->refresh();
        $this->assertSame(WorkflowStatus::ContextBuilt, $workflow->status);

        $auditLog = AuditLog::where('workflow_id', $workflow->id)
            ->where('event', 'context_built')
            ->first();
        $this->assertNotNull($auditLog);
        $this->assertSame(1, $auditLog->metadata['attachment_count']);
    }

    /**
     * Test Case 5 — Auto-reply loop detection
     */
    public function test_auto_reply_loop_detection(): void
    {
        $account = ConnectedAccount::factory()->create([
            'gmail_email' => 'test@gmail.com',
            'status' => ConnectedAccountStatus::Connected,
            'access_token' => 'fake-token',
            'token_expires_at' => now()->addHour(),
        ]);

        $workflow = Workflow::create([
            'connected_account_id' => $account->id,
            'thread_id' => 'thread123',
            'latest_message_id' => 'msg123',
            'status' => WorkflowStatus::Queued,
            'correlation_id' => 'corr-123',
            'started_at' => now(),
        ]);

        $threadResponse = json_encode([
            'id' => 'thread123',
            'messages' => [
                [
                    'id' => 'msg123',
                    'internalDate' => (string) (now()->getTimestamp() * 1000),
                    'payload' => [
                        'headers' => [
                            ['name' => 'From', 'value' => 'sender@example.com'],
                            ['name' => 'To', 'value' => 'test@gmail.com'],
                            ['name' => 'Auto-Submitted', 'value' => 'auto-replied'],
                        ],
                        'mimeType' => 'text/plain',
                        'body' => [
                            'data' => base64_encode('Hello, this is an auto-response.'),
                        ],
                    ],
                ],
            ],
        ]);

        $this->mockGmailClient([
            new GuzzleResponse(200, [], $threadResponse),
        ]);

        ProcessEmailWorkflowJob::dispatch($workflow->id, $account->id);

        $workflow->refresh();
        $this->assertSame(WorkflowStatus::Ignored, $workflow->status);

        $this->assertDatabaseHas('audit_logs', [
            'workflow_id' => $workflow->id,
            'event' => 'workflow_ignored_auto_submitted',
        ]);
    }

    /**
     * Test Case 6 — Gmail History API returns 401 (token expired mid-flight)
     */
    public function test_history_api_401_retry(): void
    {
        $account = ConnectedAccount::factory()->create([
            'gmail_email' => 'test@gmail.com',
            'status' => ConnectedAccountStatus::Connected,
            'last_history_id' => '100',
            'access_token' => 'old-expired-token',
            'token_expires_at' => now()->addHour(),
            'refresh_token' => 'valid-refresh-token',
        ]);

        $workflow = Workflow::create([
            'connected_account_id' => $account->id,
            'thread_id' => null,
            'latest_message_id' => null,
            'status' => WorkflowStatus::Queued,
            'correlation_id' => 'corr-123',
            'started_at' => now(),
        ]);

        // First run: History API returns 401 Unauthorized
        $this->mockGmailClient([
            new GuzzleResponse(401, [], json_encode([
                'error' => [
                    'code' => 401,
                    'message' => 'Invalid credentials',
                ],
            ])),
        ]);

        $thrown = false;
        try {
            ProcessEmailWorkflowJob::dispatch($workflow->id, $account->id);
        } catch (\Google\Service\Exception $e) {
            $this->assertSame(401, $e->getCode());
            $thrown = true;
        }
        $this->assertTrue($thrown);

        // Assert DB token expires at was set to past and cache was cleared
        $account->refresh();
        $this->assertTrue($account->token_expires_at->isPast());
        $this->assertNull(Cache::get("gmail_token_{$account->id}"));

        // Second run (retry):
        // 1. GmailTokenService fetches new access token (POST to oauth2 token endpoint)
        // 2. History API succeeds
        // 3. Threads API succeeds
        $historyResponse = json_encode([
            'history' => [
                [
                    'id' => '101',
                    'messagesAdded' => [
                        [
                            'message' => [
                                'id' => 'msg123',
                                'threadId' => 'thread123',
                            ],
                        ],
                    ],
                ],
            ],
            'historyId' => '101',
        ]);

        $threadResponse = json_encode([
            'id' => 'thread123',
            'messages' => [
                [
                    'id' => 'msg123',
                    'internalDate' => (string) (now()->getTimestamp() * 1000),
                    'payload' => [
                        'headers' => [
                            ['name' => 'From', 'value' => 'sender@example.com'],
                            ['name' => 'To', 'value' => 'test@gmail.com'],
                        ],
                        'mimeType' => 'text/plain',
                        'body' => [
                            'data' => base64_encode('Body text'),
                        ],
                    ],
                ],
            ],
        ]);

        $this->mockGmailClient([
            new GuzzleResponse(200, [], json_encode([
                'access_token' => 'fresh-access-token',
                'expires_in' => 3600,
            ])),
            new GuzzleResponse(200, [], $historyResponse),
            new GuzzleResponse(200, [], $threadResponse),
        ]);

        ProcessEmailWorkflowJob::dispatch($workflow->id, $account->id);

        $workflow->refresh();
        $this->assertSame(WorkflowStatus::ContextBuilt, $workflow->status);
        $this->assertSame('thread123', $workflow->thread_id);

        $account->refresh();
        $this->assertSame('fresh-access-token', $account->access_token);
    }

    /**
     * Test Case 7 — Refresh token permanently revoked
     */
    public function test_refresh_token_permanently_revoked(): void
    {
        $account = ConnectedAccount::factory()->create([
            'gmail_email' => 'test@gmail.com',
            'status' => ConnectedAccountStatus::Connected,
            'access_token' => 'old-expired-token',
            'token_expires_at' => now()->subHour(),
            'refresh_token' => 'revoked-refresh-token',
        ]);

        $workflow = Workflow::create([
            'connected_account_id' => $account->id,
            'thread_id' => null,
            'latest_message_id' => null,
            'status' => WorkflowStatus::Queued,
            'correlation_id' => 'corr-123',
            'started_at' => now(),
        ]);

        // Mock token refresh endpoint returning invalid_grant
        $this->mockGmailClient([
            new GuzzleResponse(200, [], json_encode([
                'error' => 'invalid_grant',
                'error_description' => 'Token has been expired or revoked.',
            ])),
        ]);

        ProcessEmailWorkflowJob::dispatch($workflow->id, $account->id);

        $workflow->refresh();
        $this->assertSame(WorkflowStatus::Failed, $workflow->status);

        $account->refresh();
        $this->assertSame(ConnectedAccountStatus::Disconnected, $account->status);
        $this->assertNull($account->access_token);
        $this->assertNull($account->refresh_token);

        $this->assertDatabaseHas('audit_logs', [
            'workflow_id' => $workflow->id,
            'event' => 'workflow_failed_auth_revoked',
        ]);
    }

    /**
     * Test Case 8 — Gmail History API returns 404 (history too old)
     */
    public function test_history_api_404_history_too_old(): void
    {
        $account = ConnectedAccount::factory()->create([
            'gmail_email' => 'test@gmail.com',
            'status' => ConnectedAccountStatus::Connected,
            'last_history_id' => '100',
            'access_token' => 'fake-token',
            'token_expires_at' => now()->addHour(),
        ]);

        $workflow = Workflow::create([
            'connected_account_id' => $account->id,
            'thread_id' => null,
            'latest_message_id' => null,
            'status' => WorkflowStatus::Queued,
            'correlation_id' => 'corr-123',
            'started_at' => now(),
        ]);

        // Mock:
        // 1. History API -> 404
        // 2. Profile API -> 200 with new history ID '200'
        // 3. Watch API -> 200 with new expiration and historyId
        $this->mockGmailClient([
            new GuzzleResponse(404, [], json_encode([
                'error' => [
                    'code' => 404,
                    'message' => 'History too stale.',
                ],
            ])),
            new GuzzleResponse(200, [], json_encode([
                'historyId' => '200',
            ])),
            new GuzzleResponse(200, [], json_encode([
                'historyId' => '201',
                'expiration' => (string) (now()->addDays(7)->getTimestamp() * 1000),
            ])),
        ]);

        ProcessEmailWorkflowJob::dispatch($workflow->id, $account->id);

        $workflow->refresh();
        $this->assertSame(WorkflowStatus::Failed, $workflow->status);

        $account->refresh();
        $this->assertSame('200', $account->last_history_id);
        $this->assertTrue($account->watch_expiration->isFuture());

        $this->assertDatabaseHas('audit_logs', [
            'workflow_id' => $workflow->id,
            'event' => 'workflow_failed_history_stale',
        ]);
    }

    /**
     * Test Case 9 — Concurrent lock contention
     */
    public function test_concurrent_lock_contention(): void
    {
        $account = ConnectedAccount::factory()->create([
            'gmail_email' => 'test@gmail.com',
            'status' => ConnectedAccountStatus::Connected,
            'access_token' => 'fake-token',
            'token_expires_at' => now()->addHour(),
        ]);

        $workflow = Workflow::create([
            'connected_account_id' => $account->id,
            'thread_id' => null,
            'latest_message_id' => null,
            'status' => WorkflowStatus::Queued,
            'correlation_id' => 'corr-123',
            'started_at' => now(),
        ]);

        // Manually acquire lock beforehand
        $lock = Cache::lock("workflow_lock:{$workflow->id}", 300);
        $this->assertTrue($lock->acquire());

        $this->mockGmailClient([]);

        ProcessEmailWorkflowJob::dispatch($workflow->id, $account->id);

        $workflow->refresh();
        $this->assertSame(WorkflowStatus::Queued, $workflow->status);

        $this->assertDatabaseMissing('audit_logs', [
            'workflow_id' => $workflow->id,
            'event' => 'workflow_processing_started',
        ]);

        $lock->release();
    }
}
