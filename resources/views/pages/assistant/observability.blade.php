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
                    <input type="hidden" name="focus" value="{{ $focus }}" />
                    <button type="submit" class="rounded-xl bg-cyan-400 px-4 py-2 text-sm font-semibold text-space-950 transition hover:bg-cyan-300">
                        Atualizar
                    </button>
                </form>
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
                        <a href="{{ route('assistant.observability', ['days' => $days, 'focus' => 'unknown']) }}" class="rounded-full border px-3 py-1.5 text-xs font-semibold {{ $focus === 'unknown' ? 'border-amber-300/50 bg-amber-300/15 text-amber-100' : 'border-white/10 bg-white/5 text-slate-300' }}">
                            Focar unknown
                        </a>
                        <a href="{{ route('assistant.observability', ['days' => $days, 'focus' => 'missing']) }}" class="rounded-full border px-3 py-1.5 text-xs font-semibold {{ $focus === 'missing' ? 'border-cyan-300/50 bg-cyan-300/15 text-cyan-100' : 'border-white/10 bg-white/5 text-slate-300' }}">
                            Focar missing_fields
                        </a>
                        <a href="{{ route('assistant.observability', ['days' => $days, 'focus' => 'all']) }}" class="rounded-full border px-3 py-1.5 text-xs font-semibold {{ $focus === 'all' ? 'border-white/20 bg-white/10 text-white' : 'border-white/10 bg-white/5 text-slate-300' }}">
                            Ver tudo
                        </a>
                    </div>
                </div>

                <div class="space-y-3">
                    @forelse (collect($summary['regression_backlog'])->filter(function ($item) use ($focus) {
                        return match ($focus) {
                            'unknown' => ($item['intent'] ?? null) === 'unknown',
                            'missing' => ($item['intent'] ?? null) !== 'unknown',
                            default => true,
                        };
                    }) as $item)
                        <article class="rounded-2xl border border-white/10 bg-white/5 p-4">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $item['priority'] === 'high' ? 'bg-rose-400/15 text-rose-200' : 'bg-cyan-400/15 text-cyan-200' }}">
                                    {{ $item['priority'] }}
                                </span>
                                <span class="rounded-full bg-white/5 px-2.5 py-1 text-xs text-slate-300">{{ $item['intent'] }}</span>
                                <span class="rounded-full bg-white/5 px-2.5 py-1 text-xs text-slate-400">{{ $item['count'] }}x</span>
                            </div>
                            <p class="mt-3 text-sm text-white">{{ $item['message'] }}</p>
                            <p class="mt-2 text-xs text-slate-400">{{ $item['reason'] }}</p>
                            <pre class="mt-3 overflow-x-auto rounded-xl border border-white/10 bg-space-950/80 p-3 text-xs text-slate-300">{{ json_encode($item['suggested_example'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                        </article>
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
</x-layouts.app.sidebar>
