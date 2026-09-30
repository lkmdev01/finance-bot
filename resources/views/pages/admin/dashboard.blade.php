<x-layouts.app.sidebar title="Administração InovaFinance">
    <div class="space-y-6" data-admin-dashboard>
        <h1 class="text-2xl font-bold">Painel InovaFinance</h1>
        <p class="text-slate-300">Gerencie usuários, comunicações e a operação do sistema.</p>
        @include("pages.admin.navigation")
    @can('viewAssistantObservability')
        @php
            $assistantSummary = app(\App\Assistant\Reports\AssistantObservabilityService::class)->summary(7, 250);
        @endphp

        <div class="mb-6 rounded-3xl border border-cyan-400/20 bg-gradient-to-r from-cyan-400/10 via-sky-400/10 to-transparent p-5">
            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div>
                    <p class="text-xs uppercase tracking-[0.24em] text-cyan-300/80">Revisao do assistente</p>
                    <h2 class="mt-2 text-xl font-semibold text-white">Observabilidade IA ligada no fluxo</h2>
                    <p class="mt-2 text-sm text-slate-300">
                        Nos ultimos 7 dias tivemos {{ $assistantSummary['totals']['unknowns'] }} mensagens como <code>unknown</code>
                        e {{ $assistantSummary['totals']['errors'] }} falhas registradas.
                    </p>
                </div>

                <div class="flex flex-wrap gap-3">
                    <a href="{{ route('assistant.observability', ['focus' => 'unknown', 'days' => 7]) }}" class="rounded-2xl border border-amber-300/30 bg-amber-300/10 px-4 py-2 text-sm font-semibold text-amber-100 transition hover:bg-amber-300/20">
                        Revisar unknown
                    </a>
                    <a href="{{ route('assistant.observability', ['focus' => 'missing', 'days' => 7]) }}" class="rounded-2xl border border-cyan-300/30 bg-cyan-300/10 px-4 py-2 text-sm font-semibold text-cyan-100 transition hover:bg-cyan-300/20">
                        Revisar missing_fields
                    </a>
                </div>
            </div>
        </div>
    @endcan

                <div class="lg:col-span-2 bg-white dark:bg-space-900 rounded-2xl border border-zinc-200 dark:border-white/10 p-6 shadow-[0_8px_30px_rgba(0,0,0,0.12)]">
                    <div class="flex items-center justify-between mb-5">
                        <div>
                            <h2 class="text-lg font-bold">Tendencia semanal do assistente</h2>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">Revisao operacional e aprovacoes das ultimas semanas</p>
                        </div>
                        <flux:button href="{{ route('assistant.observability') }}" wire:navigate variant="ghost" size="sm">
                            Observabilidade
                        </flux:button>
                    </div>

                    <div class="grid grid-cols-2 xl:grid-cols-4 gap-3">
                        <div class="rounded-2xl border border-zinc-200 dark:border-white/10 bg-zinc-50 dark:bg-white/[0.03] p-4">
                            <p class="text-xs uppercase tracking-[0.18em] text-zinc-500 dark:text-zinc-400">Revisoes</p>
                            <p class="mt-2 text-2xl font-black text-zinc-900 dark:text-white">{{ $assistantWeeklyUsage['review_runs'] ?? 0 }}</p>
                            <p class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400">Meta: {{ $assistantWeeklySnapshot['goals']['review_runs']['target'] ?? 0 }}</p>
                        </div>
                        <div class="rounded-2xl border border-zinc-200 dark:border-white/10 bg-zinc-50 dark:bg-white/[0.03] p-4">
                            <p class="text-xs uppercase tracking-[0.18em] text-zinc-500 dark:text-zinc-400">Syncs</p>
                            <p class="mt-2 text-2xl font-black text-zinc-900 dark:text-white">{{ $assistantWeeklyUsage['sync_runs'] ?? 0 }}</p>
                            <p class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400">Meta: {{ $assistantWeeklySnapshot['goals']['sync_runs']['target'] ?? 0 }}</p>
                        </div>
                        <div class="rounded-2xl border border-zinc-200 dark:border-white/10 bg-zinc-50 dark:bg-white/[0.03] p-4">
                            <p class="text-xs uppercase tracking-[0.18em] text-zinc-500 dark:text-zinc-400">Aprovacoes</p>
                            <p class="mt-2 text-2xl font-black text-zinc-900 dark:text-white">{{ $assistantWeeklyUsage['item_approvals'] ?? 0 }}</p>
                            <p class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400">Meta: {{ $assistantWeeklySnapshot['goals']['item_approvals']['target'] ?? 0 }}</p>
                        </div>
                        <div class="rounded-2xl border border-zinc-200 dark:border-white/10 bg-zinc-50 dark:bg-white/[0.03] p-4">
                            <p class="text-xs uppercase tracking-[0.18em] text-zinc-500 dark:text-zinc-400">Dominios</p>
                            <p class="mt-2 text-2xl font-black text-zinc-900 dark:text-white">{{ count($assistantWeeklyUsage['approved_domains'] ?? []) }}</p>
                            <p class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400">Semana atual</p>
                        </div>
                    </div>

                    @php
                        $sla = $assistantWeeklySnapshot['sla'] ?? ['status' => 'yellow', 'label' => 'SLA em atencao'];
                        $slaClass = match ($sla['status']) {
                            'green' => 'border-emerald-500/20 bg-emerald-50/70 dark:bg-emerald-500/10 text-emerald-900 dark:text-emerald-200',
                            'red' => 'border-rose-500/20 bg-rose-50/70 dark:bg-rose-500/10 text-rose-900 dark:text-rose-200',
                            default => 'border-amber-500/20 bg-amber-50/70 dark:bg-amber-500/10 text-amber-900 dark:text-amber-200',
                        };
                    @endphp

                    <div class="mt-4 rounded-2xl border {{ $slaClass }} px-4 py-4">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <p class="text-xs uppercase tracking-[0.18em]">SLA da operacao do assistente</p>
                                <p class="mt-2 text-lg font-black">{{ $sla['label'] }}</p>
                            </div>
                            <a href="{{ route('assistant.observability') }}" wire:navigate class="text-xs font-bold underline underline-offset-4">
                                Abrir observabilidade
                            </a>
                        </div>
                    </div>

                    <div class="mt-4 grid gap-3 xl:grid-cols-3">
                        @foreach (['review_runs' => 'Revisoes', 'sync_runs' => 'Syncs', 'item_approvals' => 'Aprovacoes'] as $metricKey => $metricLabel)
                            @php
                                $goal = $assistantWeeklySnapshot['goals'][$metricKey] ?? ['current' => 0, 'target' => 0, 'remaining' => 0, 'met' => false];
                                $comparison = $assistantWeeklySnapshot['comparison'][$metricKey] ?? ['delta' => 0, 'previous' => 0, 'direction' => 0];
                                $directionLabel = $comparison['direction'] > 0 ? 'acima' : ($comparison['direction'] < 0 ? 'abaixo' : 'igual');
                            @endphp
                            <div class="rounded-2xl border border-zinc-200 dark:border-white/10 bg-gradient-to-br from-zinc-50 to-white dark:from-white/[0.04] dark:to-white/[0.02] p-4">
                                <div class="flex items-center justify-between">
                                    <p class="text-sm font-bold text-zinc-900 dark:text-white">{{ $metricLabel }}</p>
                                    <span class="rounded-full px-2 py-0.5 text-[11px] font-bold {{ $goal['met'] ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' }}">
                                        {{ $goal['met'] ? 'Meta ok' : 'Faltam '.$goal['remaining'] }}
                                    </span>
                                </div>
                                <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">Comparativo com a semana passada</p>
                                <p class="mt-2 text-2xl font-black text-zinc-900 dark:text-white">
                                    {{ $comparison['delta'] >= 0 ? '+' : '' }}{{ $comparison['delta'] }}
                                </p>
                                <p class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400">
                                    {{ $directionLabel }} da semana passada ({{ $comparison['previous'] }})
                                </p>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-4 space-y-2">
                        @foreach (($assistantWeeklySnapshot['alerts'] ?? []) as $alert)
                            @php
                                $alertTone = $alert['tone'] ?? 'info';
                                $alertClass = match ($alertTone) {
                                    'warning' => 'border-amber-500/20 bg-amber-50/70 dark:bg-amber-500/10 text-amber-900 dark:text-amber-200',
                                    'ok' => 'border-emerald-500/20 bg-emerald-50/70 dark:bg-emerald-500/10 text-emerald-900 dark:text-emerald-200',
                                    default => 'border-sky-500/20 bg-sky-50/70 dark:bg-sky-500/10 text-sky-900 dark:text-sky-200',
                                };
                            @endphp
                            <div class="rounded-2xl border {{ $alertClass }} px-4 py-3">
                                <p class="text-sm font-semibold">{{ $alert['title'] ?? 'Alerta' }}</p>
                                <p class="mt-1 text-xs">{{ $alert['text'] ?? '' }}</p>
                                @if(!empty($alert['cta']['route']) && !empty($alert['cta']['label']))
                                    <a href="{{ $alert['cta']['route'] }}" wire:navigate class="mt-2 inline-flex text-xs font-bold underline underline-offset-4">
                                        {{ $alert['cta']['label'] }}
                                    </a>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-4 grid grid-cols-2 xl:grid-cols-4 gap-3">
                        @foreach (($assistantWeeklyTrend['series'] ?? []) as $week)
                            <div class="rounded-2xl border border-zinc-200 dark:border-white/10 bg-gradient-to-br from-zinc-50 to-white dark:from-white/[0.04] dark:to-white/[0.02] p-4">
                                <p class="text-xs font-semibold text-zinc-500 dark:text-zinc-400">{{ $week['label'] }}</p>
                                <p class="mt-3 text-3xl font-black text-zinc-900 dark:text-white">{{ $week['item_approvals'] }}</p>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">aprovacoes</p>
                                <div class="mt-3 flex items-center justify-between text-[11px] text-zinc-500 dark:text-zinc-400">
                                    <span>{{ $week['review_runs'] }} revisoes</span>
                                    <span>{{ $week['sync_runs'] }} syncs</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
    </div>
</x-layouts.app.sidebar>
