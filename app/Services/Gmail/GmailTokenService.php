<?php

declare(strict_types=1);

namespace App\Services\Gmail;

use App\Models\ConnectedAccount;
use Google\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GmailTokenService
{
    private const TOKEN_CACHE_TTL_SECONDS = 50 * 60;

    public function __construct(
        private readonly Client $client,
    ) {}

    public function getValidAccessToken(ConnectedAccount $account): string
    {
        $cacheKey = "gmail_token_{$account->id}";

        return Cache::remember(
            $cacheKey,
            self::TOKEN_CACHE_TTL_SECONDS,
            fn () => $this->resolveAccessToken($account)
        );
    }

    public function forgetCachedToken(ConnectedAccount $account): void
    {
        Cache::forget("gmail_token_{$account->id}");
    }

    private function resolveAccessToken(ConnectedAccount $account): string
    {
        $expiresAt    = $account->token_expires_at;
        $tokenIsAlive = $expiresAt !== null && $expiresAt->isAfter(now()->addMinutes(5));

        if ($tokenIsAlive && $account->access_token !== null) {
            return $account->access_token;
        }

        return $this->refreshAccessToken($account);
    }

    private function refreshAccessToken(ConnectedAccount $account): string
    {
        if (empty($account->refresh_token)) {
            $account->markDisconnected();

            Log::error('No refresh token available', ['account_id' => $account->id]);

            throw new \RuntimeException("Account {$account->id} has no refresh token.");
        }

        $this->client->setAccessToken(['refresh_token' => $account->refresh_token]);

        $newTokenData = $this->client->fetchAccessTokenWithRefreshToken($account->refresh_token);

        if (isset($newTokenData['error'])) {
            $isPermanent = in_array($newTokenData['error'], ['invalid_grant', 'unauthorized_client'], strict: true);

            if ($isPermanent) {
                $account->markDisconnected();

                Log::error('Gmail refresh token permanently revoked', [
                    'account_id' => $account->id,
                    'error'      => $newTokenData['error'],
                ]);

                throw new \RuntimeException("Refresh token permanently revoked for account {$account->id}.");
            }

            throw new \RuntimeException("Transient token refresh error for account {$account->id}: " . $newTokenData['error']);
        }

        $account->forceFill([
            'access_token'     => $newTokenData['access_token'],
            'token_expires_at' => now()->addSeconds($newTokenData['expires_in'] ?? 3600),
        ])->save();

        $this->forgetCachedToken($account);

        Log::info('Gmail access token refreshed', ['account_id' => $account->id]);

        return $newTokenData['access_token'];
    }
}
