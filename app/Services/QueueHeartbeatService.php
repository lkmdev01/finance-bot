<?php

namespace App\Services;

use App\Jobs\RecordQueueHeartbeat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class QueueHeartbeatService
{
    public function dispatchProbe(): bool
    {
        if (Cache::has($this->pendingKey())) {
            return false;
        }

        $probeId = (string) Str::uuid();
        Cache::put($this->pendingKey(), [
            'id' => $probeId,
            'at' => now()->timestamp,
        ], now()->addDay());

        try {
            RecordQueueHeartbeat::dispatch($probeId)
                ->onConnection($this->connection())
                ->onQueue($this->queue());
        } catch (Throwable $exception) {
            Cache::forget($this->pendingKey());
            throw $exception;
        }

        return true;
    }

    public function recordProcessed(string $probeId): void
    {
        $pending = Cache::get($this->pendingKey());

        Cache::put($this->processedKey(), [
            'id' => $probeId,
            'at' => now()->timestamp,
        ], now()->addDays(7));

        if (! is_array($pending) || ($pending['id'] ?? null) === $probeId) {
            Cache::forget($this->pendingKey());
        }
    }

    /**
     * @return array{healthy: bool, state: string, connection: string, queue: string, pending_age_seconds: int|null, processed_age_seconds: int|null}
     */
    public function snapshot(): array
    {
        $pending = Cache::get($this->pendingKey());
        $processed = Cache::get($this->processedKey());
        $pendingAge = $this->age($pending);
        $processedAge = $this->age($processed);
        $maxAge = max(30, (int) config('queue_health.max_age_seconds', 180));

        if ($pendingAge !== null) {
            $healthy = $pendingAge <= $maxAge;
            $state = $healthy ? 'probe_pending' : 'probe_stale';
        } elseif ($processedAge !== null) {
            $healthy = $processedAge <= $maxAge;
            $state = $healthy ? 'processed' : 'heartbeat_stale';
        } else {
            $healthy = false;
            $state = 'not_initialized';
        }

        return [
            'healthy' => $healthy,
            'state' => $state,
            'connection' => $this->connection(),
            'queue' => $this->queue(),
            'pending_age_seconds' => $pendingAge,
            'processed_age_seconds' => $processedAge,
        ];
    }

    public function connection(): string
    {
        return (string) config('queue.default');
    }

    public function queue(): string
    {
        return (string) config('queue_health.queue', 'default');
    }

    private function age(mixed $value): ?int
    {
        if (! is_array($value) || ! is_numeric($value['at'] ?? null)) {
            return null;
        }

        return max(0, now()->timestamp - (int) $value['at']);
    }

    private function pendingKey(): string
    {
        return "queue-health:{$this->connection()}:{$this->queue()}:pending";
    }

    private function processedKey(): string
    {
        return "queue-health:{$this->connection()}:{$this->queue()}:processed";
    }
}
