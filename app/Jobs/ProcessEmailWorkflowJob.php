<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\Workflow;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessEmailWorkflowJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public int $workflowId,
        public int $connectedAccountId
    ) {}

    public function handle(): void
    {
        $workflow = Workflow::findOrFail($this->workflowId);

        Log::info('ProcessEmailWorkflowJob started', [
            'workflow_id' => $this->workflowId,
            'connected_account_id' => $this->connectedAccountId,
        ]);

        AuditLog::create([
            'workflow_id' => $workflow->id,
            'correlation_id' => $workflow->correlation_id,
            'event' => 'workflow_picked_up',
            'metadata' => [
                'processed_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function backoff(): array
    {
        return [5, 10, 20];
    }
}
