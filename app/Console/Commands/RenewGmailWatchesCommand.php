<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ConnectedAccountStatus;
use App\Jobs\RenewGmailWatchJob;
use App\Models\ConnectedAccount;
use Illuminate\Console\Command;

class RenewGmailWatchesCommand extends Command
{
    protected $signature = 'watches:renew';

    protected $description = 'Dispatch watch renewal jobs for accounts with expiring or missing watch subscriptions';

    public function handle(): void
    {
        $accounts = ConnectedAccount::where('status', ConnectedAccountStatus::Connected)
            ->where(function ($query) {
                $query->whereNull('watch_expiration')
                    ->orWhere('watch_expiration', '<=', now()->addHours(48));
            })
            ->get();

        if ($accounts->isEmpty()) {
            return;
        }

        foreach ($accounts as $account) {
            RenewGmailWatchJob::dispatch($account);
        }
    }
}
