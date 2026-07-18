<?php

declare(strict_types=1);

namespace App\Services\Gmail;

use Google\Client;

class GmailClientFactory
{
    public static function make(): Client
    {
        $client = new Client;

        $client->setClientId(config('services.google.client_id'));
        $client->setClientSecret(config('services.google.client_secret'));
        $client->setRedirectUri(config('services.google.redirect_uri'));
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->addScope('https://www.googleapis.com/auth/gmail.modify');
        $client->addScope('https://www.googleapis.com/auth/userinfo.email');

        return $client;
    }
}
