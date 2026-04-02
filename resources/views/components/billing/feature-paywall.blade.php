@props([
    'featureTitle' => 'Recurso premium',
    'featureDescription' => 'Este recurso faz parte dos planos Pro.',
])

<div class="rounded-[2rem] border border-white/10 bg-gradient-to-br from-slate-900 via-slate-900 to-indigo-950/80 p-6 shadow-[0_24px_80px_rgba(2,6,23,0.32)] sm:p-8">
    <div class="max-w-3xl">
        <div class="inline-flex items-center gap-2 rounded-full border border-amber-400/20 bg-amber-400/10 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.22em] text-amber-200">
            Premium
        </div>

        <h1 class="mt-5 text-3xl font-black tracking-tight text-white sm:text-4xl">{{ $featureTitle }}</h1>
        <p class="mt-4 max-w-2xl text-sm leading-7 text-slate-300 sm:text-base">{{ $featureDescription }}</p>

        <div class="mt-8 grid gap-4 rounded-[1.5rem] border border-white/10 bg-white/5 p-5 sm:grid-cols-[1fr_auto] sm:items-center">
            <div>
                <p class="text-sm font-semibold text-white">Desbloqueie com Pro Mensal ou Pro Anual</p>
                <p class="mt-1 text-sm text-slate-400">Ative um plano para liberar relatorios avancados, projecoes e o Orbita durante o periodo pago.</p>
            </div>

            <flux:button :href="route('billing.plans')" wire:navigate variant="primary">
                Ver planos
            </flux:button>
        </div>
    </div>
</div>
