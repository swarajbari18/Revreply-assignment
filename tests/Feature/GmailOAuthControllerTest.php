<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ConnectedAccountStatus;
use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GmailOAuthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_callback_with_error_param_redirects_to_frontend_with_error(): void
    {
        $response = $this->get('/auth/gmail/callback?error=access_denied');

        $response->assertRedirectContains('error=oauth_denied');
    }

    public function test_callback_with_missing_code_redirects_with_error(): void
    {
        $response = $this->get('/auth/gmail/callback?state=somestate');

        $response->assertRedirectContains('error=missing_params');
    }

    public function test_callback_with_missing_state_redirects_with_error(): void
    {
        $response = $this->get('/auth/gmail/callback?code=somecode');

        $response->assertRedirectContains('error=missing_params');
    }

    public function test_index_returns_accounts_for_user(): void
    {
        $user = User::factory()->create();

        ConnectedAccount::factory()->count(2)->create(['user_id' => $user->id]);
        ConnectedAccount::factory()->create();

        $response = $this->getJson("/api/accounts?user_id={$user->id}");

        $response->assertOk();
        $response->assertJsonCount(2);
    }

    public function test_index_does_not_leak_tokens_in_response(): void
    {
        $user = User::factory()->create();
        $account = ConnectedAccount::factory()->create(['user_id' => $user->id]);

        $response = $this->getJson("/api/accounts?user_id={$user->id}");

        $response->assertOk();
        $response->assertJsonMissingPath('0.access_token');
        $response->assertJsonMissingPath('0.refresh_token');
    }

    public function test_destroy_disconnects_account(): void
    {
        $account = ConnectedAccount::factory()->create([
            'status' => ConnectedAccountStatus::Connected,
            'access_token' => 'live-token',
            'refresh_token' => 'live-refresh',
        ]);

        $response = $this->deleteJson("/api/accounts/{$account->id}");

        $response->assertOk();
        $response->assertJson(['message' => 'Account disconnected successfully']);

        $account->refresh();
        $this->assertSame(ConnectedAccountStatus::Disconnected, $account->status);
        $this->assertNull($account->access_token);
        $this->assertNull($account->refresh_token);
    }

    public function test_destroy_returns_404_for_nonexistent_account(): void
    {
        $response = $this->deleteJson('/api/accounts/99999');

        $response->assertNotFound();
    }
}
