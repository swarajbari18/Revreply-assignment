<?php

declare(strict_types=1);

use App\Http\Controllers\GmailOAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/accounts', [GmailOAuthController::class, 'index'])->name('accounts.index');
Route::delete('/accounts/{id}', [GmailOAuthController::class, 'destroy'])->name('accounts.destroy');
