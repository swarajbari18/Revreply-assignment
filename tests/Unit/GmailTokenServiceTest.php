<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\ConnectedAccountStatus;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Services\Gmail\GmailTokenService;
use Google\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class GmailTokenServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_cached_token_without_hitting_google(): void
    {
        $account = ConnectedAccount::factory()->create([
            'token_expires_at' => now()->addMinutes(55),
        ]);

        Cache::put("gmail_token_{$account->id}", 'cached-token', 3000);

        $mockClient = Mockery::mock(Client::class);
        $mockClient->shouldNotReceive('fetchAccessTokenWithRefreshToken');

        $service = new GmailTokenService($mockClient);
        $token   = $service->getValidAccessToken($account);

        $this->assertSame('cached-token', $token);
    }

    public function test_returns_db_token_when_still_valid_and_cache_empty(): void
    {
        $account = ConnectedAccount::factory()->create([
            'access_token'     => 'db-access-token',
            'token_expires_at' => now()->addMinutes(30),
        ]);

        $mockClient = Mockery::mock(Client::class);
        $mockClient->shouldNotReceive('fetchAccessTokenWithRefreshToken');

        $service = new GmailTokenService($mockClient);
        $token   = $service->getValidAccessToken($account);

        $this->assertSame('db-access-token', $token);
    }

    public function test_refreshes_token_when_expired(): void
    {
        $account = ConnectedAccount::factory()->create([
            'access_token'     => 'old-token',
            'refresh_token'    => 'valid-refresh-token',
            'token_expires_at' => now()->subMinutes(10),
        ]);

        $mockClient = Mockery::mock(Client::class);
        $mockClient->shouldReceive('setAccessToken')->once();
        $mockClient->shouldReceive('fetchAccessTokenWithRefreshToken')
            ->with('valid-refresh-token')
            ->once()
            ->andReturn([
                'access_token' => 'fresh-token',
                'expires_in'   => 3600,
            ]);

        $service = new GmailTokenService($mockClient);
        $token   = $service->getValidAccessToken($account);

        $this->assertSame('fresh-token', $token);

        $account->refresh();
        $this->assertSame('fresh-token', $account->access_token);
    }

    public function test_marks_account_disconnected_on_invalid_grant(): void
    {
        $account = ConnectedAccount::factory()->create([
            'access_token'     => 'old-token',
            'refresh_token'    => 'revoked-refresh-token',
            'token_expires_at' => now()->subMinutes(10),
        ]);

        $mockClient = Mockery::mock(Client::class);
        $mockClient->shouldReceive('setAccessToken')->once();
        $mockClient->shouldReceive('fetchAccessTokenWithRefreshToken')
            ->once()
            ->andReturn([
                'error'             => 'invalid_grant',
                'error_description' => 'Token has been expired or revoked.',
            ]);

        $service = new GmailTokenService($mockClient);

        $this->expectException(\RuntimeException::class);

        try {
            $service->getValidAccessToken($account);
        } finally {
            $account->refresh();
            $this->assertSame(ConnectedAccountStatus::Disconnected, $account->status);
            $this->assertNull($account->access_token);
            $this->assertNull($account->refresh_token);
        }
    }

    public function test_does_not_disconnect_on_transient_error(): void
    {
        $account = ConnectedAccount::factory()->create([
            'access_token'     => 'old-token',
            'refresh_token'    => 'refresh-token',
            'token_expires_at' => now()->subMinutes(10),
        ]);

        $mockClient = Mockery::mock(Client::class);
        $mockClient->shouldReceive('setAccessToken')->once();
        $mockClient->shouldReceive('fetchAccessTokenWithRefreshToken')
            ->once()
            ->andReturn([
                'error' => 'temporarily_unavailable',
            ]);

        $service = new GmailTokenService($mockClient);

        $this->expectException(\RuntimeException::class);

        try {
            $service->getValidAccessToken($account);
        } finally {
            $account->refresh();
            $this->assertSame(ConnectedAccountStatus::Connected, $account->status);
        }
    }

    public function test_marks_disconnected_when_no_refresh_token(): void
    {
        $account = ConnectedAccount::factory()->create([
            'access_token'     => null,
            'refresh_token'    => null,
            'token_expires_at' => now()->subHour(),
        ]);

        $mockClient = Mockery::mock(Client::class);

        $service = new GmailTokenService($mockClient);

        $this->expectException(\RuntimeException::class);

        try {
            $service->getValidAccessToken($account);
        } finally {
            $account->refresh();
            $this->assertSame(ConnectedAccountStatus::Disconnected, $account->status);
        }
    }

    public function test_forget_cached_token_removes_from_cache(): void
    {
        $account = ConnectedAccount::factory()->create();

        Cache::put("gmail_token_{$account->id}", 'some-token', 3000);

        $service = new GmailTokenService(Mockery::mock(Client::class));
        $service->forgetCachedToken($account);

        $this->assertNull(Cache::get("gmail_token_{$account->id}"));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
