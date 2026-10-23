<?php

use Illuminate\Support\Facades\Route;

// Backwards-compatible unversioned endpoints used by some clients/tests.
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/transactions', [App\Http\Controllers\Api\TransactionController::class, 'index']);
    Route::post('/transactions', [App\Http\Controllers\Api\TransactionController::class, 'store']);
    Route::get('/transactions/{transaction}', [App\Http\Controllers\Api\TransactionController::class, 'show']);
    Route::put('/transactions/{transaction}', [App\Http\Controllers\Api\TransactionController::class, 'update']);
    Route::delete('/transactions/{transaction}', [App\Http\Controllers\Api\TransactionController::class, 'destroy']);
});

// API Versionada
Route::prefix('v1')->group(function () {
    Route::prefix('internal/meeting-transcriptions')->middleware(['meeting.worker', 'throttle:120,1'])->group(function () {
        Route::post('/claim', [App\Http\Controllers\MeetingTranscriptionWorkerController::class, 'claim']);
        Route::post('/{task}/heartbeat', [App\Http\Controllers\MeetingTranscriptionWorkerController::class, 'heartbeat']);
        Route::post('/{task}/result', [App\Http\Controllers\MeetingTranscriptionWorkerController::class, 'result']);
        Route::post('/{task}/fail', [App\Http\Controllers\MeetingTranscriptionWorkerController::class, 'fail']);
    });
    Route::post('/webhook/vexa', [App\Http\Controllers\VexaWebhookController::class, 'handle'])
        ->middleware('throttle:120,1')
        ->name('api.v1.webhook.vexa');
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
