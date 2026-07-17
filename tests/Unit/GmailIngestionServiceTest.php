<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\ConnectedAccountStatus;
use App\Models\ConnectedAccount;
use App\Services\Gmail\GmailTokenService;
use App\Services\GmailIngestionService;
use Google\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class GmailIngestionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_ignores_envelope_missing_message_key(): void
    {
        Log::shouldReceive('warning')->once();

        $client = Mockery::mock(Client::class);
        $tokenService = Mockery::mock(GmailTokenService::class);
        $service = new GmailIngestionService($client, $tokenService);

        $service->ingest(['foo' => 'bar']);

        $this->assertDatabaseEmpty('workflows');
    }

    public function test_ignores_envelope_with_invalid_base64(): void
    {
        Log::shouldReceive('warning')->once();

        $client = Mockery::mock(Client::class);
        $tokenService = Mockery::mock(GmailTokenService::class);
        $service = new GmailIngestionService($client, $tokenService);

        $service->ingest([
            'message' => [
                'data' => '!!!invalid-base64!!!',
            ],
        ]);

        $this->assertDatabaseEmpty('workflows');
    }

    public function test_ignores_envelope_with_missing_inner_keys(): void
    {
        Log::shouldReceive('warning')->once();

        $client = Mockery::mock(Client::class);
        $tokenService = Mockery::mock(GmailTokenService::class);
        $service = new GmailIngestionService($client, $tokenService);

        $service->ingest([
            'message' => [
                'data' => base64_encode(json_encode([
                    'historyId' => '101',
                ])),
            ],
        ]);

        $this->assertDatabaseEmpty('workflows');
    }

    public function test_does_not_trigger_gap_recovery_when_history_is_contiguous(): void
    {
        $account = ConnectedAccount::factory()->create([
            'gmail_email' => 'contiguous@gmail.com',
            'status' => ConnectedAccountStatus::Connected,
            'last_history_id' => '100',
        ]);

        $client = Mockery::mock(Client::class);
        $tokenService = Mockery::mock(GmailTokenService::class);
        $tokenService->shouldNotReceive('getValidAccessToken');

        $service = new GmailIngestionService($client, $tokenService);

        $service->ingest([
            'message' => [
                'data' => base64_encode(json_encode([
                    'emailAddress' => 'contiguous@gmail.com',
                    'historyId' => '101',
                ])),
            ],
        ]);

        $this->assertDatabaseHas('workflows', [
            'connected_account_id' => $account->id,
        ]);
    }

    public function test_does_not_trigger_gap_recovery_when_last_history_id_is_null(): void
    {
        $account = ConnectedAccount::factory()->create([
            'gmail_email' => 'null_history@gmail.com',
            'status' => ConnectedAccountStatus::Connected,
            'last_history_id' => null,
        ]);

        $client = Mockery::mock(Client::class);
        $tokenService = Mockery::mock(GmailTokenService::class);
        $tokenService->shouldNotReceive('getValidAccessToken');

        $service = new GmailIngestionService($client, $tokenService);

        $service->ingest([
            'message' => [
                'data' => base64_encode(json_encode([
                    'emailAddress' => 'null_history@gmail.com',
                    'historyId' => '105',
                ])),
            ],
        ]);

        $this->assertDatabaseHas('workflows', [
            'connected_account_id' => $account->id,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
