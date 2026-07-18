<?php

declare(strict_types=1);

use App\Http\Controllers\WorkflowController;
use Illuminate\Support\Facades\Route;

Route::get('/workflows', [WorkflowController::class, 'index'])->name('workflows.index');
Route::get('/workflows/{id}', [WorkflowController::class, 'show'])->name('workflows.show');
Route::post('/workflows/{id}/approve', [WorkflowController::class, 'approve'])->name('workflows.approve');
Route::post('/workflows/{id}/send', [WorkflowController::class, 'send'])->name('workflows.send');
Route::post('/workflows/{id}/reject', [WorkflowController::class, 'reject'])->name('workflows.reject');
