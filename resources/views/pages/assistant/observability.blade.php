<x-layouts.app.sidebar title="Observabilidade do Assistente">
    <div class="space-y-6">
        <section class="rounded-3xl border border-white/10 bg-white/5 p-6 backdrop-blur-xl">
            <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                <div>
                    <p class="text-xs uppercase tracking-[0.24em] text-cyan-300/80">Observabilidade</p>
                    <h1 class="mt-2 text-3xl font-semibold text-white">Saude do assistente por intencao</h1>
                    <p class="mt-2 max-w-3xl text-sm text-slate-300">
                        Janela de {{ $summary['period_days'] }} dias com amostra de {{ $summary['sample_size'] }} conversas recentes.
                        Aqui a gente enxerga onde o roteamento esta funcionando e onde ainda estamos perdendo contexto.
                    </p>
                </div>

                <form method="GET" class="flex items-center gap-3 rounded-2xl border border-white/10 bg-black/20 px-4 py-3">
                    <label for="days" class="text-sm text-slate-300">Periodo</label>
                    <select id="days" name="days" class="rounded-xl border border-white/10 bg-space-950 px-3 py-2 text-sm text-white">
                        @foreach ([7, 14, 30] as $option)
                            <option value="{{ $option }}" @selected($days === $option)>{{ $option }} dias</option>
                        @endforeach
                    </select>
                    <select id="source" name="source" class="rounded-xl border border-white/10 bg-space-950 px-3 py-2 text-sm text-white">
                        @foreach ($sourceOptions as $sourceKey => $sourceLabel)
                            <option value="{{ $sourceKey }}" @selected($source === $sourceKey)>{{ $sourceLabel }}</option>
                        @endforeach
                    </select>
                    <input type="hidden" name="focus" value="{{ $focus }}" />
                    <button type="submit" class="rounded-xl bg-cyan-400 px-4 py-2 text-sm font-semibold text-space-950 transition hover:bg-cyan-300">
                        Atualizar
                    </button>
                </form>
            </div>

            <div class="mt-5 rounded-2xl border border-white/10 bg-black/20 p-4">
                <p class="text-xs uppercase tracking-[0.22em] text-slate-400">Sync de Fixtures</p>
                <p class="mt-2 text-sm text-slate-300">
                    Quando quisermos materializar o backlog em arquivos de teste por categoria, o comando ja esta pronto:
                </p>
                <pre class="mt-3 overflow-x-auto rounded-xl border border-white/10 bg-space-950/80 p-3 text-xs text-cyan-100">php artisan assistant:sync-observability-fixtures --days={{ $days }} --focus={{ $focus }}</pre>
                <pre class="mt-3 overflow-x-auto rounded-xl border border-white/10 bg-space-950/80 p-3 text-xs text-emerald-100">php artisan assistant:weekly-review --days=7 --sync</pre>
                <p class="mt-3 text-xs text-slate-400">
                    Rotina fixa recomendada: toda segunda-feira, 09:30. O scheduler do app agora pode rodar esse review automaticamente e manter a fila de fixtures sempre atualizada.
                </p>

                <div class="mt-4 flex flex-wrap gap-3">
                    <form method="POST" action="{{ route('assistant.observability.sync-fixtures') }}">
                        @csrf
                        <input type="hidden" name="days" value="{{ $days }}" />
                        <input type="hidden" name="focus" value="{{ $focus }}" />
                        <button type="submit" class="rounded-xl border border-cyan-300/40 bg-cyan-300/10 px-4 py-2 text-sm font-semibold text-cyan-100 transition hover:bg-cyan-300/20">
                            Sincronizar fixtures geradas
                        </button>
                    </form>

                    <a href="{{ route('assistant.observability.export-fixtures', ['approved' => 1, 'approved_days' => $approvedDays, 'source' => $source]) }}" class="rounded-xl border border-emerald-300/40 bg-emerald-300/10 px-4 py-2 text-sm font-semibold text-emerald-100 transition hover:bg-emerald-300/20">
                        Exportar aprovados dos ultimos {{ $approvedDays }} dias
                    </a>

                    <form method="POST" action="{{ route('assistant.observability.run-review') }}">
                        @csrf
                        <input type="hidden" name="days" value="7" />
                        <input type="hidden" name="focus" value="all" />
                        <input type="hidden" name="sync" value="1" />
                        <button type="submit" class="rounded-xl border border-fuchsia-300/40 bg-fuchsia-300/10 px-4 py-2 text-sm font-semibold text-fuchsia-100 transition hover:bg-fuchsia-300/20">
                            Rodar review agora
                        </button>
                    </form>
                </div>
            </div>
        </section>

        <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <article class="rounded-3xl border border-white/10 bg-gradient-to-br from-cyan-400/15 to-blue-500/10 p-5">
                <p class="text-sm text-slate-300">Mensagens analisadas</p>
                <p class="mt-3 text-3xl font-semibold text-white">{{ number_format($summary['totals']['total']) }}</p>
            </article>
            <article class="rounded-3xl border border-white/10 bg-gradient-to-br from-emerald-400/15 to-lime-500/10 p-5">
                <p class="text-sm text-slate-300">Taxa de sucesso</p>
                <p class="mt-3 text-3xl font-semibold text-white">{{ $summary['totals']['success_rate'] }}%</p>
            </article>
            <article class="rounded-3xl border border-white/10 bg-gradient-to-br from-amber-400/15 to-orange-500/10 p-5">
                <p class="text-sm text-slate-300">Intencoes desconhecidas</p>
                <p class="mt-3 text-3xl font-semibold text-white">{{ number_format($summary['totals']['unknowns']) }}</p>
            </article>
            <article class="rounded-3xl border border-white/10 bg-gradient-to-br from-fuchsia-400/15 to-pink-500/10 p-5">
                <p class="text-sm text-slate-300">Chamadas com IA</p>
                <p class="mt-3 text-3xl font-semibold text-white">{{ number_format($summary['totals']['used_ai']) }}</p>
            </article>
        </section>

        <section class="rounded-3xl border border-white/10 bg-black/20 p-6">
            <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <h2 class="text-xl font-semibold text-white">Uso da revisao semanal</h2>
                    <p class="mt-1 text-sm text-slate-400">Mostra se o ritual de revisao e aprovacao esta sendo usado de verdade pelo time.</p>
                </div>
                <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-2 text-sm text-slate-300">
                    Janela de {{ $weeklyReviewUsage['days'] }} dias
                    @if (($weeklyReviewUsage['source'] ?? 'all') !== 'all')
                        · {{ $sourceOptions[$weeklyReviewUsage['source']] ?? $weeklyReviewUsage['source'] }}
                    @endif
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <p class="text-sm text-slate-300">Revisoes executadas</p>
                    <p class="mt-2 text-2xl font-semibold text-white">{{ $weeklyReviewUsage['review_runs'] }}</p>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <p class="text-sm text-slate-300">Revisoes com sync</p>
                    <p class="mt-2 text-2xl font-semibold text-white">{{ $weeklyReviewUsage['synced_review_runs'] }}</p>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <p class="text-sm text-slate-300">Aprovacoes de itens</p>
                    <p class="mt-2 text-2xl font-semibold text-white">{{ $weeklyReviewUsage['item_approvals'] }}</p>
                </article>
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <p class="text-sm text-slate-300">Dominios aprovados</p>
                    <p class="mt-2 text-2xl font-semibold text-white">{{ count($weeklyReviewUsage['approved_domains']) }}</p>
                </article>
            </div>

            <div class="mt-4 grid gap-4 xl:grid-cols-2">
                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <p class="text-xs uppercase tracking-[0.22em] text-slate-400">Ultima revisao</p>
                    <p class="mt-2 text-sm text-white">{{ $weeklyReviewUsage['last_review_run_at'] ?? 'Ainda nao registrada' }}</p>
                    <p class="mt-3 text-xs uppercase tracking-[0.22em] text-slate-400">Ultima aprovacao</p>
                    <p class="mt-2 text-sm text-white">{{ $weeklyReviewUsage['last_approval_at'] ?? 'Ainda nao registrada' }}</p>
                </article>

                <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <p class="text-xs uppercase tracking-[0.22em] text-slate-400">Aprovacoes por dominio</p>
                    <div class="mt-3 space-y-2">
                        @forelse ($weeklyReviewUsage['approvals_by_domain'] as $domainUsage)
                            <div class="flex items-center justify-between rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-sm">
                                <span class="text-white">{{ $domainUsage['domain'] }}</span>
                                <span class="text-slate-300">{{ $domainUsage['count'] }} itens</span>
                            </div>
                        @empty
                            <p class="text-sm text-slate-400">Ainda nao temos aprovacoes registradas nessa janela.</p>
                        @endforelse
                    </div>
                </article>
            </div>

            <div class="mt-4 grid gap-4 xl:grid-cols-3">
                @foreach (['review_runs' => 'Revisoes', 'sync_runs' => 'Syncs', 'item_approvals' => 'Aprovacoes'] as $metricKey => $metricLabel)
                    @php
                        $goal = $weeklyOperationalSnapshot['goals'][$metricKey] ?? ['current' => 0, 'target' => 0, 'remaining' => 0, 'met' => false];
                        $comparison = $weeklyOperationalSnapshot['comparison'][$metricKey] ?? ['delta' => 0, 'previous' => 0, 'direction' => 0];
                        $directionLabel = $comparison['direction'] > 0 ? 'acima' : ($comparison['direction'] < 0 ? 'abaixo' : 'igual');
                    @endphp
                    <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                        <div class="flex items-center justify-between">
                            <p class="text-sm font-semibold text-white">{{ $metricLabel }}</p>
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $goal['met'] ? 'bg-emerald-400/15 text-emerald-200' : 'bg-amber-400/15 text-amber-200' }}">
                                {{ $goal['met'] ? 'Meta ok' : 'Faltam '.$goal['remaining'] }}
                            </span>
                        </div>
                        <p class="mt-3 text-2xl font-semibold text-white">{{ $goal['current'] }}/{{ $goal['target'] }}</p>
                        <p class="mt-1 text-xs text-slate-400">
                            {{ $comparison['delta'] >= 0 ? '+' : '' }}{{ $comparison['delta'] }} {{ $directionLabel }} da semana passada ({{ $comparison['previous'] }})
                        </p>
                    </article>
                @endforeach
            </div>

            @php
                $sla = $weeklyOperationalSnapshot['sla'] ?? ['status' => 'yellow', 'label' => 'SLA em atencao'];
                $slaClass = match ($sla['status']) {
                    'green' => 'border-emerald-300/30 bg-emerald-300/10 text-emerald-100',
                    'red' => 'border-rose-300/30 bg-rose-300/10 text-rose-100',
                    default => 'border-amber-300/30 bg-amber-300/10 text-amber-100',
                };
            @endphp

            <div class="mt-4 rounded-2xl border {{ $slaClass }} px-4 py-4">
                <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div>
                        <p class="text-xs uppercase tracking-[0.22em]">SLA da operacao do assistente</p>
                        <p class="mt-2 text-lg font-semibold">{{ $sla['label'] }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2 text-xs">
                        <span class="rounded-full border border-current/20 px-2.5 py-1">score {{ $sla['score'] }}/4</span>
                        <span class="rounded-full border border-current/20 px-2.5 py-1">{{ $sla['status'] }}</span>
                    </div>
                </div>
            </div>

            <div class="mt-4 space-y-2">
                @foreach (($weeklyOperationalSnapshot['alerts'] ?? []) as $alert)
                    @php
                        $alertClass = match ($alert['tone'] ?? 'info') {
                            'warning' => 'border-amber-300/30 bg-amber-300/10 text-amber-100',
                            'ok' => 'border-emerald-300/30 bg-emerald-300/10 text-emerald-100',
                            default => 'border-cyan-300/30 bg-cyan-300/10 text-cyan-100',
                        };
                    @endphp
                    <article class="rounded-2xl border {{ $alertClass }} px-4 py-3">
                        <p class="text-sm font-semibold">{{ $alert['title'] ?? 'Alerta' }}</p>
                        <p class="mt-1 text-xs">{{ $alert['text'] ?? '' }}</p>
                        @if(!empty($alert['cta']['route']) && !empty($alert['cta']['label']))
                            <a href="{{ $alert['cta']['route'] }}" class="mt-2 inline-flex text-xs font-semibold underline underline-offset-4">
                                {{ $alert['cta']['label'] }}
                            </a>
                        @endif
                    </article>
                @endforeach
            </div>

            <div class="mt-4 rounded-2xl border border-white/10 bg-white/5 p-4">
                <div class="flex items-center justify-between">
                    <p class="text-xs uppercase tracking-[0.22em] text-slate-400">Tendencia semanal</p>
                    <p class="text-xs text-slate-500">Ultimas {{ $weeklyReviewTrend['weeks'] }} semanas</p>
                </div>
                <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-6">
                    @foreach ($weeklyReviewTrend['series'] as $week)
                        <div class="rounded-2xl border border-white/10 bg-black/20 p-3">
                            <p class="text-xs font-semibold text-slate-400">{{ $week['label'] }}</p>
                            <p class="mt-3 text-2xl font-semibold text-white">{{ $week['item_approvals'] }}</p>
                            <p class="text-xs text-slate-400">aprovacoes</p>
                            <div class="mt-3 space-y-1 text-xs text-slate-400">
                                <p>{{ $week['review_runs'] }} revisoes</p>
                                <p>{{ $week['sync_runs'] }} syncs</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="rounded-3xl border border-white/10 bg-black/20 p-6">
            <div class="mb-4">
                <h2 class="text-xl font-semibold text-white">Quebras por intencao</h2>
                <p class="mt-1 text-sm text-slate-400">Acompanha volume, taxa de erro, confianca media e os `missing_fields` que mais aparecem.</p>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-white/10 text-sm">
                    <thead>
                        <tr class="text-left text-slate-400">
                            <th class="px-3 py-3 font-medium">Intencao</th>
                            <th class="px-3 py-3 font-medium">Total</th>
                            <th class="px-3 py-3 font-medium">Sucesso</th>
                            <th class="px-3 py-3 font-medium">Erros</th>
                            <th class="px-3 py-3 font-medium">IA</th>
                            <th class="px-3 py-3 font-medium">Confianca</th>
                            <th class="px-3 py-3 font-medium">Campos pendentes</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5 text-slate-200">
                        @forelse ($summary['by_intent'] as $row)
                            <tr>
                                <td class="px-3 py-4 font-medium text-white">{{ $row['intent'] }}</td>
                                <td class="px-3 py-4">{{ $row['total'] }}</td>
                                <td class="px-3 py-4">{{ $row['success_rate'] }}%</td>
                                <td class="px-3 py-4">{{ $row['errors'] }}</td>
                                <td class="px-3 py-4">{{ $row['used_ai'] }}</td>
                                <td class="px-3 py-4">{{ $row['avg_confidence'] }}</td>
                                <td class="px-3 py-4">
                                    @if ($row['top_missing_fields'] === [])
                                        <span class="text-slate-500">-</span>
                                    @else
                                        <div class="flex flex-wrap gap-2">
                                            @foreach ($row['top_missing_fields'] as $field => $count)
                                                <span class="rounded-full border border-cyan-400/20 bg-cyan-400/10 px-2.5 py-1 text-xs text-cyan-200">
                                                    {{ $field }} ({{ $count }})
                                                </span>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-3 py-6 text-center text-slate-400">Ainda nao temos dados suficientes para esta janela.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <div class="grid gap-6 xl:grid-cols-2">
            <section class="rounded-3xl border border-white/10 bg-black/20 p-6 xl:col-span-2">
                <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h2 class="text-xl font-semibold text-white">Fila priorizada de regressao</h2>
                        <p class="mt-1 text-sm text-slate-400">Transforma `unknown` e `missing_fields` recorrentes em candidatos reais para fixtures e testes.</p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <a href="{{ route('assistant.observability.export-fixtures', ['days' => $days, 'focus' => $focus]) }}" class="rounded-full border border-emerald-300/40 bg-emerald-300/10 px-3 py-1.5 text-xs font-semibold text-emerald-100">
                            Exportar fixtures
                        </a>
                        <a href="{{ route('assistant.observability', ['days' => $days, 'focus' => 'unknown', 'source' => $source]) }}" class="rounded-full border px-3 py-1.5 text-xs font-semibold {{ $focus === 'unknown' ? 'border-amber-300/50 bg-amber-300/15 text-amber-100' : 'border-white/10 bg-white/5 text-slate-300' }}">
                            Focar unknown
                        </a>
                        <a href="{{ route('assistant.observability', ['days' => $days, 'focus' => 'missing', 'source' => $source]) }}" class="rounded-full border px-3 py-1.5 text-xs font-semibold {{ $focus === 'missing' ? 'border-cyan-300/50 bg-cyan-300/15 text-cyan-100' : 'border-white/10 bg-white/5 text-slate-300' }}">
                            Focar missing_fields
                        </a>
                        <a href="{{ route('assistant.observability', ['days' => $days, 'focus' => 'all', 'source' => $source]) }}" class="rounded-full border px-3 py-1.5 text-xs font-semibold {{ $focus === 'all' ? 'border-white/20 bg-white/10 text-white' : 'border-white/10 bg-white/5 text-slate-300' }}">
                            Ver tudo
                        </a>
                    </div>
                </div>

                <div class="space-y-5">
                    @php
                        $filteredBacklog = collect($summary['regression_backlog'])->filter(function ($item) use ($focus) {
                            return match ($focus) {
                                'unknown' => ($item['intent'] ?? null) === 'unknown',
                                'missing' => ($item['intent'] ?? null) !== 'unknown',
                                default => true,
                            };
                        })->groupBy(fn ($item) => $item['domain'] ?? 'unknown')->sortKeys();
                    @endphp

                    @forelse ($filteredBacklog as $domain => $items)
                        <section class="rounded-2xl border border-white/10 bg-white/5 p-4">
                            <div class="flex flex-col gap-3 border-b border-white/10 pb-4 md:flex-row md:items-center md:justify-between">
                                <div>
                                    <p class="text-xs uppercase tracking-[0.22em] text-slate-400">Dominio</p>
                                    <h3 class="mt-1 text-lg font-semibold text-white">{{ $domain }}</h3>
                                </div>

                                <div class="flex flex-wrap gap-2">
                                    <a href="{{ route('assistant.observability.export-fixtures', ['days' => $days, 'focus' => $focus, 'domain' => $domain]) }}" class="rounded-full border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-semibold text-slate-200">
                                        Baixar fixture
                                    </a>
                                    <a href="{{ route('assistant.observability.export-fixtures', ['approved' => 1, 'approved_days' => $approvedDays, 'domain' => $domain, 'source' => $source]) }}" class="rounded-full border border-emerald-300/40 bg-emerald-300/10 px-3 py-1.5 text-xs font-semibold text-emerald-100">
                                        Aprovados da semana
                                    </a>
                                    <a href="{{ route('assistant.observability', ['days' => $days, 'focus' => $focus, 'preview_domain' => $domain, 'source' => $source]) }}" class="rounded-full border border-fuchsia-300/40 bg-fuchsia-300/10 px-3 py-1.5 text-xs font-semibold text-fuchsia-100">
                                        Ver preview/diff
                                    </a>
                                    <form method="POST" action="{{ route('assistant.observability.sync-fixtures') }}">
                                        @csrf
                                        <input type="hidden" name="days" value="{{ $days }}" />
                                        <input type="hidden" name="focus" value="{{ $focus }}" />
                                        <input type="hidden" name="domain" value="{{ $domain }}" />
                                        <button type="submit" class="rounded-full border border-emerald-300/40 bg-emerald-300/10 px-3 py-1.5 text-xs font-semibold text-emerald-100">
                                            Sincronizar dominio
                                        </button>
                                    </form>
                                    <button
                                        type="button"
                                        data-copy-fixture
                                        data-url="{{ route('assistant.observability.export-fixtures', ['days' => $days, 'focus' => $focus, 'domain' => $domain]) }}"
                                        class="rounded-full border border-cyan-300/40 bg-cyan-300/10 px-3 py-1.5 text-xs font-semibold text-cyan-100"
                                    >
                                        Copiar para fixture
                                    </button>
                                </div>
                            </div>

                            <div class="mt-4 space-y-3">
                                @foreach ($items as $item)
                                    <article class="rounded-2xl border {{ ($previewItemKey ?? null) === ($item['key'] ?? null) ? 'border-fuchsia-300/40 bg-fuchsia-300/5' : 'border-white/10 bg-black/20' }} p-4">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $item['priority'] === 'high' ? 'bg-rose-400/15 text-rose-200' : 'bg-cyan-400/15 text-cyan-200' }}">
                                                {{ $item['priority'] }}
                                            </span>
                                            <span class="rounded-full bg-white/5 px-2.5 py-1 text-xs text-slate-300">{{ $item['intent'] }}</span>
                                            <span class="rounded-full bg-white/5 px-2.5 py-1 text-xs text-slate-400">{{ $item['count'] }}x</span>
                                            <span class="rounded-full bg-white/5 px-2.5 py-1 text-xs text-slate-500">item {{ \Illuminate\Support\Str::limit($item['key'] ?? '', 10, '') }}</span>
                                        </div>
                                        <p class="mt-3 text-sm text-white">{{ $item['message'] }}</p>
                                        <p class="mt-2 text-xs text-slate-400">{{ $item['reason'] }}</p>
                                        <pre class="mt-3 overflow-x-auto rounded-xl border border-white/10 bg-space-950/80 p-3 text-xs text-slate-300">{{ json_encode($item['suggested_example'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>

                                        <div class="mt-3 flex flex-wrap gap-2">
                                            <a href="{{ route('assistant.observability', ['days' => $days, 'focus' => $focus, 'preview_domain' => $domain, 'preview_item' => $item['key'], 'source' => $source]) }}" class="rounded-full border border-fuchsia-300/40 bg-fuchsia-300/10 px-3 py-1.5 text-xs font-semibold text-fuchsia-100">
                                                Ver item
                                            </a>
                                            <form method="POST" action="{{ route('assistant.observability.sync-fixtures') }}">
                                                @csrf
                                                <input type="hidden" name="days" value="{{ $days }}" />
                                                <input type="hidden" name="focus" value="{{ $focus }}" />
                                                <input type="hidden" name="item_key" value="{{ $item['key'] }}" />
                                                <button type="submit" class="rounded-full border border-emerald-300/40 bg-emerald-300/10 px-3 py-1.5 text-xs font-semibold text-emerald-100">
                                                    Aprovar item
                                                </button>
                                            </form>
                                            <button
                                                type="button"
                                                data-copy-fixture
                                                data-url="{{ route('assistant.observability.export-fixtures', ['days' => $days, 'focus' => $focus, 'item_key' => $item['key']]) }}"
                                                class="rounded-full border border-cyan-300/40 bg-cyan-300/10 px-3 py-1.5 text-xs font-semibold text-cyan-100"
                                            >
                                                Copiar item
                                            </button>
                                        </div>

                                        @if (($previewItemKey ?? null) === ($item['key'] ?? null) && $fixtureItemPreview)
                                            <div class="mt-4 rounded-2xl border border-fuchsia-300/20 bg-fuchsia-300/5 p-4">
                                                <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                                                    <div>
                                                        <p class="text-xs uppercase tracking-[0.22em] text-fuchsia-200/80">Preview seletivo por item</p>
                                                        <p class="mt-1 text-sm text-slate-300">{{ $fixtureItemPreview['path'] }}</p>
                                                    </div>
                                                    <div class="flex flex-wrap gap-2 text-xs">
                                                        <span class="rounded-full border border-white/10 bg-white/5 px-2.5 py-1 text-slate-200">
                                                            {{ $fixtureItemPreview['exists'] ? 'arquivo existente' : 'arquivo novo' }}
                                                        </span>
                                                        <span class="rounded-full border border-white/10 bg-white/5 px-2.5 py-1 text-slate-200">
                                                            {{ $fixtureItemPreview['has_changes'] ? 'com alteracoes' : 'sem alteracoes' }}
                                                        </span>
                                                    </div>
                                                </div>

                                                <div class="mt-4 grid gap-4 xl:grid-cols-2">
                                                    <div>
                                                        <p class="mb-2 text-xs uppercase tracking-[0.22em] text-slate-400">Atual</p>
                                                        <pre class="overflow-x-auto rounded-xl border border-white/10 bg-space-950/80 p-3 text-xs text-slate-300">{{ $fixtureItemPreview['current_content'] ?? 'Arquivo ainda nao existe.' }}</pre>
                                                    </div>
                                                    <div>
                                                        <p class="mb-2 text-xs uppercase tracking-[0.22em] text-slate-400">Gerado com esse item</p>
                                                        <pre class="overflow-x-auto rounded-xl border border-white/10 bg-space-950/80 p-3 text-xs text-cyan-100">{{ $fixtureItemPreview['generated_content'] }}</pre>
                                                    </div>
                                                </div>

                                                <div class="mt-4">
                                                    <p class="mb-2 text-xs uppercase tracking-[0.22em] text-slate-400">Diff</p>
                                                    <pre class="overflow-x-auto rounded-xl border border-white/10 bg-space-950/80 p-3 text-xs text-emerald-100">{{ $fixtureItemPreview['diff'] }}</pre>
                                                </div>
                                            </div>
                                        @endif
                                    </article>
                                @endforeach
                            </div>

                            @if (($previewDomain ?? null) === $domain && $fixturePreview)
                                <div class="mt-4 rounded-2xl border border-fuchsia-300/20 bg-fuchsia-300/5 p-4">
                                    <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                                        <div>
                                            <p class="text-xs uppercase tracking-[0.22em] text-fuchsia-200/80">Preview do fixture</p>
                                            <p class="mt-1 text-sm text-slate-300">{{ $fixturePreview['path'] }}</p>
                                        </div>
                                        <div class="flex flex-wrap gap-2 text-xs">
                                            <span class="rounded-full border border-white/10 bg-white/5 px-2.5 py-1 text-slate-200">
                                                {{ $fixturePreview['exists'] ? 'arquivo existente' : 'arquivo novo' }}
                                            </span>
                                            <span class="rounded-full border border-white/10 bg-white/5 px-2.5 py-1 text-slate-200">
                                                {{ $fixturePreview['has_changes'] ? 'com alteracoes' : 'sem alteracoes' }}
                                            </span>
                                            <span class="rounded-full border border-white/10 bg-white/5 px-2.5 py-1 text-slate-200">
                                                {{ $fixturePreview['has_backlog'] ? 'com backlog' : 'sem backlog elegivel' }}
                                            </span>
                                        </div>
                                    </div>

                                    <div class="mt-4 grid gap-4 xl:grid-cols-2">
                                        <div>
                                            <p class="mb-2 text-xs uppercase tracking-[0.22em] text-slate-400">Atual</p>
                                            <pre class="overflow-x-auto rounded-xl border border-white/10 bg-space-950/80 p-3 text-xs text-slate-300">{{ $fixturePreview['current_content'] ?? 'Arquivo ainda nao existe.' }}</pre>
                                        </div>
                                        <div>
                                            <p class="mb-2 text-xs uppercase tracking-[0.22em] text-slate-400">Gerado</p>
                                            <pre class="overflow-x-auto rounded-xl border border-white/10 bg-space-950/80 p-3 text-xs text-cyan-100">{{ $fixturePreview['generated_content'] }}</pre>
                                        </div>
                                    </div>

                                    <div class="mt-4">
                                        <p class="mb-2 text-xs uppercase tracking-[0.22em] text-slate-400">Diff</p>
                                        <pre class="overflow-x-auto rounded-xl border border-white/10 bg-space-950/80 p-3 text-xs text-emerald-100">{{ $fixturePreview['diff'] }}</pre>
                                    </div>
                                </div>
                            @endif
                        </section>
                    @empty
                        <p class="text-sm text-slate-400">Nenhum candidato de regressao encontrado nesta janela.</p>
                    @endforelse
                </div>
            </section>

            <section class="rounded-3xl border border-white/10 bg-black/20 p-6">
                <div class="mb-4">
                    <h2 class="text-xl font-semibold text-white">Mensagens desconhecidas mais frequentes</h2>
                    <p class="mt-1 text-sm text-slate-400">Bom lugar para ampliar o catalogo e os exemplos de regressao.</p>
                </div>

                <div class="space-y-3">
                    @forelse ($summary['top_unknown_messages'] as $row)
                        <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                            <div class="flex items-start justify-between gap-4">
                                <p class="text-sm text-slate-200">{{ $row['message'] }}</p>
                                <span class="rounded-full bg-amber-400/15 px-2.5 py-1 text-xs font-semibold text-amber-200">{{ $row['count'] }}x</span>
                            </div>
                            <p class="mt-2 text-xs text-slate-500">Ultima ocorrencia: {{ $row['last_seen_at'] }}</p>
                        </article>
                    @empty
                        <p class="text-sm text-slate-400">Nenhuma mensagem desconhecida nessa janela.</p>
                    @endforelse
                </div>
            </section>

            <section class="rounded-3xl border border-white/10 bg-black/20 p-6">
                <div class="mb-4">
                    <h2 class="text-xl font-semibold text-white">Falhas recentes</h2>
                    <p class="mt-1 text-sm text-slate-400">Une erros reais e interacoes que ainda caem como `unknown`.</p>
                </div>

                <div class="space-y-3">
                    @forelse ($summary['recent_failures'] as $row)
                        <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full bg-rose-400/15 px-2.5 py-1 text-xs font-semibold text-rose-200">{{ $row['status'] }}</span>
                                <span class="rounded-full bg-white/5 px-2.5 py-1 text-xs text-slate-300">{{ $row['assistant_intent'] }}</span>
                                @if ($row['used_ai'])
                                    <span class="rounded-full bg-fuchsia-400/15 px-2.5 py-1 text-xs text-fuchsia-200">usou IA</span>
                                @endif
                            </div>
                            <p class="mt-3 text-sm text-white">{{ $row['message'] }}</p>
                            @if ($row['error_message'])
                                <p class="mt-2 text-xs text-rose-200">{{ $row['error_message'] }}</p>
                            @endif
                            <p class="mt-2 text-xs text-slate-500">Em {{ $row['created_at'] }}</p>
                        </article>
                    @empty
                        <p class="text-sm text-slate-400">Nenhuma falha recente nessa janela.</p>
                    @endforelse
                </div>
            </section>
        </div>
    </div>

    @push('scripts')
        <script>
            document.addEventListener('click', async (event) => {
                const button = event.target.closest('[data-copy-fixture]');
                if (!button) {
                    return;
                }

                const original = button.textContent;

                try {
                    const response = await fetch(button.dataset.url, {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });

                    const text = await response.text();
                    await navigator.clipboard.writeText(text);
                    button.textContent = 'Fixture copiada';
                } catch (error) {
                    button.textContent = 'Falhou ao copiar';
                } finally {
                    setTimeout(() => {
                        button.textContent = original;
                    }, 1800);
                }
            });
        </script>
    @endpush
</x-layouts.app.sidebar>
