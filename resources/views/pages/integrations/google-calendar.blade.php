<x-layouts.app.sidebar title="Integrações: Google Calendar">
    <div class="p-6 space-y-6">
        <div>
            <h1 class="text-2xl font-bold">Google Calendar e Tasks</h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                Conecte sua agenda e lista de tarefas para consultar compromissos e organizar tarefas pelo WhatsApp.
            </p>
        </div>

        @if (session('message'))
            <div role="status" class="rounded-lg border border-zinc-200 bg-white p-4 text-sm dark:border-zinc-700 dark:bg-zinc-900">
                {{ session('message') }}
            </div>
        @endif

        <section class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-sm font-semibold">Status da conexão</p>
                    @if ($connection && ! $connection->revoked_at)
                        <p class="mt-1 text-sm text-green-600 dark:text-green-400">Conectado</p>
                        @if ($connection->google_email)
                            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ $connection->google_email }}</p>
                        @endif
                    @else
                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">Desconectado</p>
                    @endif
                </div>

                @if ($connection && ! $connection->revoked_at && ! $needsAdditionalScopes)
                    <form method="POST" action="{{ route('integrations.google-calendar.disconnect') }}">
                        @csrf
                        <flux:button type="submit" variant="danger">Desconectar</flux:button>
                    </form>
                @else
                    <a href="{{ route('google-calendar.redirect') }}"
                       class="inline-flex items-center justify-center rounded-xl bg-[var(--color-accent,#6366f1)] px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-[var(--color-accent,#6366f1)]/40">
                        {{ $connection && ! $connection->revoked_at ? 'Atualizar permissões' : 'Conectar Google Calendar' }}
                    </a>
                @endif
            </div>

            <p class="mt-5 rounded-lg bg-zinc-50 p-4 text-sm text-zinc-700 dark:bg-zinc-950/60 dark:text-zinc-300">
                A conexão permite criar e consultar eventos do seu calendário principal, verificar disponibilidade e gerenciar tarefas da sua conta Google. O escopo de tarefas permite criar, editar, organizar e excluir tarefas. Desconectar impede novos acessos pelo InovaFinance. A gravação de reuniões pelo Vexa é uma função separada.
            </p>
        </section>
    </div>
</x-layouts.app.sidebar>
