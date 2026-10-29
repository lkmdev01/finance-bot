<x-layouts.app.sidebar title="Uso de IA">
    <main class="mx-auto max-w-6xl space-y-6" data-ai-token-usage>
        <header>
            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-cyan-300">Assistente</p>
            <h1 class="mt-2 text-3xl font-bold text-white">Uso de IA</h1>
            <p class="mt-2 text-sm text-slate-300">Consumo por agente em {{ \Carbon\CarbonImmutable::createFromFormat('Y-m', $period)->locale('pt_BR')->translatedFormat('F \d\e Y') }}.</p>
        </header>

        @if ($hourlyThreshold > 0 && $hourlyTotal >= $hourlyThreshold)
            <section role="status" class="rounded-2xl border border-amber-300/30 bg-amber-400/10 p-4 text-amber-100">
                O consumo chegou a {{ number_format($hourlyTotal, 0, ',', '.') }} tokens nesta hora, acima do alerta configurado de {{ number_format($hourlyThreshold, 0, ',', '.') }}.
            </section>
        @endif

        @if ($recentSpikeAlerts->isNotEmpty())
            <section aria-labelledby="ai-usage-alerts-title" class="space-y-3">
                <h2 id="ai-usage-alerts-title" class="font-semibold text-amber-100">Alertas recentes de consumo</h2>
                @foreach ($recentSpikeAlerts as $alert)
                    <article class="rounded-2xl border border-amber-300/30 bg-amber-400/10 p-4 text-sm text-amber-100">
                        <p>{{ $alert->data['message'] ?? 'O consumo de IA ultrapassou o alerta horário configurado.' }}</p>
                        <p class="mt-1 text-xs text-amber-100/70">{{ $alert->created_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</p>
                    </article>
                @endforeach
            </section>
        @endif

        <section class="grid gap-4 sm:grid-cols-2">
            <article class="rounded-3xl border border-white/10 bg-white/5 p-5">
                <p class="text-sm text-slate-400">Total contabilizado no mês</p>
                <p class="mt-2 text-3xl font-bold text-white">{{ number_format($monthlyTotal, 0, ',', '.') }}</p>
                @if ($isExempt)
                    <p class="mt-2 text-xs text-emerald-200">Sua conta está isenta do teto mensal interno.</p>
                @elseif ($monthlyLimit > 0)
                    <p class="mt-2 text-xs text-slate-400">Limite: {{ number_format($monthlyLimit, 0, ',', '.') }} tokens.</p>
                @else
                    <p class="mt-2 text-xs text-amber-200">O teto mensal interno está desativado para todos os usuários.</p>
                @endif
            </article>
            <article class="rounded-3xl border border-white/10 bg-white/5 p-5">
                <p class="text-sm text-slate-400">Uso nesta hora</p>
                <p class="mt-2 text-3xl font-bold text-white">{{ number_format($hourlyTotal, 0, ',', '.') }}</p>
                <p class="mt-2 text-xs text-slate-400">Alerta configurado: {{ $hourlyThreshold > 0 ? number_format($hourlyThreshold, 0, ',', '.') : 'desativado' }} tokens por hora.</p>
            </article>
        </section>

        <section class="overflow-hidden rounded-3xl border border-white/10 bg-white/5">
            <div class="border-b border-white/10 px-5 py-4">
                <h2 class="font-semibold text-white">Detalhamento por agente</h2>
                <p class="mt-1 text-xs text-slate-400">Registros detalhados são mantidos por {{ $retentionDays }} dias. Os totais mensais permanecem disponíveis.</p>
            </div>
            @if ($agentUsage->isEmpty())
                <p class="p-5 text-sm text-slate-300">Ainda não há registros detalhados neste mês.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[36rem] text-left text-sm">
                        <thead class="bg-black/20 text-xs uppercase tracking-wide text-slate-400">
                            <tr><th class="px-5 py-3">Agente</th><th class="px-5 py-3 text-right">Entrada</th><th class="px-5 py-3 text-right">Saída</th><th class="px-5 py-3 text-right">Total</th></tr>
                        </thead>
                        <tbody class="divide-y divide-white/5 text-slate-200">
                            @foreach ($agentUsage as $row)
                                @php
                                    $labels = ['financial' => 'Financeiro', 'agenda' => 'Agenda', 'tasks' => 'Tarefas', 'drive-metadata' => 'Drive', 'meetings' => 'Reuniões', 'general' => 'Geral'];
                                @endphp
                                <tr>
                                    <th scope="row" class="px-5 py-3 font-medium">{{ $labels[$row->agent_key] ?? \Illuminate\Support\Str::headline($row->agent_key) }}</th>
                                    <td class="px-5 py-3 text-right">{{ number_format((int) $row->input_tokens, 0, ',', '.') }}</td>
                                    <td class="px-5 py-3 text-right">{{ number_format((int) $row->output_tokens, 0, ',', '.') }}</td>
                                    <td class="px-5 py-3 text-right font-semibold">{{ number_format((int) $row->total_tokens, 0, ',', '.') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </main>
</x-layouts.app.sidebar>
