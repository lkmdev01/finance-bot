<x-layouts.checkout title="Planos" :back-href="route('dashboard')">
    @php
        $user = auth()->user();
        $hasSubscriptionFlow = collect($plans ?? [])->contains(fn ($plan) => ($plan['checkout_flow'] ?? 'checkout') === 'subscription' && (($plan['price_cents'] ?? 0) > 0));
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
                        O Starter cobre o basico. Os planos Pro liberam relatorios avancados, projecoes financeiras e a experiencia completa do {{ config('mascot.name', 'Orbita') }}.
                    </p>
                    <p class="mt-2 max-w-2xl text-sm leading-7 text-slate-400">
                        @if ($hasSubscriptionFlow)
                            Alguns planos renovam automaticamente no cartao (assinatura). Outros funcionam como pagamento avulso e nao renovam automaticamente.
                        @else
                            Cada pagamento libera o acesso pelo periodo do plano escolhido. Nao ha renovacao automatica nesta etapa.
                        @endif
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
                        @if ($user->hasActiveTrial())
                            <span class="rounded-full border border-emerald-300/20 bg-emerald-400/10 px-3 py-1 text-emerald-100">
                                Teste grátis até {{ $user->trial_ends_at?->format('d/m/Y') }}
                            </span>
                        @elseif ($user->trial_ends_at)
                            <span class="rounded-full border border-amber-300/20 bg-amber-400/10 px-3 py-1 text-amber-100">
                                Leitura liberada, novos registros exigem assinatura
                            </span>
                        @endif
                        @if ($user->billing_access_ends_at)
                            <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1">
                                Acesso ate {{ $user->billing_access_ends_at->format('d/m/Y') }}
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
                                <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-emerald-400/15"><span class="h-2.5 w-2.5 rounded-full bg-emerald-200"></span></span>
                                <span>{{ str($feature)->replace('_', ' ')->title() }}</span>
                            </li>
                        @endforeach
                    </ul>

                    <div class="mt-8">
                        @if ($plan['price_cents'] === 0)
                            <flux:button variant="ghost" class="w-full" disabled>
                                Sempre disponivel
                            </flux:button>
                        @elseif ($isCurrent)
                            <flux:button variant="ghost" class="w-full" disabled>
                                Plano atual
                            </flux:button>
                        @else
                            <form method="POST" action="{{ route('billing.subscribe', $plan['code']) }}" data-billing-subscribe-form data-plan-name="{{ $plan['name'] }}">
                                @csrf
                                <flux:button type="submit" variant="primary" class="w-full">
                                    Ativar {{ $plan['name'] }}
                                </flux:button>
                            </form>
                        @endif
                    </div>
                </article>
            @endforeach
        </section>
    </div>

    {{-- Checkout invisivel: coleta CPF/CNPJ inline (se faltar) e inicia o checkout via AJAX sem sair da tela. --}}
    <div id="billing-tax-modal" class="fixed inset-0 z-[999] hidden">
        <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" data-billing-tax-close></div>
        <div class="relative mx-auto mt-24 w-[92%] max-w-lg rounded-[1.75rem] border border-white/10 bg-slate-950/90 p-6 shadow-[0_24px_90px_rgba(2,6,23,0.55)]">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">Confirmar dados</p>
                    <h3 class="mt-2 text-2xl font-black text-white">CPF ou CNPJ</h3>
                    <p class="mt-2 text-sm leading-7 text-slate-300">
                        Para abrir o checkout do plano <span class="font-semibold text-white" data-billing-tax-plan></span>, preciso do seu CPF/CNPJ.
                    </p>
                </div>
                <button type="button" class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white hover:bg-white/10" data-billing-tax-close>
                    Fechar
                </button>
            </div>

            <div class="mt-6 space-y-3">
                <label class="block text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Documento</label>
                <input
                    id="billing-tax-id"
                    type="text"
                    inputmode="numeric"
                    autocomplete="off"
                    placeholder="000.000.000-00"
                    class="w-full rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white placeholder:text-slate-500 focus:border-indigo-400/60 focus:outline-none focus:ring-2 focus:ring-indigo-400/20"
                    value="{{ old('tax_id', \App\Support\BrazilTaxId::format($user->tax_id)) }}"
                />
                <p class="text-xs leading-6 text-slate-400">Se o plano for assinatura, ele renova automaticamente no cartao. Caso contrario, e pagamento unico.</p>
                <p class="hidden text-sm text-rose-300" data-billing-tax-error></p>
            </div>

            <div class="mt-6 flex flex-col gap-3">
                <button type="button" class="inline-flex w-full items-center justify-center rounded-xl bg-indigo-500 px-4 py-3 text-sm font-semibold text-white transition hover:bg-indigo-400 disabled:cursor-not-allowed disabled:opacity-60" data-billing-tax-confirm>
                    Continuar para pagamento
                </button>
                <div class="text-center text-xs text-slate-400" data-billing-tax-loading style="display:none;">Iniciando checkout...</div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const modal = document.getElementById('billing-tax-modal');
            const planLabel = modal?.querySelector('[data-billing-tax-plan]');
            const closeButtons = modal?.querySelectorAll('[data-billing-tax-close]') ?? [];
            const confirmButton = modal?.querySelector('[data-billing-tax-confirm]');
            const loadingEl = modal?.querySelector('[data-billing-tax-loading]');
            const errorEl = modal?.querySelector('[data-billing-tax-error]');
            const taxInput = document.getElementById('billing-tax-id');

            if (!modal || !confirmButton || !taxInput) return;

            let pendingForm = null;

            function showModal(planName) {
                if (planLabel) planLabel.textContent = planName || '';
                if (errorEl) {
                    errorEl.textContent = '';
                    errorEl.classList.add('hidden');
                }
                modal.classList.remove('hidden');
                taxInput.focus();
            }

            function hideModal() {
                modal.classList.add('hidden');
                pendingForm = null;
                if (loadingEl) loadingEl.style.display = 'none';
                confirmButton.disabled = false;
            }

            closeButtons.forEach(btn => btn.addEventListener('click', hideModal));

            async function submitSubscribe(form, extraFields) {
                const fd = new FormData(form);
                if (extraFields) {
                    Object.entries(extraFields).forEach(([k, v]) => fd.set(k, v));
                }

                const res = await fetch(form.action, {
                    method: 'POST',
                    body: fd,
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                const data = await res.json().catch(() => ({}));

                if (res.ok && data && data.redirect_url) {
                    window.location.href = data.redirect_url;
                    return;
                }

                const message = data?.message || 'Nao foi possivel iniciar o pagamento agora. Tente novamente.';

                if (res.status === 422 && data?.requires_tax_id) {
                    pendingForm = form;
                    showModal(form.getAttribute('data-plan-name') || '');
                    return;
                }

                alert(message);
            }

            document.querySelectorAll('[data-billing-subscribe-form]').forEach((form) => {
                form.addEventListener('submit', (e) => {
                    e.preventDefault();
                    submitSubscribe(form);
                });
            });

            confirmButton.addEventListener('click', async () => {
                if (!pendingForm) {
                    hideModal();
                    return;
                }

                confirmButton.disabled = true;
                if (loadingEl) loadingEl.style.display = 'block';

                const taxId = (taxInput.value || '').trim();

                try {
                    await submitSubscribe(pendingForm, { tax_id: taxId });
                } catch (err) {
                    if (errorEl) {
                        errorEl.textContent = 'Falha ao iniciar o checkout. Tente novamente.';
                        errorEl.classList.remove('hidden');
                    }
                    confirmButton.disabled = false;
                    if (loadingEl) loadingEl.style.display = 'none';
                }
            });
        })();
    </script>
</x-layouts.checkout>


