<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ConnectedAccountStatus;
use App\Jobs\RenewGmailWatchJob;
use App\Models\ConnectedAccount;
use App\Services\GmailIngestionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RecoverGmailGapsCommand extends Command
{
    protected $signature = 'gaps:recover';

    protected $description = 'Recover missed Gmail messages for connected accounts with expired watches';

    public function __construct(
        private readonly GmailIngestionService $ingestionService
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        $accounts = ConnectedAccount::where('status', ConnectedAccountStatus::Connected)
            ->whereNotNull('last_history_id')
            ->where(function ($query) {
                $query->whereNull('watch_expiration')
                    ->orWhere('watch_expiration', '<=', now());
            })
            ->get();

        if ($accounts->isEmpty()) {
            return;
        }

        foreach ($accounts as $account) {
            Log::info('Recovering gap for account', [
                'account_id' => $account->id,
                'last_history_id' => $account->last_history_id,
            ]);

            try {
                $this->ingestionService->recoverGap($account, $account->last_history_id);
            } catch (\Exception $exception) {
                Log::error('Failed to recover gap for account', [
                    'account_id' => $account->id,
                    'error' => $exception->getMessage(),
                ]);
            }

            RenewGmailWatchJob::dispatch($account);
        }
    }
}
