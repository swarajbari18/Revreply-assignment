<?php

namespace Database\Factories;

use App\Models\ConnectedAccount;
use App\Models\ProcessedNotification;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProcessedNotificationFactory extends Factory
{
    protected $model = ProcessedNotification::class;

    public function definition(): array
    {
        return [
            'idempotency_key' => fake()->email().'|'.fake()->numerify('########'),
            'connected_account_id' => ConnectedAccount::factory(),
            'processed_at' => now(),
        ];
    }
}
