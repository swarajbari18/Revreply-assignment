<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ProcessedNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PruneProcessedNotificationsCommand extends Command
{
    protected $signature = 'notifications:prune';

    protected $description = 'Prune processed notification idempotency keys older than 14 days';

    public function handle(): void
    {
        $deletedCount = ProcessedNotification::where('processed_at', '<', now()->subDays(14))->delete();

        Log::info('Pruned old processed notifications', [
            'deleted_count' => $deletedCount,
        ]);

        $this->info("Pruned {$deletedCount} old processed notifications.");
    }
}
