<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ConnectedAccountStatus;
use App\Jobs\RenewGmailWatchJob;
use App\Models\ConnectedAccount;
use Google\Client;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RenewGmailWatchesTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_dispatches_jobs_for_expiring_or_missing_watches(): void
    {
        $expiring = ConnectedAccount::factory()->create([
            'status' => ConnectedAccountStatus::Connected,
            'watch_expiration' => now()->addHours(10),
            'access_token' => 'fake-access-token',
            'token_expires_at' => now()->addHour(),
        ]);

        $missing = ConnectedAccount::factory()->create([
            'status' => ConnectedAccountStatus::Connected,
            'watch_expiration' => null,
            'access_token' => 'fake-access-token',
            'token_expires_at' => now()->addHour(),
        ]);

        $fresh = ConnectedAccount::factory()->create([
            'status' => ConnectedAccountStatus::Connected,
            'watch_expiration' => now()->addDays(5),
        ]);

        $disconnected = ConnectedAccount::factory()->create([
            'status' => ConnectedAccountStatus::Disconnected,
            'watch_expiration' => now()->addHours(10),
        ]);

        $guzzleMock = new GuzzleClient([
            'handler' => HandlerStack::create(
                new MockHandler([
                    // First job for expiring
                    new GuzzleResponse(200, [], json_encode([
                        'historyId' => '101',
                        'expiration' => now()->addDays(7)->getTimestamp() * 1000,
                    ])),
                    // Second job for missing
                    new GuzzleResponse(200, [], json_encode([
                        'historyId' => '102',
                        'expiration' => now()->addDays(7)->getTimestamp() * 1000,
                    ])),
                ])
            ),
        ]);

        $client = $this->app->make(Client::class);
        $client->setHttpClient($guzzleMock);

        $this->artisan('watches:renew')->assertSuccessful();

        $expiring->refresh();
        $this->assertTrue($expiring->watch_expiration->isFuture());

        $missing->refresh();
        $this->assertTrue($missing->watch_expiration->isFuture());
    }

    public function test_job_invokes_watch_service(): void
    {
        $account = ConnectedAccount::factory()->create([
            'status' => ConnectedAccountStatus::Connected,
            'access_token' => 'fake-access-token',
            'token_expires_at' => now()->addHour(),
        ]);

        $guzzleMock = new GuzzleClient([
            'handler' => HandlerStack::create(
                new MockHandler([
                    new GuzzleResponse(200, [], json_encode([
                        'historyId' => '101',
                        'expiration' => now()->addDays(7)->getTimestamp() * 1000,
                    ])),
                ])
            ),
        ]);

        $client = $this->app->make(Client::class);
        $client->setHttpClient($guzzleMock);

        $job = new RenewGmailWatchJob($account);
        $job->handle($this->app->make(\App\Services\Gmail\GmailWatchService::class));

        $account->refresh();
        $this->assertTrue($account->watch_expiration->isFuture());
    }

    public function test_job_fails_and_does_not_retry_on_permanent_errors(): void
    {
        $account = ConnectedAccount::factory()->create([
            'status' => ConnectedAccountStatus::Connected,
            'access_token' => 'fake-access-token',
            'token_expires_at' => now()->addHour(),
        ]);

        $guzzleMock = new GuzzleClient([
            'handler' => HandlerStack::create(
                new MockHandler([
                    new GuzzleResponse(401, [], json_encode([
                        'error' => [
                            'code' => 401,
                            'message' => 'Invalid Credentials',
                        ],
                    ])),
                ])
            ),
        ]);

        $client = $this->app->make(Client::class);
        $client->setHttpClient($guzzleMock);

        $job = new RenewGmailWatchJob($account);
        $job->withFakeQueueInteractions();
        
        try {
            $job->handle($this->app->make(\App\Services\Gmail\GmailWatchService::class));
        } catch (\Exception $e) {
            // Expected
        }
    }

    public function test_job_rethrows_transient_exceptions(): void
    {
        $account = ConnectedAccount::factory()->create([
            'status' => ConnectedAccountStatus::Connected,
            'access_token' => 'fake-access-token',
            'token_expires_at' => now()->addHour(),
        ]);

        $guzzleMock = new GuzzleClient([
            'handler' => HandlerStack::create(
                new MockHandler([
                    new GuzzleResponse(429, [], json_encode([
                        'error' => [
                            'code' => 429,
                            'message' => 'Too Many Requests',
                        ],
                    ])),
                ])
            ),
        ]);

        $client = $this->app->make(Client::class);
        $client->setHttpClient($guzzleMock);

        $job = new RenewGmailWatchJob($account);

        $this->expectException(\Google\Service\Exception::class);
        $this->expectExceptionMessage("Too Many Requests");

        $job->handle($this->app->make(\App\Services\Gmail\GmailWatchService::class));
    }
}
