<?php
require 'vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\ConnectedAccount;
use App\Services\Gmail\GmailTokenService;
use Google\Client;
use Google\Service\Gmail;

$account = ConnectedAccount::find(2);
$tokenService = app(GmailTokenService::class);
$client = app(Client::class);
$client->setAccessToken(['access_token' => $tokenService->getValidAccessToken($account)]);
$gmailService = new Gmail($client);

try {
    $thread = $gmailService->users_threads->get('me', '19f7122b9e2639c3');
    echo "Found thread: " . $thread->getId() . "\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
