<x-layouts.app.sidebar title="Integrações: Open Finance">
    <div class="p-6 space-y-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="space-y-2">
                <div class="inline-flex items-center gap-2 rounded-full border border-emerald-400/30 bg-emerald-500/10 px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] text-emerald-300">
                    Open Finance
                </div>
                <div>
                    <h1 class="text-3xl font-black tracking-tight text-white">Conecte bancos e cartões com sincronização automática</h1>
                    <p class="mt-2 max-w-3xl text-sm text-slate-300">
                        Essa primeira versão usa um provedor dedicado para Open Finance e importa contas, cartões e transações
                        direto para a estrutura que o InovaFinance já usa.
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                @if($pluggyEnabled)
                    <button type="button" class="pluggy-connect-button inline-flex items-center justify-center rounded-xl bg-emerald-500 px-4 py-2.5 text-sm font-semibold text-slate-950 shadow-sm transition hover:bg-emerald-400">
                        Conectar conta via Open Finance
                    </button>
                @else
                    <div class="rounded-xl border border-amber-400/30 bg-amber-500/10 px-4 py-2 text-sm text-amber-200">
                        Configure <span class="font-mono">PLUGGY_CLIENT_ID</span> e <span class="font-mono">PLUGGY_CLIENT_SECRET</span> para ativar.
                    </div>
                @endif
            </div>
        </div>

        <div class="grid gap-4 xl:grid-cols-[1.1fr_0.9fr]">
            <div class="rounded-2xl border border-white/10 bg-black/30 p-5 backdrop-blur-sm">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-bold text-white">Conexões</h2>
                        <p class="text-sm text-slate-400">Cada conexão representa um item retornado pelo provedor de Open Finance.</p>
                    </div>
                </div>

                <div class="mt-4 space-y-4">
                    @forelse($connections as $connection)
                        <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                <div class="space-y-3 min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="text-base font-semibold text-white">{{ $connection->connector_name ?: 'Conexao Open Finance' }}</h3>
                                        <span class="rounded-full bg-sky-500/15 px-2.5 py-1 text-xs font-medium text-sky-300">{{ $connection->provider }}</span>
                                        <span class="rounded-full bg-emerald-500/15 px-2.5 py-1 text-xs font-medium text-emerald-300">{{ $connection->status ?: 'sem status' }}</span>
                                        @if($connection->execution_status)
                                            <span class="rounded-full bg-violet-500/15 px-2.5 py-1 text-xs font-medium text-violet-300">{{ $connection->execution_status }}</span>
                                        @endif
                                    </div>

                                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4 text-sm">
                                        <div>
                                            <p class="text-slate-500">Item</p>
                                            <p class="font-mono text-xs text-slate-200 break-all">{{ $connection->item_id }}</p>
                                        </div>
                                        <div>
                                            <p class="text-slate-500">Conectado em</p>
                                            <p class="text-slate-200">{{ optional($connection->connected_at)->format('d/m/Y H:i') ?: '—' }}</p>
                                        </div>
                                        <div>
                                            <p class="text-slate-500">Última sync</p>
                                            <p class="text-slate-200">{{ optional($connection->last_synced_at)->format('d/m/Y H:i') ?: 'Ainda não sincronizado' }}</p>
                                        </div>
                                        <div>
                                            <p class="text-slate-500">Resumo</p>
                                            <p class="text-slate-200">
                                                {{ data_get($connection->last_sync_summary, 'accounts', 0) }} contas,
                                                {{ data_get($connection->last_sync_summary, 'cards', 0) }} cartões,
                                                {{ data_get($connection->last_sync_summary, 'transactions', 0) }} transações
                                            </p>
                                        </div>
                                    </div>

                                    @if($connection->sync_error)
                                        <div class="rounded-xl border border-red-400/20 bg-red-500/10 px-3 py-2 text-sm text-red-200">
                                            {{ $connection->sync_error }}
                                        </div>
                                    @endif
                                </div>

                                <div class="flex flex-wrap gap-2 lg:justify-end">
                                    <button
                                        type="button"
                                        class="pluggy-update-{{ $connection->id }} inline-flex items-center justify-center rounded-xl border border-white/15 bg-white/5 px-3 py-2 text-sm font-semibold text-slate-100 transition hover:bg-white/10"
                                    >
                                        Atualizar acesso
                                    </button>

                                    <form method="POST" action="{{ route('integrations.open-finance.sync', $connection) }}">
                                        @csrf
                                        <flux:button type="submit" variant="primary">Sincronizar agora</flux:button>
                                    </form>

                                    <form method="POST" action="{{ route('integrations.open-finance.disconnect', $connection) }}">
                                        @csrf
                                        <flux:button type="submit" variant="danger">Desconectar</flux:button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="rounded-2xl border border-dashed border-white/15 bg-white/[0.02] p-8 text-center">
                            <p class="text-sm text-slate-400">Nenhuma conexão Open Finance criada ainda.</p>
                        </div>
                    @endforelse
                </div>
            </div>

            <div class="space-y-4">
                <div class="rounded-2xl border border-white/10 bg-black/30 p-5 backdrop-blur-sm">
                    <h2 class="text-lg font-bold text-white">Contas sincronizadas</h2>
                    <div class="mt-4 space-y-3">
                        @forelse($bankAccounts as $account)
                            <div class="rounded-xl border border-white/10 bg-white/[0.03] p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <p class="font-semibold text-white">{{ $account->name }}</p>
                                        <p class="text-sm text-slate-400">{{ $account->institution ?: 'Open Finance' }} · {{ ucfirst($account->type) }}</p>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-xs text-slate-500">Saldo sincronizado</p>
                                        <p class="font-semibold text-emerald-300">R$ {{ number_format((float) ($account->open_finance_balance ?? 0), 2, ',', '.') }}</p>
                                    </div>
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-slate-500">Nenhuma conta sincronizada ainda.</p>
                        @endforelse
                    </div>
                </div>

                <div class="rounded-2xl border border-white/10 bg-black/30 p-5 backdrop-blur-sm">
                    <h2 class="text-lg font-bold text-white">Cartões sincronizados</h2>
                    <div class="mt-4 space-y-3">
                        @forelse($creditCards as $card)
                            <div class="rounded-xl border border-white/10 bg-white/[0.03] p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <p class="font-semibold text-white">{{ $card->name }}</p>
                                        <p class="text-sm text-slate-400">{{ $card->issuer ?: 'Open Finance' }} · {{ $card->brand ?: 'Cartão' }}</p>
                                    </div>
                                    <div class="text-right space-y-1">
                                        <div>
                                            <p class="text-xs text-slate-500">Fatura aberta</p>
                                            <p class="font-semibold text-rose-300">R$ {{ number_format((float) ($card->open_finance_balance ?? 0), 2, ',', '.') }}</p>
                                        </div>
                                        <div>
                                            <p class="text-xs text-slate-500">Limite disponível</p>
                                            <p class="font-semibold text-emerald-300">R$ {{ number_format((float) ($card->open_finance_available_limit ?? 0), 2, ',', '.') }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-slate-500">Nenhum cartão sincronizado ainda.</p>
                        @endforelse
                    </div>
                </div>

                <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-5">
                    <h2 class="text-lg font-bold text-white">Como funciona</h2>
                    <ul class="mt-3 space-y-2 text-sm text-slate-300">
                        <li>1. Você abre o widget e autoriza seu banco pelo fluxo oficial.</li>
                        <li>2. O sistema grava a conexão retornada pelo provedor.</li>
                        <li>3. A sincronização cria contas, cartões e importa transações recentes.</li>
                        <li>4. Depois podemos evoluir para sync automática, conciliação e enriquecimento.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    @if($pluggyEnabled)
        @push('scripts')
            <script src="{{ $pluggyWidgetScript }}"></script>
            <script>
                window.pluggy = {
                    includeSandbox: @json($pluggyIncludeSandbox),
                    connectTokenUrl: @json(route('integrations.open-finance.connect-token')),
                    buttons: [
                        {
                            clientUserId: @json((string) auth()->id()),
                            buttonClassName: 'pluggy-connect-button',
                            updateItem: null,
                        },
                        @foreach($connections as $connection)
                        {
                            clientUserId: @json((string) auth()->id()),
                            buttonClassName: @json('pluggy-update-'.$connection->id),
                            updateItem: @json($connection->item_id),
                        },
                        @endforeach
                    ],
                    onSuccess: async ({ item }) => {
                        const response = await fetch(@json(route('integrations.open-finance.connections.store')), {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': @json(csrf_token()),
                                'Accept': 'application/json',
                            },
                            body: JSON.stringify({
                                item_id: item?.id,
                                provider: 'pluggy',
                            }),
                        });

                        if (! response.ok) {
                            const data = await response.json().catch(() => ({}));
                            throw new Error(data.message || 'Nao consegui salvar a conexao Open Finance.');
                        }

                        window.location.reload();
                    },
                    onError: (error) => {
                        window.Livewire?.dispatch('toast', {
                            type: 'error',
                            message: error?.message || 'Nao foi possivel concluir a conexao Open Finance.',
                        });
                    },
                };
            </script>
            <script src="{{ $pluggyWidgetHelperScript }}"></script>
        @endpush
    @endif
</x-layouts.app.sidebar>
