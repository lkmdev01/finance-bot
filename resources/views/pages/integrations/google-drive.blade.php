<x-layouts.app.sidebar title="Integrações: Google Drive">
    <div class="p-6 space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold">Google Drive</h1>
                <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-1">
                    Conecte seu Drive para salvar arquivos enviados no WhatsApp e encontrar depois por busca.
                </p>
            </div>
        </div>

        <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6 space-y-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div class="space-y-1">
                    <p class="text-sm font-semibold">Status</p>
                    @if($connection && ! $connection->revoked_at)
                        <p class="text-sm text-green-600 dark:text-green-400">Conectado</p>
                        @if($rootFolderId)
                            <p class="text-xs text-zinc-500">Pasta raiz: <span class="font-mono">{{ $rootFolderId }}</span></p>
                        @endif
                    @else
                        <p class="text-sm text-zinc-600 dark:text-zinc-400">Desconectado</p>
                    @endif
                </div>

                <div class="flex items-center gap-2">
                    @if($connection && ! $connection->revoked_at)
                        <form method="POST" action="{{ route('integrations.google-drive.disconnect') }}">
                            @csrf
                            <flux:button type="submit" variant="danger">Desconectar</flux:button>
                        </form>
                    @else
                        <a
                            href="{{ route('google-drive.redirect') }}"
                            class="inline-flex items-center justify-center rounded-xl bg-[var(--color-accent,#6366f1)] px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-[var(--color-accent,#6366f1)]/40"
                        >
                            Conectar Google Drive
                        </a>
                    @endif
                </div>
            </div>

            <div class="rounded-lg bg-zinc-50 dark:bg-zinc-950/60 border border-zinc-200/70 dark:border-zinc-800 p-4">
                <p class="text-sm font-semibold">Como usar no WhatsApp</p>
                <ul class="mt-2 text-sm text-zinc-700 dark:text-zinc-300 list-disc pl-5 space-y-1">
                    <li>Envie um arquivo (PDF, foto, audio, etc.) e diga: <span class="font-mono">salva isso no drive</span></li>
                    <li>Ou indique pasta: <span class="font-mono">salva na pasta de comprovantes/veiculos</span></li>
                    <li>Para buscar: <span class="font-mono">ache meu comprovante do mecanico</span></li>
                </ul>
            </div>
        </div>
    </div>
</x-layouts.app.sidebar>
