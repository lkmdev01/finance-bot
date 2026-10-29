<x-layouts.app.sidebar title="Pesquisas">
    <main class="mx-auto max-w-6xl space-y-6">
        <header>
            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-cyan-300">Assistente</p>
            <h1 class="mt-2 text-3xl font-bold text-white">Pesquisas</h1>
                <p class="mt-2 text-sm text-slate-300">Pesquisas públicas feitas pelo WhatsApp, com fontes para conferência.</p>
        </header>

        @if (session('status'))
            <p role="status" class="rounded-xl border border-emerald-300/20 bg-emerald-400/10 p-3 text-sm text-emerald-100">{{ session('status') }}</p>
        @endif

        <nav aria-label="Filtrar pesquisas" class="flex flex-wrap gap-2">
            @foreach (['' => 'Todas', 'delivered' => 'Entregues', 'pending' => 'Em andamento', 'in_progress' => 'Processando', 'failed' => 'Falhas'] as $value => $label)
                <a href="{{ route('research.index', $value ? ['status' => $value] : []) }}" class="rounded-full border px-4 py-2 text-sm {{ $status === $value ? 'border-lime-300 bg-lime-300 text-slate-950' : 'border-white/10 text-slate-200' }}">{{ $label }}</a>
            @endforeach
        </nav>

        <section class="space-y-6">
            @forelse ($queries->getCollection()->groupBy(fn ($item) => $item->created_at->timezone(config('app.timezone'))->toDateString()) as $day => $dayQueries)
                <div class="space-y-3">
                    <h2 class="text-sm font-semibold text-slate-300">{{ \Carbon\CarbonImmutable::parse($day)->locale('pt_BR')->translatedFormat('l, d \\d\\e F \\d\\e Y') }}</h2>
                    @foreach ($dayQueries as $item)
                <article class="space-y-3 rounded-2xl border border-white/10 bg-white/5 p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="text-xs text-slate-400">{{ $item->created_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</p>
                            <h2 class="mt-1 font-semibold text-white">{{ $item->query }}</h2>
                        </div>
                        <span class="rounded-full bg-white/10 px-3 py-1 text-xs text-slate-200">{{ ['pending' => 'Em andamento', 'in_progress' => 'Processando', 'delivered' => 'Entregue', 'failed' => 'Falhou'][$item->status] ?? $item->status }}</span>
                    </div>
                    @if ($item->answer)
                        <p class="text-xs font-semibold text-cyan-200">Ítalo</p>
                        <p class="whitespace-pre-line text-sm text-slate-200">{{ $item->answer }}</p>
                    @elseif ($item->status === 'failed')
                        <p class="text-sm text-amber-200">Não foi possível concluir esta pesquisa. Código: {{ $item->failure_code ?? 'indisponível' }}.</p>
                    @endif
                    @if ($item->sources)
                        <ul class="space-y-1 border-t border-white/10 pt-3 text-sm">
                            @foreach ($item->sources as $source)
                                <li><a class="text-cyan-200 underline" href="{{ $source['url'] }}" target="_blank" rel="noopener noreferrer">{{ $source['domain'] ?? $source['title'] }} — Ver fonte</a></li>
                            @endforeach
                        </ul>
                    @endif
                    <form method="POST" action="{{ route('research.destroy', $item) }}" onsubmit="return confirm('Remover esta pesquisa do seu histórico?')">
                        @csrf @method('DELETE')
                        <button class="text-xs text-slate-400 underline">Excluir do histórico</button>
                    </form>
                </article>
                    @endforeach
                </div>
            @empty
                <p class="rounded-2xl border border-white/10 bg-white/5 p-6 text-sm text-slate-300">Nenhuma pesquisa neste filtro.</p>
            @endforelse
        </section>

        {{ $queries->links() }}
    </main>
</x-layouts.app.sidebar>
