<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ConnectedAccountStatus;
use App\Jobs\RenewGmailWatchJob;
use App\Models\ConnectedAccount;
use App\Services\Gmail\GmailWatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class RenewGmailWatchesTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_dispatches_jobs_for_expiring_or_missing_watches(): void
    {
        Queue::fake();

        $expiring = ConnectedAccount::factory()->create([
            'status' => ConnectedAccountStatus::Connected,
            'watch_expiration' => now()->addHours(10),
        ]);

        $missing = ConnectedAccount::factory()->create([
            'status' => ConnectedAccountStatus::Connected,
            'watch_expiration' => null,
        ]);

        $fresh = ConnectedAccount::factory()->create([
            'status' => ConnectedAccountStatus::Connected,
            'watch_expiration' => now()->addDays(5),
        ]);

        $disconnected = ConnectedAccount::factory()->create([
            'status' => ConnectedAccountStatus::Disconnected,
            'watch_expiration' => now()->addHours(10),
        ]);

        $this->artisan('watches:renew')->assertSuccessful();

        Queue::assertPushed(RenewGmailWatchJob::class, function ($job) use ($expiring) {
            return $job->account->id === $expiring->id;
        });

        Queue::assertPushed(RenewGmailWatchJob::class, function ($job) use ($missing) {
            return $job->account->id === $missing->id;
        });

        Queue::assertNotPushed(RenewGmailWatchJob::class, function ($job) use ($fresh) {
            return $job->account->id === $fresh->id;
        });

        Queue::assertNotPushed(RenewGmailWatchJob::class, function ($job) use ($disconnected) {
            return $job->account->id === $disconnected->id;
        });
    }

    public function test_job_invokes_watch_service(): void
    {
        $account = ConnectedAccount::factory()->create([
            'status' => ConnectedAccountStatus::Connected,
        ]);

        $mockWatchService = Mockery::mock(GmailWatchService::class);
        $mockWatchService->shouldReceive('watch')
            ->once()
            ->with(Mockery::on(fn ($acc) => $acc->id === $account->id));

        $job = new RenewGmailWatchJob($account);
        $job->handle($mockWatchService);

        $this->assertTrue(true);
    }

    public function test_job_fails_and_does_not_retry_on_permanent_errors(): void
    {
        $account = ConnectedAccount::factory()->create([
            'status' => ConnectedAccountStatus::Connected,
        ]);

        $mockWatchService = Mockery::mock(GmailWatchService::class);
        $mockWatchService->shouldReceive('watch')
            ->once()
            ->andThrow(new \RuntimeException("Refresh token permanently revoked for account {$account->id}."));

        $job = new RenewGmailWatchJob($account);
        $job->withFakeQueueInteractions();
        $job->handle($mockWatchService);

        $job->assertFailed();
    }

    public function test_job_rethrows_transient_exceptions(): void
    {
        $account = ConnectedAccount::factory()->create([
            'status' => ConnectedAccountStatus::Connected,
        ]);

        $mockWatchService = Mockery::mock(GmailWatchService::class);
        $mockWatchService->shouldReceive('watch')
            ->once()
            ->andThrow(new \RuntimeException("Transient rate limit error"));

        $job = new RenewGmailWatchJob($account);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Transient rate limit error");

        $job->handle($mockWatchService);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
