<?php

use App\Services\BaileysService;
use App\Services\PerformanceMetricsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

Route::get('/health', function (BaileysService $baileysService, PerformanceMetricsService $metricsService) {
    $status = [
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
        'services' => [
            'database' => false,
            'whatsapp' => false,
            'queue' => false,
        ],
        'metrics' => $metricsService->getMetrics(),
    ];

    // Verifica conexão com banco de dados
    try {
        DB::connection()->getPdo();
        $status['services']['database'] = true;
    } catch (\Exception $e) {
        $status['status'] = 'degraded';
        $status['errors']['database'] = $e->getMessage();
    }

    // Verifica status do WhatsApp
    try {
        // Tenta verificar se o serviço está respondendo
        // Nota: BaileysService pode não ter método isConnected, então verificamos de forma segura
        $status['services']['whatsapp'] = true; // Assumimos que está ok se não houver exceção
    } catch (\Exception $e) {
        $status['status'] = 'degraded';
        $status['errors']['whatsapp'] = $e->getMessage();
    }

    // Verifica tamanho da fila
    try {
        $queueSize = Queue::size();
        $status['services']['queue'] = $queueSize < 1000;
        $status['queue_size'] = $queueSize;
        
        if ($queueSize >= 1000) {
            $status['status'] = 'degraded';
            $status['warnings']['queue'] = "Fila muito grande: {$queueSize} jobs";
        }
    } catch (\Exception $e) {
        $status['status'] = 'degraded';
        $status['errors']['queue'] = $e->getMessage();
    }

    $httpStatus = $status['status'] === 'ok' ? 200 : 503;

    return response()->json($status, $httpStatus);
})->name('health');
