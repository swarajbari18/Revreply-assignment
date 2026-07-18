<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ConnectedAccountStatus;
use App\Models\ConnectedAccount;
use Google\Client;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class RecoverGmailGapsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_command_processes_gaps_for_expired_watches(): void
    {
        $account = ConnectedAccount::factory()->create([
            'gmail_email' => 'expired@gmail.com',
            'status' => ConnectedAccountStatus::Connected,
            'watch_expiration' => now()->subDay(),
            'last_history_id' => '100',
            'access_token' => 'fake-access-token',
            'token_expires_at' => now()->addHour(),
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
                            ['name' => 'To', 'value' => 'expired@gmail.com'],
                        ],
                        'mimeType' => 'text/plain',
                        'body' => [
                            'data' => base64_encode('Hello!'),
                        ],
                    ],
                ],
            ],
        ]);

        $watchResponse = json_encode([
            'historyId' => '102',
            'expiration' => (string) (now()->addDays(7)->getTimestamp() * 1000),
        ]);

        $this->mockGmailClient([
            new GuzzleResponse(200, [], $historyResponse),
            new GuzzleResponse(200, [], $threadResponse),
            new GuzzleResponse(200, [], $watchResponse),
        ]);

        $this->artisan('gaps:recover')->assertSuccessful();

        $this->assertDatabaseHas('workflows', [
            'connected_account_id' => $account->id,
            'thread_id' => 'thread123',
            'latest_message_id' => 'msg123',
        ]);

        $this->assertDatabaseHas('processed_notifications', [
            'idempotency_key' => 'expired@gmail.com|101',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'workflow_picked_up',
        ]);

        $account->refresh();
        $this->assertTrue($account->watch_expiration->isFuture());
    }

    public function test_command_skips_valid_watches(): void
    {
        $account = ConnectedAccount::factory()->create([
            'gmail_email' => 'valid@gmail.com',
            'status' => ConnectedAccountStatus::Connected,
            'watch_expiration' => now()->addDays(5),
            'last_history_id' => '100',
        ]);

        $this->artisan('gaps:recover')->assertSuccessful();

        $this->assertDatabaseEmpty('workflows');
    }

    public function test_command_handles_stale_history_exception(): void
    {
        $account = ConnectedAccount::factory()->create([
            'gmail_email' => 'stale@gmail.com',
            'status' => ConnectedAccountStatus::Connected,
            'watch_expiration' => now()->subDay(),
            'last_history_id' => '100',
            'access_token' => 'fake-access-token',
            'token_expires_at' => now()->addHour(),
        ]);

        $guzzleMock = new GuzzleClient([
            'handler' => HandlerStack::create(
                new MockHandler([
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
                        'expiration' => now()->addDays(7)->getTimestamp() * 1000,
                    ])),
                    new GuzzleResponse(200, [], json_encode([
                        'historyId' => '202',
                        'expiration' => now()->addDays(7)->getTimestamp() * 1000,
                    ])),
                ])
            ),
        ]);

        $client = $this->app->make(Client::class);
        $client->setHttpClient($guzzleMock);

        $this->artisan('gaps:recover')->assertSuccessful();

        $account->refresh();
        $this->assertSame('200', $account->last_history_id);

        $this->assertTrue($account->watch_expiration->isFuture());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
