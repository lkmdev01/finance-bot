<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

class PerformanceMetricsService
{
    /**
     * Registra métrica de tempo de resposta da IA
     */
    public function recordAITime(float $timeMs, string $action = null): void
    {
        $key = 'metrics:ai:response_time';
        $this->incrementMetric($key, $timeMs);
        
        // Incrementa contador de requisições
        Cache::increment('metrics:ai:total_requests', 1);
        Cache::put('metrics:ai:total_requests', Cache::get('metrics:ai:total_requests', 0), now()->addDay());
        
        if ($action) {
            $actionKey = "metrics:ai:response_time:{$action}";
            $this->incrementMetric($actionKey, $timeMs);
        }
    }

    /**
     * Registra métrica de sucesso de transação
     */
    public function recordTransactionSuccess(bool $success, string $source = 'whatsapp'): void
    {
        $key = "metrics:transactions:{$source}:" . ($success ? 'success' : 'failure');
        Cache::increment($key, 1);
        
        // Expira após 24 horas
        Cache::put($key, Cache::get($key, 0), now()->addDay());
    }

    /**
     * Registra métrica de erro
     */
    public function recordError(string $type, string $message = null): void
    {
        $key = "metrics:errors:{$type}";
        Cache::increment($key, 1);
        
        if ($message) {
            Log::warning('Erro registrado nas métricas', [
                'type' => $type,
                'message' => $message,
            ]);
        }
    }

    /**
     * Obtém métricas de performance
     */
    public function getMetrics(): array
    {
        return [
            'ai' => [
                'avg_response_time_ms' => $this->getAverageMetric('metrics:ai:response_time'),
                'total_requests' => Cache::get('metrics:ai:total_requests', 0),
            ],
            'transactions' => [
                'whatsapp_success' => Cache::get('metrics:transactions:whatsapp:success', 0),
                'whatsapp_failure' => Cache::get('metrics:transactions:whatsapp:failure', 0),
                'success_rate' => $this->calculateSuccessRate('whatsapp'),
            ],
            'errors' => [
                'total' => $this->getTotalErrors(),
                'by_type' => $this->getErrorsByType(),
            ],
            'queue' => [
                'size' => Queue::size(),
            ],
            'database' => [
                'connection_time_ms' => $this->measureDatabaseConnectionTime(),
            ],
        ];
    }

    /**
     * Incrementa métrica com valor
     */
    private function incrementMetric(string $key, float $value): void
    {
        $current = Cache::get($key, 0);
        $count = Cache::get("{$key}:count", 0);
        
        Cache::put($key, $current + $value, now()->addDay());
        Cache::put("{$key}:count", $count + 1, now()->addDay());
    }

    /**
     * Obtém média de uma métrica
     */
    private function getAverageMetric(string $key): float
    {
        $total = Cache::get($key, 0);
        $count = Cache::get("{$key}:count", 1);
        
        return $count > 0 ? round($total / $count, 2) : 0;
    }

    /**
     * Calcula taxa de sucesso
     */
    private function calculateSuccessRate(string $source): float
    {
        $success = Cache::get("metrics:transactions:{$source}:success", 0);
        $failure = Cache::get("metrics:transactions:{$source}:failure", 0);
        $total = $success + $failure;
        
        return $total > 0 ? round(($success / $total) * 100, 2) : 0;
    }

    /**
     * Obtém total de erros
     */
    private function getTotalErrors(): int
    {
        // Busca todas as chaves de erro
        $keys = [
            'metrics:errors:validation',
            'metrics:errors:database',
            'metrics:errors:ai',
            'metrics:errors:whatsapp',
        ];
        
        return array_sum(array_map(fn($key) => Cache::get($key, 0), $keys));
    }

    /**
     * Obtém erros por tipo
     */
    private function getErrorsByType(): array
    {
        return [
            'validation' => Cache::get('metrics:errors:validation', 0),
            'database' => Cache::get('metrics:errors:database', 0),
            'ai' => Cache::get('metrics:errors:ai', 0),
            'whatsapp' => Cache::get('metrics:errors:whatsapp', 0),
        ];
    }

    /**
     * Mede tempo de conexão com banco
     */
    private function measureDatabaseConnectionTime(): float
    {
        $start = microtime(true);
        try {
            DB::connection()->getPdo();
            return round((microtime(true) - $start) * 1000, 2);
        } catch (\Exception $e) {
            return -1; // Indica erro
        }
    }

    /**
     * Obtém taxa de erro
     */
    public function getErrorRate(): float
    {
        $totalErrors = $this->getTotalErrors();
        $totalRequests = $this->getTotalRequests();
        
        return $totalRequests > 0 ? $totalErrors / $totalRequests : 0;
    }

    /**
     * Obtém total de requisições
     */
    public function getTotalRequests(): int
    {
        return Cache::get('metrics:ai:total_requests', 0);
    }

    /**
     * Obtém tempo médio de resposta
     */
    public function getAverageResponseTime(): float
    {
        return $this->getAverageMetric('metrics:ai:response_time');
    }

    /**
     * Obtém taxa de sucesso
     */
    public function getSuccessRate(): float
    {
        return $this->calculateSuccessRate('whatsapp') / 100;
    }

    /**
     * Obtém contagem de erros
     */
    public function getErrorCount(): int
    {
        return $this->getTotalErrors();
    }
}
