<x-layouts.app>
    <x-slot name="title">Monitoramento</x-slot>

    <div class="space-y-6 p-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-sky-500 dark:text-sky-300">Operação</p>
                <h1 class="mt-2 text-3xl font-black tracking-tight text-zinc-900 dark:text-white">Monitoramento do Bot</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-zinc-500 dark:text-zinc-400">
                    Visão geral do processamento do WhatsApp, saúde da IA e histórico recente de mensagens tratadas pelo sistema.
                </p>
            </div>

            <div class="rounded-3xl border border-sky-900/40 bg-slate-950 px-5 py-4 text-slate-100 shadow-[0_24px_80px_rgba(2,6,23,0.34)]">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-sky-300">Ritmo atual</p>
                <p class="mt-2 text-2xl font-black">{{ number_format($metrics->getAverageResponseTime(), 0) }}ms</p>
                <p class="mt-1 text-xs text-slate-400">Tempo médio de resposta da IA</p>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="relative overflow-hidden rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm transition-all hover:shadow-md dark:border-white/10 dark:bg-[#07111f]">
                <div class="absolute -right-6 -top-6 h-24 w-24 rounded-full bg-emerald-500/12 blur-2xl"></div>
                <div class="mb-4 flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-emerald-100 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <p class="text-sm font-semibold text-zinc-500 dark:text-zinc-400">Status WhatsApp</p>
                </div>
                <p class="text-2xl font-black tracking-tight text-zinc-900 dark:text-white">Conectado</p>
                <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">Pronto para receber e responder mensagens.</p>
            </div>

            <div class="relative overflow-hidden rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm transition-all hover:shadow-md dark:border-white/10 dark:bg-[#07111f]">
                <div class="absolute -right-6 -top-6 h-24 w-24 rounded-full bg-sky-500/12 blur-2xl"></div>
                <div class="mb-4 flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-sky-100 text-sky-600 dark:bg-sky-500/10 dark:text-sky-300">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                    </div>
                    <p class="text-sm font-semibold text-zinc-500 dark:text-zinc-400">Tempo Médio IA</p>
                </div>
                <p class="text-2xl font-black tracking-tight text-zinc-900 dark:text-white">{{ number_format($metrics->getAverageResponseTime(), 0) }}ms</p>
                <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">Latência média das respostas geradas.</p>
            </div>

            <div class="relative overflow-hidden rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm transition-all hover:shadow-md dark:border-white/10 dark:bg-[#07111f]">
                <div class="absolute -right-6 -top-6 h-24 w-24 rounded-full bg-violet-500/12 blur-2xl"></div>
                <div class="mb-4 flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-violet-100 text-violet-600 dark:bg-violet-500/10 dark:text-violet-300">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                        </svg>
                    </div>
                    <p class="text-sm font-semibold text-zinc-500 dark:text-zinc-400">Taxa de Sucesso</p>
                </div>
                <p class="text-2xl font-black tracking-tight text-zinc-900 dark:text-white">{{ number_format($metrics->getSuccessRate() * 100, 1) }}%</p>
                <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">Mensagens que completaram o fluxo esperado.</p>
            </div>

            <div class="relative overflow-hidden rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm transition-all hover:shadow-md dark:border-white/10 dark:bg-[#07111f]">
                <div class="absolute -right-6 -top-6 h-24 w-24 rounded-full bg-rose-500/12 blur-2xl"></div>
                <div class="mb-4 flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-rose-100 text-rose-600 dark:bg-rose-500/10 dark:text-rose-300">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                    <p class="text-sm font-semibold text-zinc-500 dark:text-zinc-400">Erros (24h)</p>
                </div>
                <p class="text-2xl font-black tracking-tight text-zinc-900 dark:text-white">{{ $metrics->getErrorCount() }}</p>
                <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">Ocorrências registradas nas últimas 24 horas.</p>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-[0.9fr_1.1fr]">
            <div class="space-y-6">
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-[#07111f]">
                    <h2 class="text-lg font-black text-zinc-900 dark:text-white">Volume de Mensagens</h2>
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Panorama rápido da atividade recente.</p>

                    <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 dark:border-white/10 dark:bg-white/[0.03]">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-zinc-500 dark:text-zinc-400">Hoje</p>
                            <p class="mt-3 text-2xl font-black text-zinc-900 dark:text-white">{{ $messagesToday }}</p>
                        </div>
                        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 dark:border-white/10 dark:bg-white/[0.03]">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-zinc-500 dark:text-zinc-400">Semana</p>
                            <p class="mt-3 text-2xl font-black text-zinc-900 dark:text-white">{{ $messagesThisWeek }}</p>
                        </div>
                        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 dark:border-white/10 dark:bg-white/[0.03]">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-zinc-500 dark:text-zinc-400">Contatos</p>
                            <p class="mt-3 text-2xl font-black text-zinc-900 dark:text-white">{{ $totalContacts }}</p>
                        </div>
                    </div>
                </div>

                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-[#07111f]">
                    <h2 class="text-lg font-black text-zinc-900 dark:text-white">Saúde da IA</h2>
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Indicadores principais do pipeline de resposta.</p>

                    <dl class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 dark:border-white/10 dark:bg-white/[0.03]">
                            <dt class="text-xs font-semibold uppercase tracking-[0.18em] text-zinc-500 dark:text-zinc-400">Tempo Médio</dt>
                            <dd class="mt-3 text-2xl font-black text-zinc-900 dark:text-white">{{ number_format($metrics->getAverageResponseTime(), 0) }}ms</dd>
                        </div>
                        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 dark:border-white/10 dark:bg-white/[0.03]">
                            <dt class="text-xs font-semibold uppercase tracking-[0.18em] text-zinc-500 dark:text-zinc-400">Sucesso</dt>
                            <dd class="mt-3 text-2xl font-black text-zinc-900 dark:text-white">{{ number_format($metrics->getSuccessRate() * 100, 1) }}%</dd>
                        </div>
                        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 dark:border-white/10 dark:bg-white/[0.03]">
                            <dt class="text-xs font-semibold uppercase tracking-[0.18em] text-zinc-500 dark:text-zinc-400">Requisições</dt>
                            <dd class="mt-3 text-2xl font-black text-zinc-900 dark:text-white">{{ $metrics->getTotalRequests() }}</dd>
                        </div>
                    </dl>
                </div>

                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-[#07111f]">
                    <h2 class="text-lg font-black text-zinc-900 dark:text-white">Erros Recentes</h2>
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Últimos registros úteis para investigação.</p>

                    <div class="mt-5 flow-root">
                        <ul class="space-y-4">
                            @forelse($recentErrors as $log)
                                <li class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 dark:border-white/10 dark:bg-white/[0.03]">
                                    <div class="flex items-start gap-3">
                                        <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-2xl bg-rose-500/10 text-rose-500 dark:text-rose-300">
                                            <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                                            </svg>
                                        </span>
                                        <div class="flex-1">
                                            <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ $log->metadata['error_type'] ?? 'Erro desconhecido' }}</p>
                                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $log->created_at->diffForHumans() }}</p>
                                        </div>
                                    </div>
                                </li>
                            @empty
                                <li class="rounded-2xl border border-emerald-500/20 bg-emerald-500/10 px-4 py-5 text-sm text-emerald-700 dark:text-emerald-200">
                                    Nenhum erro recente.
                                </li>
                            @endforelse
                        </ul>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-[#07111f]">
                <div class="mb-5 flex items-center justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-black text-zinc-900 dark:text-white">Observação do Bot</h2>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Últimas mensagens processadas com classificação, ação e handler.</p>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-white/10">
                        <thead>
                            <tr class="text-left text-zinc-500 dark:text-zinc-400">
                                <th class="py-3 pr-4 text-xs font-semibold uppercase tracking-[0.18em]">Quando</th>
                                <th class="py-3 pr-4 text-xs font-semibold uppercase tracking-[0.18em]">Status</th>
                                <th class="py-3 pr-4 text-xs font-semibold uppercase tracking-[0.18em]">Classificação</th>
                                <th class="py-3 pr-4 text-xs font-semibold uppercase tracking-[0.18em]">Ação</th>
                                <th class="py-3 pr-4 text-xs font-semibold uppercase tracking-[0.18em]">Handler</th>
                                <th class="py-3 pr-4 text-xs font-semibold uppercase tracking-[0.18em]">Mensagem</th>
                                <th class="py-3 pr-4 text-xs font-semibold uppercase tracking-[0.18em]">Resposta</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-white/10">
                            @forelse($recentConversationLogs as $entry)
                                <tr class="align-top transition hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                                    <td class="whitespace-nowrap py-4 pr-4 text-zinc-600 dark:text-zinc-300">{{ $entry->created_at->format('d/m H:i:s') }}</td>
                                    <td class="py-4 pr-4">
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $entry->status === 'error' ? 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-200' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200' }}">
                                            {{ $entry->status }}
                                        </span>
                                    </td>
                                    <td class="py-4 pr-4 text-zinc-700 dark:text-zinc-200">{{ $entry->classification ?? '—' }}</td>
                                    <td class="py-4 pr-4 text-zinc-700 dark:text-zinc-200">{{ $entry->action ?? '—' }}</td>
                                    <td class="py-4 pr-4 text-zinc-600 dark:text-zinc-300">{{ $entry->handler ? class_basename($entry->handler) : '—' }}</td>
                                    <td class="max-w-sm py-4 pr-4 text-zinc-700 dark:text-zinc-200">
                                        <div class="line-clamp-3">{{ $entry->message }}</div>
                                    </td>
                                    <td class="max-w-md py-4 pr-4 text-zinc-600 dark:text-zinc-300">
                                        <div class="line-clamp-3">{{ $entry->reply ?? $entry->error_message ?? '—' }}</div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">Ainda não há logs de conversa do bot.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
