<?php

use App\Services\BaileysService;
use App\Services\QueueHeartbeatService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

Route::get('/health', function (BaileysService $baileysService, QueueHeartbeatService $heartbeat) {
    $services = [
        'database' => false,
        'whatsapp' => false,
        'queue' => false,
    ];
    $details = [];

    try {
        DB::select('select 1');
        $services['database'] = true;
    } catch (Throwable $exception) {
        Log::warning('Health check do banco falhou.', ['exception' => $exception::class]);
    }

    try {
        $response = $baileysService->checkConnection();
        $services['whatsapp'] = $response->successful() && $response->json('connected') === true;
    } catch (Throwable $exception) {
        Log::warning('Health check do WhatsApp falhou.', ['exception' => $exception::class]);
    }

    try {
        $queueHeartbeat = $heartbeat->snapshot();
        $services['queue'] = $queueHeartbeat['healthy'];
        $details['queue'] = [
            'state' => $queueHeartbeat['state'],
            'size' => Queue::connection($queueHeartbeat['connection'])->size($queueHeartbeat['queue']),
            'pending_age_seconds' => $queueHeartbeat['pending_age_seconds'],
            'processed_age_seconds' => $queueHeartbeat['processed_age_seconds'],
        ];
    } catch (Throwable $exception) {
        Log::warning('Health check da fila falhou.', ['exception' => $exception::class]);
    }

    $healthy = ! in_array(false, $services, true);

    return response()->json([
        'status' => $healthy ? 'ok' : 'degraded',
        'timestamp' => now()->toIso8601String(),
        'services' => $services,
        'details' => $details,
    ], $healthy ? 200 : 503);
})->name('health');
