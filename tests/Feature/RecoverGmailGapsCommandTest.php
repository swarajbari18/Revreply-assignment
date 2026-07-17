<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ConnectedAccountStatus;
use App\Jobs\RenewGmailWatchJob;
use App\Jobs\ProcessEmailWorkflowJob;
use App\Models\ConnectedAccount;
use App\Models\ProcessedNotification;
use App\Services\Gmail\GmailTokenService;
use Google\Client;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class RecoverGmailGapsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_command_processes_gaps_for_expired_watches(): void
    {
        $account = ConnectedAccount::factory()->create([
            'gmail_email' => 'expired@gmail.com',
            'status' => ConnectedAccountStatus::Connected,
            'watch_expiration' => now()->subDay(),
            'last_history_id' => '100',
        ]);

        $mockTokenService = Mockery::mock(GmailTokenService::class);
        $mockTokenService->shouldReceive('getValidAccessToken')
            ->once()
            ->with(Mockery::on(fn ($acc) => $acc->id === $account->id))
            ->andReturn('fake-access-token');

        $this->app->instance(GmailTokenService::class, $mockTokenService);

        $guzzleMock = new GuzzleClient([
            'handler' => HandlerStack::create(
                new MockHandler([
                    new GuzzleResponse(200, [], json_encode([
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
                    ])),
                ])
            ),
        ]);

        $client = $this->app->make(Client::class);
        $client->setHttpClient($guzzleMock);

        $this->artisan('gaps:recover')->assertSuccessful();

        $this->assertDatabaseHas('workflows', [
            'connected_account_id' => $account->id,
            'thread_id' => 'thread123',
            'latest_message_id' => 'msg123',
        ]);

        $this->assertDatabaseHas('processed_notifications', [
            'idempotency_key' => 'expired@gmail.com|101',
        ]);

        Queue::assertPushed(ProcessEmailWorkflowJob::class, 1);
        Queue::assertPushed(RenewGmailWatchJob::class, function ($job) use ($account) {
            return $job->account->id === $account->id;
        });
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

        Queue::assertNotPushed(ProcessEmailWorkflowJob::class);
        Queue::assertNotPushed(RenewGmailWatchJob::class);
    }

    public function test_command_handles_stale_history_exception(): void
    {
        $account = ConnectedAccount::factory()->create([
            'gmail_email' => 'stale@gmail.com',
            'status' => ConnectedAccountStatus::Connected,
            'watch_expiration' => now()->subDay(),
            'last_history_id' => '100',
        ]);

        $mockTokenService = Mockery::mock(GmailTokenService::class);
        $mockTokenService->shouldReceive('getValidAccessToken')
            ->once()
            ->andReturn('fake-access-token');

        $this->app->instance(GmailTokenService::class, $mockTokenService);

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
                ])
            ),
        ]);

        $client = $this->app->make(Client::class);
        $client->setHttpClient($guzzleMock);

        $this->artisan('gaps:recover')->assertSuccessful();

        $account->refresh();
        $this->assertSame('200', $account->last_history_id);

        Queue::assertPushed(RenewGmailWatchJob::class, function ($job) use ($account) {
            return $job->account->id === $account->id;
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
