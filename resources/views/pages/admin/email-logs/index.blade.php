<x-layouts.app>
    <x-slot name="title">Historico de e-mails</x-slot>

    <div class="mx-auto max-w-7xl space-y-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <p class="text-sm font-medium uppercase tracking-[0.22em] text-emerald-300">Admin</p>
                <h1 class="mt-2 text-3xl font-black text-white sm:text-4xl">Historico de e-mails</h1>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-300">
                    Consulte os e-mails transacionais enviados pelo sistema para auditar assinatura, seguranca e comunicacoes.
                </p>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <div class="rounded-3xl border border-white/10 bg-white/[0.04] p-5">
                <p class="text-sm text-slate-400">Hoje</p>
                <p class="mt-2 text-3xl font-black text-white">{{ $stats['today'] }}</p>
            </div>
            <div class="rounded-3xl border border-white/10 bg-white/[0.04] p-5">
                <p class="text-sm text-slate-400">Ultimos 7 dias</p>
                <p class="mt-2 text-3xl font-black text-white">{{ $stats['week'] }}</p>
            </div>
            <div class="rounded-3xl border border-white/10 bg-white/[0.04] p-5">
                <p class="text-sm text-slate-400">Total</p>
                <p class="mt-2 text-3xl font-black text-white">{{ $stats['total'] }}</p>
            </div>
        </div>

        <form method="GET" action="{{ route('admin.email-logs.index') }}" class="rounded-3xl border border-white/10 bg-slate-950/70 p-4">
            <div class="grid grid-cols-1 gap-3 md:grid-cols-[minmax(0,1fr)_auto]">
                <input name="q" value="{{ $search }}" placeholder="Buscar por e-mail, assunto ou tipo" class="w-full rounded-2xl border border-white/10 bg-slate-900 px-4 py-3 text-sm text-white outline-none placeholder:text-slate-500 focus:border-emerald-400">
                <button type="submit" class="rounded-2xl bg-cyan-400 px-5 py-3 text-sm font-black text-slate-950 transition hover:bg-cyan-300">Filtrar</button>
            </div>
        </form>

        <div class="overflow-hidden rounded-3xl border border-white/10 bg-slate-950/70">
            <div class="hidden grid-cols-[180px_minmax(0,1fr)_minmax(0,1fr)_160px_160px] gap-4 border-b border-white/10 px-5 py-3 text-xs font-bold uppercase tracking-[0.16em] text-slate-500 lg:grid">
                <span>Data</span>
                <span>Destinatario</span>
                <span>Assunto</span>
                <span>Tipo</span>
                <span>Status</span>
            </div>

            <div class="divide-y divide-white/10">
                @forelse ($logs as $log)
                    <div class="grid gap-3 px-5 py-4 text-sm text-slate-300 lg:grid-cols-[180px_minmax(0,1fr)_minmax(0,1fr)_160px_160px] lg:items-center">
                        <div>
                            <span class="block text-xs font-bold uppercase tracking-[0.16em] text-slate-500 lg:hidden">Data</span>
                            {{ $log->created_at?->format('d/m/Y H:i') }}
                        </div>
                        <div class="min-w-0">
                            <span class="block text-xs font-bold uppercase tracking-[0.16em] text-slate-500 lg:hidden">Destinatario</span>
                            <p class="truncate font-bold text-white">{{ $log->user?->name ?: 'Usuario externo' }}</p>
                            <p class="truncate text-xs text-slate-400">{{ $log->to_email }}</p>
                        </div>
                        <div class="min-w-0">
                            <span class="block text-xs font-bold uppercase tracking-[0.16em] text-slate-500 lg:hidden">Assunto</span>
                            <p class="truncate">{{ $log->subject ?: 'Sem assunto' }}</p>
                        </div>
                        <div class="min-w-0">
                            <span class="block text-xs font-bold uppercase tracking-[0.16em] text-slate-500 lg:hidden">Tipo</span>
                            <p class="truncate text-xs">{{ class_basename($log->notification_type ?: 'Email') }}</p>
                        </div>
                        <div>
                            <span class="inline-flex rounded-full border border-emerald-300/20 bg-emerald-400/10 px-3 py-1 text-xs font-bold text-emerald-100">{{ $log->status }}</span>
                        </div>
                    </div>
                @empty
                    <div class="p-8 text-center text-sm text-slate-400">Nenhum e-mail registrado ainda.</div>
                @endforelse
            </div>
        </div>

        {{ $logs->links() }}
    </div>
</x-layouts.app>
