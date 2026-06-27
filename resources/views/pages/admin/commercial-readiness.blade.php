<x-layouts.app>
    <x-slot name="title">Pronto para vender</x-slot>

    @php
        $statusStyles = [
            'pass' => 'border-emerald-300/20 bg-emerald-400/10 text-emerald-100',
            'warning' => 'border-amber-300/20 bg-amber-400/10 text-amber-100',
            'fail' => 'border-rose-300/20 bg-rose-400/10 text-rose-100',
        ];
    @endphp

    <div class="mx-auto max-w-7xl space-y-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <p class="text-sm font-medium uppercase tracking-[0.22em] text-emerald-300">Admin</p>
                <h1 class="mt-2 text-3xl font-black text-white sm:text-4xl">Pronto para vender?</h1>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-300">
                    Checklist operacional para decidir se o InovaFinance pode receber usuarios pagantes hoje sem depender de improviso.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('billing.plans') }}" class="rounded-2xl border border-white/10 px-4 py-2 text-sm font-bold text-slate-100 hover:bg-white/10">Planos</a>
                <a href="{{ route('admin.email-logs.index') }}" class="rounded-2xl border border-white/10 px-4 py-2 text-sm font-bold text-slate-100 hover:bg-white/10">E-mails</a>
                <a href="{{ route('support') }}" class="rounded-2xl border border-emerald-300/20 bg-emerald-400/10 px-4 py-2 text-sm font-bold text-emerald-100 hover:bg-emerald-400/20">Suporte</a>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <div class="rounded-3xl border border-emerald-300/20 bg-emerald-400/10 p-5">
                <p class="text-sm text-emerald-100/80">OK</p>
                <p class="mt-2 text-4xl font-black text-white">{{ $summary['passed'] }}</p>
            </div>
            <div class="rounded-3xl border border-amber-300/20 bg-amber-400/10 p-5">
                <p class="text-sm text-amber-100/80">Atencao</p>
                <p class="mt-2 text-4xl font-black text-white">{{ $summary['warning'] }}</p>
            </div>
            <div class="rounded-3xl border border-rose-300/20 bg-rose-400/10 p-5">
                <p class="text-sm text-rose-100/80">Falhas</p>
                <p class="mt-2 text-4xl font-black text-white">{{ $summary['failed'] }}</p>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            @foreach ($checks as $check)
                <div class="rounded-3xl border p-5 {{ $statusStyles[$check['status']] ?? 'border-white/10 bg-white/[0.04] text-slate-100' }}">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-lg font-black text-white">{{ $check['label'] }}</p>
                            <p class="mt-2 text-sm leading-6 opacity-85">{{ $check['message'] }}</p>
                        </div>
                        <span class="rounded-full border border-white/15 bg-white/10 px-3 py-1 text-xs font-black uppercase">{{ $check['status'] }}</span>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_420px]">
            <div class="rounded-3xl border border-white/10 bg-slate-950/70 p-5">
                <h2 class="text-xl font-black text-white">Sinais das ultimas 24h</h2>
                <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                        <p class="text-xs uppercase tracking-[0.16em] text-slate-500">Webhooks falhos</p>
                        <p class="mt-2 text-3xl font-black text-white">{{ $signals['failed_webhooks_24h'] }}</p>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                        <p class="text-xs uppercase tracking-[0.16em] text-slate-500">E-mails enviados</p>
                        <p class="mt-2 text-3xl font-black text-white">{{ $signals['emails_24h'] }}</p>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                        <p class="text-xs uppercase tracking-[0.16em] text-slate-500">Erros WhatsApp</p>
                        <p class="mt-2 text-3xl font-black text-white">{{ $signals['whatsapp_errors_24h'] }}</p>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                        <p class="text-xs uppercase tracking-[0.16em] text-slate-500">Pagantes ativos</p>
                        <p class="mt-2 text-3xl font-black text-white">{{ $signals['active_paid_users'] }}</p>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                        <p class="text-xs uppercase tracking-[0.16em] text-slate-500">Jobs pendentes</p>
                        <p class="mt-2 text-3xl font-black text-white">{{ $signals['pending_jobs'] ?? 'n/a' }}</p>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                        <p class="text-xs uppercase tracking-[0.16em] text-slate-500">Jobs falhos</p>
                        <p class="mt-2 text-3xl font-black text-white">{{ $signals['failed_jobs'] ?? 'n/a' }}</p>
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                <div class="rounded-3xl border border-cyan-300/20 bg-cyan-400/10 p-5 text-cyan-50">
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-cyan-100/80">Rotinas obrigatorias</p>
                    <h2 class="mt-2 text-xl font-black text-white">Coolify precisa manter isto vivo</h2>
                    <div class="mt-4 space-y-3 text-sm">
                        <code class="block overflow-x-auto rounded-2xl bg-slate-950/80 p-3 text-cyan-100">php artisan queue:work --sleep=3 --tries=3 --timeout=90</code>
                        <code class="block overflow-x-auto rounded-2xl bg-slate-950/80 p-3 text-cyan-100">* * * * * cd /app && php artisan schedule:run</code>
                    </div>
                </div>

                <div class="rounded-3xl border border-emerald-300/20 bg-emerald-400/10 p-5 text-emerald-50">
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-emerald-100/80">Suporte</p>
                    <h2 class="mt-2 text-xl font-black text-white">Canal claro para cliente</h2>
                    <p class="mt-2 text-sm leading-6">E-mail: {{ $support['email'] ?: 'nao configurado' }}</p>
                    <p class="mt-1 text-sm leading-6">Tempo: {{ $support['response_time'] }}</p>
                    <div class="mt-4 flex flex-wrap gap-2">
                        @if($support['whatsapp_url'])
                            <a href="{{ $support['whatsapp_url'] }}" target="_blank" class="rounded-2xl bg-emerald-300 px-4 py-2 text-sm font-black text-slate-950">WhatsApp</a>
                        @endif
                        @if($support['email'])
                            <a href="mailto:{{ $support['email'] }}" class="rounded-2xl border border-white/15 px-4 py-2 text-sm font-bold text-white">E-mail</a>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="rounded-3xl border border-white/10 bg-slate-950/70 p-5">
            <h2 class="text-xl font-black text-white">Ultimos eventos</h2>
            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                    <p class="text-xs uppercase tracking-[0.16em] text-slate-500">Ultimo webhook AbacatePay</p>
                    <p class="mt-2 text-sm text-white">{{ $signals['latest_webhook']?->event_name ?: 'Sem webhook registrado' }}</p>
                    <p class="mt-1 text-xs text-slate-400">{{ $signals['latest_webhook']?->status }} - {{ $signals['latest_webhook']?->received_at?->format('d/m/Y H:i') }}</p>
                </div>
                <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                    <p class="text-xs uppercase tracking-[0.16em] text-slate-500">Ultimo e-mail</p>
                    <p class="mt-2 text-sm text-white">{{ $signals['latest_email']?->subject ?: 'Sem e-mail registrado' }}</p>
                    <p class="mt-1 text-xs text-slate-400">{{ $signals['latest_email']?->to_email }} - {{ $signals['latest_email']?->created_at?->format('d/m/Y H:i') }}</p>
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
