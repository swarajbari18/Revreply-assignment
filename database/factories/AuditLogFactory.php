<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\Workflow;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    public function definition(): array
    {
        return [
            'workflow_id' => Workflow::factory(),
            'correlation_id' => (string) Str::uuid(),
            'event' => 'notification_received',
            'metadata' => [],
        ];
    }
}
