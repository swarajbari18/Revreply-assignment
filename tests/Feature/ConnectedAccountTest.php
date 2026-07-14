<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ConnectedAccountStatus;
use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConnectedAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_have_multiple_connected_accounts(): void
    {
        $user = User::factory()->create();

        $first = ConnectedAccount::factory()->create([
            'user_id' => $user->id,
            'gmail_email' => 'a@example.com',
        ]);

        $second = ConnectedAccount::factory()->create([
            'user_id' => $user->id,
            'gmail_email' => 'b@example.com',
        ]);

        $accounts = $user->connectedAccounts;

        $this->assertCount(2, $accounts);
        $this->assertTrue($accounts->contains($first));
        $this->assertTrue($accounts->contains($second));
    }

    public function test_tokens_are_encrypted_at_rest_in_the_database(): void
    {
        $account = ConnectedAccount::factory()->create([
            'access_token' => 'plain-access-token',
            'refresh_token' => 'plain-refresh-token',
        ]);

        $this->assertSame('plain-access-token', $account->fresh()->access_token);
        $this->assertSame('plain-refresh-token', $account->fresh()->refresh_token);

        $row = DB::table('connected_accounts')->where('id', $account->id)->first();

        $this->assertNotSame('plain-access-token', $row->access_token);
        $this->assertNotSame('plain-refresh-token', $row->refresh_token);
        $this->assertNotEmpty($row->access_token);
        $this->assertNotEmpty($row->refresh_token);
    }

    public function test_tokens_are_hidden_from_array_and_json_serialization(): void
    {
        $account = ConnectedAccount::factory()->create([
            'access_token' => 'secret-access',
            'refresh_token' => 'secret-refresh',
        ]);

        $array = $account->toArray();

        $this->assertArrayNotHasKey('access_token', $array);
        $this->assertArrayNotHasKey('refresh_token', $array);
    }

    public function test_status_is_cast_to_enum(): void
    {
        $account = ConnectedAccount::factory()->create([
            'status' => ConnectedAccountStatus::Connected,
        ]);

        $fresh = $account->fresh();

        $this->assertInstanceOf(ConnectedAccountStatus::class, $fresh->status);
        $this->assertSame(ConnectedAccountStatus::Connected, $fresh->status);
        $this->assertTrue($fresh->isConnected());
    }

    public function test_mark_disconnected_clears_tokens_and_sets_status(): void
    {
        $account = ConnectedAccount::factory()->create([
            'status' => ConnectedAccountStatus::Connected,
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
        ]);

        $account->markDisconnected();
        $account->refresh();

        $this->assertSame(ConnectedAccountStatus::Disconnected, $account->status);
        $this->assertFalse($account->isConnected());
        $this->assertNull($account->access_token);
        $this->assertNull($account->refresh_token);
        $this->assertNull($account->token_expires_at);
    }

    public function test_gmail_email_must_be_unique(): void
    {
        ConnectedAccount::factory()->create([
            'gmail_email' => 'same@example.com',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        ConnectedAccount::factory()->create([
            'gmail_email' => 'same@example.com',
        ]);
    }

    public function test_account_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $account = ConnectedAccount::factory()->create([
            'user_id' => $user->id,
        ]);

        $this->assertTrue($account->user->is($user));
    }
}
