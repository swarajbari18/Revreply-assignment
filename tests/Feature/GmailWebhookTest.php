<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ConnectedAccountStatus;
use App\Enums\WorkflowStatus;
use App\Jobs\ProcessEmailWorkflowJob;
use App\Models\AuditLog;
use App\Models\ConnectedAccount;
use App\Models\ProcessedNotification;
use App\Models\Workflow;
use App\Services\Gmail\GmailTokenService;
use Google\Client;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class GmailWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_happy_path_valid_new_notification(): void
    {
        $account = ConnectedAccount::factory()->create([
            'gmail_email' => 'test@gmail.com',
            'status' => ConnectedAccountStatus::Connected,
            'last_history_id' => '100',
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
                    'id' => 'msg123',
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
            new GuzzleResponse(200, [], $historyResponse),
            new GuzzleResponse(200, [], $threadResponse),
        ]);

        $payload = [
            'message' => [
                'data' => base64_encode(json_encode([
                    'emailAddress' => 'test@gmail.com',
                    'historyId' => '101',
                ])),
            ],
        ];

        $response = $this->postJson('/gmail/webhook', $payload);

        $response->assertOk();

        $this->assertDatabaseHas('workflows', [
            'connected_account_id' => $account->id,
            'status' => WorkflowStatus::ContextBuilt->value,
        ]);

        $workflow = Workflow::first();

        $this->assertDatabaseHas('processed_notifications', [
            'idempotency_key' => 'test@gmail.com|101',
            'connected_account_id' => $account->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'workflow_id' => $workflow->id,
            'event' => 'notification_received',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'workflow_id' => $workflow->id,
            'event' => 'workflow_picked_up',
        ]);
    }

    public function test_duplicate_notification_is_ignored(): void
    {
        $account = ConnectedAccount::factory()->create([
            'gmail_email' => 'test@gmail.com',
            'status' => ConnectedAccountStatus::Connected,
        ]);

        ProcessedNotification::create([
            'idempotency_key' => 'test@gmail.com|101',
            'connected_account_id' => $account->id,
            'processed_at' => now(),
        ]);

        $payload = [
            'message' => [
                'data' => base64_encode(json_encode([
                    'emailAddress' => 'test@gmail.com',
                    'historyId' => '101',
                ])),
            ],
        ];

        $response = $this->postJson('/gmail/webhook', $payload);

        $response->assertOk();

        $this->assertDatabaseEmpty('workflows');
    }

    public function test_notification_for_unknown_gmail_address_is_ignored(): void
    {
        $payload = [
            'message' => [
                'data' => base64_encode(json_encode([
                    'emailAddress' => 'unknown@gmail.com',
                    'historyId' => '101',
                ])),
            ],
        ];

        $response = $this->postJson('/gmail/webhook', $payload);

        $response->assertOk();

        $this->assertDatabaseEmpty('workflows');
    }

    public function test_notification_for_disconnected_account_is_ignored(): void
    {
        $account = ConnectedAccount::factory()->create([
            'gmail_email' => 'test@gmail.com',
            'status' => ConnectedAccountStatus::Disconnected,
        ]);

        $payload = [
            'message' => [
                'data' => base64_encode(json_encode([
                    'emailAddress' => 'test@gmail.com',
                    'historyId' => '101',
                ])),
            ],
        ];

        $response = $this->postJson('/gmail/webhook', $payload);

        $response->assertOk();

        $this->assertDatabaseEmpty('workflows');
    }

    public function test_malformed_payload_missing_message_key(): void
    {
        $response = $this->postJson('/gmail/webhook', ['foo' => 'bar']);

        $response->assertOk();
        $this->assertDatabaseEmpty('workflows');
    }

    public function test_malformed_payload_invalid_base64(): void
    {
        $payload = [
            'message' => [
                'data' => 'invalid-base64-content!',
            ],
        ];

        $response = $this->postJson('/gmail/webhook', $payload);

        $response->assertOk();
        $this->assertDatabaseEmpty('workflows');
    }

    public function test_malformed_payload_missing_email_address(): void
    {
        $payload = [
            'message' => [
                'data' => base64_encode(json_encode([
                    'historyId' => '101',
                ])),
            ],
        ];

        $response = $this->postJson('/gmail/webhook', $payload);

        $response->assertOk();
        $this->assertDatabaseEmpty('workflows');
    }

    public function test_gap_detection_triggers_inline_recovery(): void
    {
        $account = ConnectedAccount::factory()->create([
            'gmail_email' => 'test@gmail.com',
            'status' => ConnectedAccountStatus::Connected,
            'last_history_id' => '100',
        ]);

        // Provide an OAuth token for HTTP request
        $account->update(['access_token' => 'fake-access-token']);

        $historyCombined = json_encode([
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
                [
                    'id' => '102',
                    'messagesAdded' => [
                        [
                            'message' => [
                                'id' => 'msg456',
                                'threadId' => 'thread456',
                            ],
                        ],
                    ],
                ],
            ],
            'historyId' => '102',
        ]);

        $thread1 = json_encode([
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
                            'data' => base64_encode('Hello 1!'),
                        ],
                    ],
                ],
            ],
        ]);

        $thread2 = json_encode([
            'id' => 'thread456',
            'messages' => [
                [
                    'id' => 'msg456',
                    'internalDate' => (string) (now()->getTimestamp() * 1000),
                    'payload' => [
                        'headers' => [
                            ['name' => 'From', 'value' => 'sender@example.com'],
                            ['name' => 'To', 'value' => 'test@gmail.com'],
                        ],
                        'mimeType' => 'text/plain',
                        'body' => [
                            'data' => base64_encode('Hello 2!'),
                        ],
                    ],
                ],
            ],
        ]);

        $this->mockGmailClient([
            new GuzzleResponse(200, [], $historyCombined),
            new GuzzleResponse(200, [], $thread1),
            new GuzzleResponse(200, [], $thread2),
        ]);

        $payload = [
            'message' => [
                'data' => base64_encode(json_encode([
                    'emailAddress' => 'test@gmail.com',
                    'historyId' => '102',
                ])),
            ],
        ];

        $response = $this->postJson('/gmail/webhook', $payload);

        $response->assertOk();

        $this->assertDatabaseHas('workflows', [
            'connected_account_id' => $account->id,
            'thread_id' => 'thread123',
            'latest_message_id' => 'msg123',
            'status' => WorkflowStatus::ContextBuilt->value,
        ]);

        $this->assertDatabaseHas('workflows', [
            'connected_account_id' => $account->id,
            'thread_id' => 'thread456',
            'latest_message_id' => 'msg456',
            'status' => WorkflowStatus::ContextBuilt->value,
        ]);

        $this->assertDatabaseHas('processed_notifications', [
            'idempotency_key' => 'test@gmail.com|101',
        ]);

        $this->assertDatabaseHas('processed_notifications', [
            'idempotency_key' => 'test@gmail.com|102',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'workflow_picked_up',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
