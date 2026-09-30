<?php

namespace App\Jobs;

use App\Services\QueueHeartbeatService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RecordQueueHeartbeat implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $probeId) {}

    public function handle(QueueHeartbeatService $heartbeat): void
    {
        $heartbeat->recordProcessed($this->probeId);
    }
}
