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
    $response = $gmailService->users_history->listUsersHistory('me', [
        'startHistoryId' => '761520'
    ]);
    foreach ($response->getHistory() as $history) {
        echo "History ID: " . $history->getId() . "\n";
        $added = $history->getMessagesAdded();
        if ($added) {
            foreach ($added as $mAdded) {
                echo "  Added Message: " . $mAdded->getMessage()->getId() . " Thread: " . $mAdded->getMessage()->getThreadId() . "\n";
            }
        }
        $deleted = $history->getMessagesDeleted();
        if ($deleted) {
            foreach ($deleted as $mDeleted) {
                echo "  Deleted Message: " . $mDeleted->getMessage()->getId() . " Thread: " . $mDeleted->getMessage()->getThreadId() . "\n";
            }
        }
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
