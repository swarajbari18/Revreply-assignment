<?php

declare(strict_types=1);

namespace App\Services\Gmail;

use App\Models\ConnectedAccount;
use Carbon\Carbon;
use Google\Client;
use Google\Service\Gmail;
use Illuminate\Support\Facades\Log;

class GmailWatchService
{
    public function __construct(
        private readonly Client $client,
        private readonly GmailTokenService $tokenService,
    ) {}

    public function watch(ConnectedAccount $account): void
    {
        $accessToken = $this->tokenService->getValidAccessToken($account);

        $this->client->setAccessToken(['access_token' => $accessToken]);

        $gmailService = new Gmail($this->client);

        $watchRequest = new Gmail\WatchRequest([
            'topicName' => config('services.google.pubsub_topic'),
            'labelIds' => ['INBOX'],
            'labelFilterBehavior' => 'INCLUDE',
        ]);

        $response = $gmailService->users->watch('me', $watchRequest);

        $historyId = (string) $response->getHistoryId();
        $expiration = Carbon::createFromTimestampMs($response->getExpiration());

        $account->forceFill([
            'watch_expiration' => $expiration,
            'last_history_id' => $account->last_history_id ?? $historyId,
        ])->save();

        Log::info('Gmail watch registered', [
            'account_id' => $account->id,
            'gmail_email' => $account->gmail_email,
            'watch_expiration' => $expiration->toDateTimeString(),
        ]);
    }
}
