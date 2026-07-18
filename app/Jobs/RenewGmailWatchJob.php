<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ConnectedAccount;
use App\Services\Gmail\GmailWatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RenewGmailWatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public ConnectedAccount $account
    ) {}

    public function handle(GmailWatchService $watchService): void
    {
        if (! $this->account->isConnected()) {
            return;
        }

        try {
            $watchService->watch($this->account);
        } catch (\RuntimeException $exception) {
            $message = $exception->getMessage();
            if (str_contains($message, 'permanently revoked') || str_contains($message, 'no refresh token')) {
                $this->fail($exception);

                return;
            }
            throw $exception;
        }
    }

    public function backoff(): array
    {
        return [5, 10, 20];
    }
}
