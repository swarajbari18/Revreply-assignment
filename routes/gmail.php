<?php

declare(strict_types=1);

use App\Http\Controllers\GmailWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhook', [GmailWebhookController::class, 'handle']);
