<?php

namespace App\Services\OpenFinance;

use App\Models\OpenFinanceConnection;
use App\Models\PluggyWebhookEvent;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class PluggyWebhookProcessor
{
    public function __construct(
        private readonly OpenFinanceSyncService $sync,
    ) {}

    public function process(PluggyWebhookEvent $event): void
    {
        $payload = $event->payload ?? [];
        $eventName = (string) ($payload['event'] ?? $event->event_name);
        $itemId = (string) ($payload['itemId'] ?? $event->item_id);
        $clientUserId = (string) ($payload['clientUserId'] ?? $event->client_user_id);

        try {
            match ($eventName) {
                'item/created', 'item/updated' => $this->handleItemReady($itemId, $clientUserId),
                'item/error' => $this->handleItemError($itemId, $payload),
                'item/deleted' => $this->handleItemDeleted($itemId),
                'item/waiting_user_input', 'item/waiting_user_action', 'item/login_succeeded' => $this->handleStatusOnly($itemId, $payload),
                default => null,
            };

            $event->forceFill([
                'status' => 'processed',
                'processed_at' => now(),
                'error_message' => null,
            ])->save();
        } catch (\Throwable $exception) {
            Log::error('Erro ao processar webhook da Pluggy', [
                'event_id' => $event->event_id,
                'event_name' => $eventName,
                'item_id' => $itemId,
                'error' => $exception->getMessage(),
            ]);

            $event->forceFill([
                'status' => 'failed',
                'processed_at' => now(),
                'error_message' => $exception->getMessage(),
            ])->save();

            throw $exception;
        }
    }

    private function handleItemReady(string $itemId, string $clientUserId): void
    {
        if ($itemId === '') {
            return;
        }

        $connection = OpenFinanceConnection::query()
            ->where('provider', 'pluggy')
            ->where('item_id', $itemId)
            ->first();

        if (! $connection && $clientUserId !== '') {
            $user = User::find($clientUserId);

            if ($user) {
                $connection = OpenFinanceConnection::create([
                    'user_id' => $user->id,
                    'provider' => 'pluggy',
                    'item_id' => $itemId,
                    'connected_at' => now(),
                    'status' => 'UPDATING',
                ]);
            }
        }

        if (! $connection) {
            return;
        }

        $this->sync->syncConnection($connection);
    }

    private function handleItemError(string $itemId, array $payload): void
    {
        $connection = $this->findConnection($itemId);
        if (! $connection) {
            return;
        }

        $error = $payload['error'] ?? [];

        $connection->forceFill([
            'status' => 'ERROR',
            'execution_status' => $error['code'] ?? $payload['executionStatus'] ?? 'ERROR',
            'sync_error' => $error['message'] ?? 'Pluggy informou um erro nesta conexao.',
            'metadata' => array_merge($connection->metadata ?? [], [
                'last_webhook_error' => $error,
            ]),
        ])->save();
    }

    private function handleItemDeleted(string $itemId): void
    {
        $connection = $this->findConnection($itemId);
        if (! $connection) {
            return;
        }

        $connection->forceFill([
            'status' => 'DELETED',
            'disconnected_at' => now(),
        ])->save();
    }

    private function handleStatusOnly(string $itemId, array $payload): void
    {
        $connection = $this->findConnection($itemId);
        if (! $connection) {
            return;
        }

        $connection->forceFill([
            'status' => (string) ($payload['itemStatus'] ?? $payload['status'] ?? $connection->status),
            'execution_status' => (string) ($payload['executionStatus'] ?? $connection->execution_status),
            'metadata' => array_merge($connection->metadata ?? [], [
                'last_webhook_status' => [
                    'event' => $payload['event'] ?? null,
                    'status' => $payload['status'] ?? null,
                    'executionStatus' => $payload['executionStatus'] ?? null,
                ],
            ]),
        ])->save();
    }

    private function findConnection(string $itemId): ?OpenFinanceConnection
    {
        if ($itemId === '') {
            return null;
        }

        return OpenFinanceConnection::query()
            ->where('provider', 'pluggy')
            ->where('item_id', $itemId)
            ->first();
    }
}
