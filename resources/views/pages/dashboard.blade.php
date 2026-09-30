<x-layouts.app.sidebar :title="'Planejamento de ' . auth()->user()->name">

    @php
        $supportEmail = config('support.email');
        $supportWhatsAppUrl = config('support.whatsapp_url');
        $supportNumber = preg_replace('/\D+/', '', (string) config('support.whatsapp_number'));

        if (! $supportWhatsAppUrl && $supportNumber) {
            $supportWhatsAppUrl = "https://wa.me/{$supportNumber}";
        }
    @endphp

    <div class="brand-paper mb-6 rounded-3xl p-5">
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
