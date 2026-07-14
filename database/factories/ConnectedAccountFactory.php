<?php

namespace Database\Factories;

use App\Enums\ConnectedAccountStatus;
use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConnectedAccount>
 */
class ConnectedAccountFactory extends Factory
{
    protected $model = ConnectedAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'gmail_email' => fake()->unique()->safeEmail(),
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'token_expires_at' => now()->addMinutes(55),
            'status' => ConnectedAccountStatus::Connected,
            'watch_expiration' => now()->addDays(6),
            'last_history_id' => (string) fake()->numerify('########'),
        ];
    }

    /**
     * State for a mailbox that requires the user to reconnect.
     */
    public function disconnected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ConnectedAccountStatus::Disconnected,
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
        ]);
    }
}
