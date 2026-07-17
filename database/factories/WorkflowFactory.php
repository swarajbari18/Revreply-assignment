<?php

namespace Database\Factories;

use App\Enums\WorkflowStatus;
use App\Models\ConnectedAccount;
use App\Models\Workflow;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class WorkflowFactory extends Factory
{
    protected $model = Workflow::class;

    public function definition(): array
    {
        return [
            'connected_account_id' => ConnectedAccount::factory(),
            'thread_id' => (string) fake()->numerify('################'),
            'latest_message_id' => (string) fake()->numerify('################'),
            'status' => WorkflowStatus::Received,
            'correlation_id' => (string) Str::uuid(),
            'started_at' => now(),
            'completed_at' => null,
        ];
    }
}
