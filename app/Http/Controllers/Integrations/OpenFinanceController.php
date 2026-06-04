<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\OpenFinanceConnection;
use App\Services\OpenFinance\OpenFinanceManager;
use App\Services\OpenFinance\OpenFinanceSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OpenFinanceController extends Controller
{
    private function ensureEnabled(): void
    {
        abort_unless(config('openfinance.enabled', false), 404);
    }

    public function index(): View
    {
        $this->ensureEnabled();

        $user = Auth::user();

        return view('pages.integrations.open-finance', [
            'connections' => $user->openFinanceConnections()
                ->latest('last_synced_at')
                ->latest('created_at')
                ->get(),
            'bankAccounts' => $user->bankAccounts()
                ->whereNotNull('open_finance_account_id')
                ->orderByDesc('open_finance_synced_at')
                ->orderBy('name')
                ->get(),
            'creditCards' => $user->creditCards()
                ->whereNotNull('open_finance_account_id')
                ->orderByDesc('open_finance_synced_at')
                ->orderBy('name')
                ->get(),
            'pluggyEnabled' => filled(config('openfinance.pluggy.client_id')) && filled(config('openfinance.pluggy.client_secret')),
            'pluggyWidgetScript' => (string) config('openfinance.pluggy.connect_widget_script'),
            'pluggyIncludeSandbox' => (bool) config('openfinance.pluggy.include_sandbox', false),
        ]);
    }

    public function createConnectToken(Request $request, OpenFinanceManager $manager): JsonResponse
    {
        $this->ensureEnabled();

        $data = $request->validate([
            'itemId' => ['nullable', 'string', 'max:128'],
        ]);

        $result = $manager->provider()->createConnectToken(Auth::user(), $data['itemId'] ?? null);

        return response()->json([
            'accessToken' => $result['accessToken'] ?? null,
        ]);
    }

    public function storeConnection(Request $request, OpenFinanceManager $manager, OpenFinanceSyncService $sync): JsonResponse
    {
        $this->ensureEnabled();

        $data = $request->validate([
            'provider' => ['nullable', 'string', Rule::in(['pluggy'])],
            'item_id' => ['required', 'string', 'max:128'],
        ]);

        $providerName = $data['provider'] ?? (string) config('openfinance.provider', 'pluggy');
        $item = $manager->provider($providerName)->getItem($data['item_id']);

        $connection = OpenFinanceConnection::updateOrCreate(
            [
                'provider' => $providerName,
                'item_id' => $data['item_id'],
            ],
            [
                'user_id' => Auth::id(),
                'connector_id' => data_get($item, 'connector.id'),
                'connector_name' => data_get($item, 'connector.name'),
                'status' => $item['status'] ?? null,
                'execution_status' => $item['executionStatus'] ?? null,
                'connected_at' => now(),
                'disconnected_at' => null,
                'metadata' => ['item' => $item],
            ],
        );

        $summary = $sync->syncConnection($connection);

        return response()->json([
            'ok' => true,
            'connection_id' => $connection->id,
            'summary' => $summary,
        ]);
    }

    public function sync(OpenFinanceConnection $connection, OpenFinanceSyncService $sync): RedirectResponse
    {
        $this->ensureEnabled();

        abort_unless($connection->user_id === Auth::id(), 403);

        try {
            $summary = $sync->syncConnection($connection);
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->route('integrations.open-finance')
                ->with('message', 'Nao foi possivel sincronizar agora: '.$exception->getMessage());
        }

        return redirect()->route('integrations.open-finance')
            ->with('message', "Open Finance sincronizado: {$summary['accounts']} contas, {$summary['cards']} cartoes e {$summary['transactions']} transacoes.");
    }

    public function disconnect(OpenFinanceConnection $connection, OpenFinanceManager $manager): RedirectResponse
    {
        $this->ensureEnabled();

        abort_unless($connection->user_id === Auth::id(), 403);

        try {
            $manager->provider($connection->provider)->disconnect($connection);
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->route('integrations.open-finance')
                ->with('message', 'Nao foi possivel desconectar agora: '.$exception->getMessage());
        }

        $connection->forceFill([
            'disconnected_at' => now(),
            'status' => 'DISCONNECTED',
        ])->save();

        return redirect()->route('integrations.open-finance')
            ->with('message', 'Conexao Open Finance desconectada.');
    }
}
