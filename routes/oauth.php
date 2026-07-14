<?php

declare(strict_types=1);

use App\Http\Controllers\GmailOAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/connect', [GmailOAuthController::class, 'connect'])->name('gmail.oauth.connect');
Route::get('/callback', [GmailOAuthController::class, 'callback'])->name('gmail.oauth.callback');
