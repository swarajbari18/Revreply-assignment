<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\ConnectedAccountStatus;
use App\Models\ConnectedAccount;
use App\Services\GmailIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class GmailIngestionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_ignores_envelope_missing_message_key(): void
    {
        Log::shouldReceive('warning')->once();

        $service = $this->app->make(GmailIngestionService::class);

        $service->ingest(['foo' => 'bar']);

        $this->assertDatabaseEmpty('workflows');
    }

    public function test_ignores_envelope_with_invalid_base64(): void
    {
        Log::shouldReceive('warning')->once();

        $service = $this->app->make(GmailIngestionService::class);

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

        $service = $this->app->make(GmailIngestionService::class);

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

        $service = $this->app->make(GmailIngestionService::class);

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

        $service = $this->app->make(GmailIngestionService::class);

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
}
