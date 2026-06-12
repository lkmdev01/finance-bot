<x-layouts.app.sidebar :title="'Planejamento de ' . auth()->user()->name">
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

    <livewire:dashboard />
</x-layouts.app.sidebar>

