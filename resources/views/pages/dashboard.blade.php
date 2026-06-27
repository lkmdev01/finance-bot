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

    @php
        $supportEmail = config('support.email');
        $supportWhatsAppUrl = config('support.whatsapp_url');
        $supportNumber = preg_replace('/\D+/', '', (string) config('support.whatsapp_number'));

        if (! $supportWhatsAppUrl && $supportNumber) {
            $supportWhatsAppUrl = "https://wa.me/{$supportNumber}";
        }
    @endphp

    <div class="mb-6 rounded-3xl border border-emerald-400/20 bg-gradient-to-r from-emerald-400/10 via-cyan-400/10 to-transparent p-5">
        <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
            <div>
                <p class="text-xs uppercase tracking-[0.24em] text-emerald-300/80">Suporte</p>
                <h2 class="mt-2 text-xl font-semibold text-white">Precisa de ajuda com conta, pagamento ou WhatsApp?</h2>
                <p class="mt-2 text-sm text-slate-300">
                    Acesse os canais oficiais do InovaFinance. {{ config('support.response_time') }}.
                </p>
            </div>

            <div class="flex flex-wrap gap-3">
                <a href="{{ route('support') }}" class="rounded-2xl border border-emerald-300/30 bg-emerald-300/10 px-4 py-2 text-sm font-semibold text-emerald-100 transition hover:bg-emerald-300/20">
                    Abrir suporte
                </a>
                @if($supportWhatsAppUrl)
                    <a href="{{ $supportWhatsAppUrl }}" target="_blank" rel="noopener" class="rounded-2xl border border-cyan-300/30 bg-cyan-300/10 px-4 py-2 text-sm font-semibold text-cyan-100 transition hover:bg-cyan-300/20">
                        WhatsApp
                    </a>
                @endif
                @if($supportEmail)
                    <a href="mailto:{{ $supportEmail }}" class="rounded-2xl border border-white/10 px-4 py-2 text-sm font-semibold text-slate-100 transition hover:bg-white/10">
                        E-mail
                    </a>
                @endif
            </div>
        </div>
    </div>

    <livewire:dashboard />
</x-layouts.app.sidebar>

