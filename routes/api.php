<?php

use Illuminate\Support\Facades\Route;

// API Versionada
Route::prefix('v1')->group(function () {
    // Webhook WhatsApp (versão 1)
    Route::post('/webhook/whatsapp', [App\Http\Controllers\WhatsAppWebhookController::class, 'handle'])
        ->middleware('throttle:60,1')
        ->name('api.v1.webhook.whatsapp');
    
    // Transações
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::get('/transactions', [App\Http\Controllers\Api\TransactionController::class, 'index']);
        Route::post('/transactions', [App\Http\Controllers\Api\TransactionController::class, 'store']);
        Route::get('/transactions/{transaction}', [App\Http\Controllers\Api\TransactionController::class, 'show']);
        Route::put('/transactions/{transaction}', [App\Http\Controllers\Api\TransactionController::class, 'update']);
        Route::delete('/transactions/{transaction}', [App\Http\Controllers\Api\TransactionController::class, 'destroy']);
    });
});
