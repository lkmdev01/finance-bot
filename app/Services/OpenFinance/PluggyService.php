<?php

namespace App\Services\OpenFinance;

use App\Models\OpenFinanceConnection;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PluggyService implements OpenFinanceProvider
{
    private const API_KEY_CACHE = 'openfinance:pluggy:api-key';

    public function createConnectToken(User $user, ?string $itemId = null): array
    {
        $payload = [
            'options' => [
                'clientUserId' => (string) $user->id,
                'avoidDuplicates' => true,
                'webhookUrl' => route('webhook.pluggy'),
            ],
        ];

        if ($itemId) {
            $payload['itemId'] = $itemId;
        }

        $response = $this->request()
            ->post('/connect_token', $payload);

        if (! $response->successful()) {
            $this->throwPluggyException($response, 'Nao foi possivel iniciar a conexao Open Finance.');
        }

        return $response->json();
    }

    public function getItem(string $itemId): array
    {
        $response = $this->request()->get('/items/'.rawurlencode($itemId));

        if (! $response->successful()) {
            $this->throwPluggyException($response, 'Nao foi possivel consultar a conexao Open Finance.');
        }

        return $response->json();
    }

    public function getAccounts(string $itemId): array
    {
        $response = $this->request()->get('/accounts', [
            'itemId' => $itemId,
        ]);

        if (! $response->successful()) {
            $this->throwPluggyException($response, 'Nao foi possivel buscar as contas conectadas.');
        }

        return $this->extractResults($response->json());
    }

    public function getTransactions(string $accountId): array
    {
        $all = [];
        $cursor = null;
        $page = 1;

        do {
            $query = [
                'accountId' => $accountId,
                'pageSize' => 500,
            ];

            if ($cursor) {
                $query['cursor'] = $cursor;
            } elseif ($page > 1) {
                $query['page'] = $page;
            }

            $response = $this->request()->get('/v2/transactions', $query);

            if (! $response->successful()) {
                $this->throwPluggyException($response, 'Nao foi possivel buscar as transacoes da conta conectada.');
            }

            $data = $response->json();
            $results = $this->extractResults($data);
            $all = array_merge($all, $results);

            $cursor = data_get($data, 'nextCursor');
            $page = (int) (data_get($data, 'page', $page) + 1);
        } while ($cursor || count($results) === 500);

        return $all;
    }

    public function disconnect(OpenFinanceConnection $connection): void
    {
        $response = $this->request()->delete('/items/'.rawurlencode($connection->item_id));

        if (! $response->successful() && $response->status() !== 404) {
            $this->throwPluggyException($response, 'Nao foi possivel desconectar a conexao Open Finance.');
        }
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl((string) config('openfinance.pluggy.base_url'))
            ->timeout((int) config('openfinance.pluggy.timeout', 30))
            ->withHeaders([
                'Accept' => 'application/json',
                'X-API-KEY' => $this->apiKey(),
            ]);
    }

    private function apiKey(): string
    {
        return Cache::remember(self::API_KEY_CACHE, Carbon::now()->addMinutes(110), function (): string {
            $clientId = (string) config('openfinance.pluggy.client_id');
            $clientSecret = (string) config('openfinance.pluggy.client_secret');

            if ($clientId === '' || $clientSecret === '') {
                throw new RuntimeException('Credenciais da Pluggy nao configuradas.');
            }

            $response = Http::baseUrl((string) config('openfinance.pluggy.base_url'))
                ->timeout((int) config('openfinance.pluggy.timeout', 30))
                ->acceptJson()
                ->post('/auth', [
                    'clientId' => $clientId,
                    'clientSecret' => $clientSecret,
                ]);

            if (! $response->successful()) {
                $this->throwPluggyException($response, 'Nao foi possivel autenticar com o provedor Open Finance.');
            }

            $apiKey = (string) $response->json('apiKey');
            if ($apiKey === '') {
                throw new RuntimeException('Pluggy nao retornou uma API key valida.');
            }

            return $apiKey;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function extractResults(array $data): array
    {
        $results = $data['results'] ?? $data;

        return is_array($results) ? array_values($results) : [];
    }

    private function throwPluggyException(Response $response, string $fallback): never
    {
        Log::warning('Pluggy request failed', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        $message = data_get($response->json(), 'message')
            ?: data_get($response->json(), 'error.message')
            ?: $fallback;

        throw new RuntimeException((string) $message);
    }
}
