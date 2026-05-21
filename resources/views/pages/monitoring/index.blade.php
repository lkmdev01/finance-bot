<x-layouts.app>
    <x-slot name="title">Monitoramento</x-slot>

    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Dashboard de Monitoramento</h1>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Visão geral do sistema e da performance do bot.</p>
        </div>

        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
            <div class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex h-10 w-10 items-center justify-center rounded-md bg-green-500">
                            <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="truncate text-sm font-medium text-gray-500 dark:text-gray-400">Status WhatsApp</dt>
                                <dd class="text-lg font-semibold text-gray-900 dark:text-white">Conectado</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex h-10 w-10 items-center justify-center rounded-md bg-blue-500">
                            <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                            </svg>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="truncate text-sm font-medium text-gray-500 dark:text-gray-400">Tempo Médio IA</dt>
                                <dd class="text-lg font-semibold text-gray-900 dark:text-white">{{ number_format($metrics->getAverageResponseTime(), 0) }}ms</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex h-10 w-10 items-center justify-center rounded-md bg-purple-500">
                            <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                            </svg>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="truncate text-sm font-medium text-gray-500 dark:text-gray-400">Taxa de Sucesso</dt>
                                <dd class="text-lg font-semibold text-gray-900 dark:text-white">{{ number_format($metrics->getSuccessRate() * 100, 1) }}%</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex h-10 w-10 items-center justify-center rounded-md bg-red-500">
                            <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="truncate text-sm font-medium text-gray-500 dark:text-gray-400">Erros (24h)</dt>
                                <dd class="text-lg font-semibold text-gray-900 dark:text-white">{{ $metrics->getErrorCount() }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="rounded-lg bg-white shadow dark:bg-gray-800">
            <div class="px-4 py-5 sm:p-6">
                <h3 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Estatísticas de Mensagens</h3>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Mensagens Hoje</p>
                        <p class="text-2xl font-semibold text-gray-900 dark:text-white">{{ $messagesToday }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Mensagens Esta Semana</p>
                        <p class="text-2xl font-semibold text-gray-900 dark:text-white">{{ $messagesThisWeek }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Total de Contatos</p>
                        <p class="text-2xl font-semibold text-gray-900 dark:text-white">{{ $totalContacts }}</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="rounded-lg bg-white shadow dark:bg-gray-800">
            <div class="px-4 py-5 sm:p-6">
                <h3 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Performance da IA</h3>
                <dl class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <dt class="text-sm text-gray-500 dark:text-gray-400">Tempo Médio de Resposta</dt>
                        <dd class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ number_format($metrics->getAverageResponseTime(), 0) }}ms</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500 dark:text-gray-400">Taxa de Sucesso</dt>
                        <dd class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ number_format($metrics->getSuccessRate() * 100, 1) }}%</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500 dark:text-gray-400">Total de Requisições</dt>
                        <dd class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ $metrics->getTotalRequests() }}</dd>
                    </div>
                </dl>
            </div>
        </div>

        <div class="rounded-lg bg-white shadow dark:bg-gray-800">
            <div class="px-4 py-5 sm:p-6">
                <h3 class="mb-4 text-lg font-medium text-gray-900 dark:text-white">Erros Recentes</h3>
                <div class="flow-root">
                    <ul class="-mb-8">
                        @forelse($recentErrors as $log)
                            <li>
                                <div class="relative pb-8">
                                    <div class="relative flex space-x-3">
                                        <div>
                                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-red-500 ring-8 ring-white dark:ring-gray-800">
                                                <svg class="h-5 w-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                                                </svg>
                                            </span>
                                        </div>
                                        <div class="flex min-w-0 flex-1 justify-between space-x-4 pt-1.5">
                                            <div>
                                                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $log->metadata['error_type'] ?? 'Erro desconhecido' }}</p>
                                            </div>
                                            <div class="whitespace-nowrap text-right text-sm text-gray-500 dark:text-gray-400">{{ $log->created_at->diffForHumans() }}</div>
                                        </div>
                                    </div>
                                </div>
                            </li>
                        @empty
                            <li class="text-sm text-gray-500 dark:text-gray-400">Nenhum erro recente.</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        </div>

        <div class="rounded-lg bg-white shadow dark:bg-gray-800">
            <div class="px-4 py-5 sm:p-6">
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white">Observação do Bot</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Últimas mensagens processadas com classificação, ação e handler.</p>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                        <thead>
                            <tr class="text-left text-zinc-500 dark:text-zinc-400">
                                <th class="py-2 pr-4">Quando</th>
                                <th class="py-2 pr-4">Status</th>
                                <th class="py-2 pr-4">Classificação</th>
                                <th class="py-2 pr-4">Ação</th>
                                <th class="py-2 pr-4">Handler</th>
                                <th class="py-2 pr-4">Mensagem</th>
                                <th class="py-2 pr-4">Resposta</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @forelse($recentConversationLogs as $entry)
                                <tr>
                                    <td class="whitespace-nowrap py-3 pr-4 align-top text-zinc-600 dark:text-zinc-300">{{ $entry->created_at->format('d/m H:i:s') }}</td>
                                    <td class="py-3 pr-4 align-top">
                                        <span class="inline-flex rounded-full px-2 py-1 text-xs font-medium {{ $entry->status === 'error' ? 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-200' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200' }}">
                                            {{ $entry->status }}
                                        </span>
                                    </td>
                                    <td class="py-3 pr-4 align-top text-zinc-700 dark:text-zinc-200">{{ $entry->classification ?? '—' }}</td>
                                    <td class="py-3 pr-4 align-top text-zinc-700 dark:text-zinc-200">{{ $entry->action ?? '—' }}</td>
                                    <td class="py-3 pr-4 align-top text-zinc-600 dark:text-zinc-300">{{ $entry->handler ? class_basename($entry->handler) : '—' }}</td>
                                    <td class="max-w-sm py-3 pr-4 align-top text-zinc-700 dark:text-zinc-200">
                                        <div class="line-clamp-3">{{ $entry->message }}</div>
                                    </td>
                                    <td class="max-w-md py-3 pr-4 align-top text-zinc-600 dark:text-zinc-300">
                                        <div class="line-clamp-3">{{ $entry->reply ?? $entry->error_message ?? '—' }}</div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="py-6 text-center text-zinc-500 dark:text-zinc-400">Ainda não há logs de conversa do bot.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
