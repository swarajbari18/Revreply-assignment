<?php

declare(strict_types=1);

namespace App\Services\Gmail;

use App\Enums\ConnectedAccountStatus;
use App\Models\ConnectedAccount;
use Google\Client;
use Google\Service\Oauth2;
use Illuminate\Support\Facades\Log;

class GmailOAuthService
{
    public function __construct(
        private readonly Client $client,
    ) {}

    public function getAuthUrl(int $userId): string
    {
        $state = base64_encode(json_encode(['user_id' => $userId]));

        $this->client->setState($state);

        return $this->client->createAuthUrl();
    }

    public function handleCallback(string $code, string $state): ConnectedAccount
    {
        $stateData = json_decode(base64_decode($state), associative: true);
        $userId    = (int) ($stateData['user_id'] ?? 0);

        if ($userId === 0) {
            throw new \RuntimeException('Invalid OAuth state: missing user_id');
        }

        $tokenData = $this->client->fetchAccessTokenWithAuthCode($code);

        if (isset($tokenData['error'])) {
            throw new \RuntimeException('Google token exchange failed: ' . $tokenData['error_description']);
        }

        $this->client->setAccessToken($tokenData);

        $oauth2Service = new Oauth2($this->client);
        $googleUser    = $oauth2Service->userinfo->get();
        $gmailEmail    = $googleUser->getEmail();

        if (empty($gmailEmail)) {
            throw new \RuntimeException('Could not determine Gmail address from token');
        }

        $account = ConnectedAccount::updateOrCreate(
            ['gmail_email' => $gmailEmail],
            [
                'user_id'          => $userId,
                'access_token'     => $tokenData['access_token'],
                'refresh_token'    => $tokenData['refresh_token'] ?? null,
                'token_expires_at' => now()->addSeconds($tokenData['expires_in'] ?? 3600),
                'status'           => ConnectedAccountStatus::Connected,
            ]
        );

        Log::info('Gmail account connected', [
            'user_id'     => $userId,
            'gmail_email' => $gmailEmail,
            'account_id'  => $account->id,
        ]);

        return $account;
    }
}
