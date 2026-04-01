<x-layouts.app.sidebar title="Planos">
    @php
        $user = auth()->user();
    @endphp

    <div class="space-y-8">
        @if (session('status'))
            <div class="rounded-2xl border border-sky-400/20 bg-sky-500/10 px-4 py-3 text-sm text-sky-100">
                {{ session('status') }}
            </div>
        @endif

        <section class="rounded-[2rem] border border-white/10 bg-gradient-to-br from-slate-950 via-slate-900 to-indigo-950/80 p-6 shadow-[0_24px_80px_rgba(2,6,23,0.34)] sm:p-8">
            <div class="grid gap-8 lg:grid-cols-[1.2fr_0.8fr] lg:items-center">
                <div>
                    <div class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-300">
                        Billing
                    </div>
                    <h1 class="mt-5 text-4xl font-black tracking-tight text-white sm:text-5xl">Escolha o plano ideal para continuar subindo.</h1>
                    <p class="mt-4 max-w-2xl text-sm leading-7 text-slate-300 sm:text-base">
                        O Starter cobre o básico. Os planos Pro liberam relatórios avançados, projeções financeiras e a experiência completa do {{ config('mascot.name', 'Orbita') }}.
                    </p>
                </div>

                <div class="rounded-[1.75rem] border border-white/10 bg-white/5 p-5">
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Seu acesso atual</p>
                    <h2 class="mt-3 text-2xl font-black text-white">{{ $currentPlan['name'] }}</h2>
                    <p class="mt-2 text-sm text-slate-300">{{ $currentPlan['description'] }}</p>
                    <div class="mt-5 flex flex-wrap gap-3 text-sm text-slate-300">
                        <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1">
                            Status: {{ $user->billing_plan_status_label }}
                        </span>
                        @if ($user->billing_access_ends_at)
                            <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1">
                                Acesso até {{ $user->billing_access_ends_at->format('d/m/Y') }}
                            </span>
                        @endif
                    </div>
                </div>
            </div>
        </section>

        <section class="grid gap-6 xl:grid-cols-3">
            @foreach ($plans as $plan)
                @php
                    $isCurrent = ($user->billing_plan_code ?: config('billing.default_plan', 'starter')) === $plan['code'] && (($plan['price_cents'] === 0) || $user->hasActivePaidPlan());
                @endphp

                <article class="rounded-[2rem] border {{ $plan['highlight'] ? 'border-indigo-400/40 bg-indigo-500/10' : 'border-white/10 bg-slate-950/80' }} p-6 shadow-[0_16px_60px_rgba(2,6,23,0.28)]">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">{{ $plan['badge'] }}</p>
                            <h2 class="mt-3 text-2xl font-black text-white">{{ $plan['name'] }}</h2>
                        </div>
                        <div class="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-sm font-semibold text-white">
                            {{ $plan['formatted_price'] }}
                        </div>
                    </div>

                    <p class="mt-4 text-sm leading-7 text-slate-300">{{ $plan['description'] }}</p>

                    <ul class="mt-6 space-y-3 text-sm text-slate-200">
                        @foreach ($plan['features'] as $feature)
                            <li class="flex items-center gap-3">
                                <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-emerald-400/15 text-emerald-200">?</span>
                                <span>{{ str($feature)->replace('_', ' ')->title() }}</span>
                            </li>
                        @endforeach
                    </ul>

                    <div class="mt-8">
                        @if ($plan['price_cents'] === 0)
                            <flux:button variant="ghost" class="w-full" disabled>
                                Sempre disponível
                            </flux:button>
                        @elseif ($isCurrent)
                            <flux:button variant="ghost" class="w-full" disabled>
                                Plano atual
                            </flux:button>
                        @else
                            <form method="POST" action="{{ route('billing.subscribe', $plan['code']) }}">
                                @csrf
                                <flux:button type="submit" variant="primary" class="w-full">
                                    Assinar {{ $plan['name'] }}
                                </flux:button>
                            </form>
                        @endif
                    </div>
                </article>
            @endforeach
        </section>
    </div>
</x-layouts.app.sidebar>
