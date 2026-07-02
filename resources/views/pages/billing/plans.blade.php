<x-layouts.checkout title="Planos" :back-href="route('dashboard')">
    @php
        $user = auth()->user();
        $hasSubscriptionFlow = collect($plans ?? [])->contains(fn ($plan) => ($plan['checkout_flow'] ?? 'checkout') === 'subscription' && (($plan['price_cents'] ?? 0) > 0));
        $nextBillingDate = $cancelableSubscription && $user->billing_access_ends_at
            ? $user->billing_access_ends_at->format('d/m/Y')
            : null;
    @endphp

    <div class="space-y-8">
        @if (!empty($checkoutReturned))
            <div class="rounded-2xl border border-emerald-400/20 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100">
                Recebemos seu retorno do checkout. Se o pagamento ja foi concluido, a liberacao do plano acontece automaticamente em instantes.
            </div>
        @endif

        @if (session('status'))
            <div class="rounded-2xl border border-sky-400/20 bg-sky-500/10 px-4 py-3 text-sm text-sky-100">
                {{ session('status') }}
            </div>
        @endif

        <section class="rounded-[2rem] border border-yellow-300/20 bg-gradient-to-br from-slate-950 via-emerald-950/35 to-blue-950/70 p-6 shadow-[0_24px_80px_rgba(2,6,23,0.34)] sm:p-8">
            <div class="grid gap-8 lg:grid-cols-[1.2fr_0.8fr] lg:items-center">
                <div>
                    <div class="inline-flex items-center gap-2 rounded-full border border-yellow-300/35 bg-yellow-300/10 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.22em] text-yellow-100">
                        Brasil na Copa • 30% OFF
                    </div>
                    <h1 class="mt-5 text-4xl font-black tracking-tight text-white sm:text-5xl">Oferta especial para liberar o InovaFinance completo.</h1>
                    <p class="mt-4 max-w-2xl text-sm leading-7 text-slate-300 sm:text-base">
                        Enquanto o Brasil estiver na Copa, o Pro Mensal sai por R$ 19,97/mes. Ele libera relatorios avancados, projecoes financeiras e a experiencia completa do {{ config('mascot.name', 'Orbita') }}.
                    </p>
                    <p class="mt-2 max-w-2xl text-sm leading-7 text-slate-400">
                        @if ($hasSubscriptionFlow)
                            A oferta Pro renova automaticamente no cartao e pode ser cancelada pelo painel.
                        @else
                            O pagamento libera o acesso pelo periodo escolhido. Nao ha renovacao automatica nesta etapa.
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
                                Teste gratis ate {{ $user->trial_ends_at?->format('d/m/Y') }}
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

                    <div class="mt-4 rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-slate-200">
                        <p class="font-semibold text-white">Como esse plano funciona</p>
                        <p class="mt-1">{{ $currentPlan['billing_mode_label'] ?? 'Pagamento unico' }} • {{ $currentPlan['billing_mode_description'] ?? '' }}</p>
                    </div>

                    @if ($cancelableSubscription)
                        <div class="mt-4 grid gap-3 sm:grid-cols-2">
                            <div class="rounded-2xl border border-emerald-300/15 bg-emerald-400/10 px-4 py-3">
                                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-100/80">Assinatura</p>
                                <p class="mt-1 text-sm font-semibold text-white">
                                    {{ str($cancelableSubscription->status ?: 'ACTIVE')->replace('_', ' ')->title() }}
                                </p>
                            </div>
                            <div class="rounded-2xl border border-indigo-300/15 bg-indigo-400/10 px-4 py-3">
                                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-indigo-100/80">Proxima cobranca</p>
                                <p class="mt-1 text-sm font-semibold text-white">
                                    {{ $nextBillingDate ? $nextBillingDate : 'Aguardando confirmacao' }}
                                </p>
                            </div>
                        </div>

                        <div class="mt-6">
                            <button type="button" class="inline-flex w-full items-center justify-center rounded-xl border border-rose-300/20 bg-rose-500/10 px-4 py-3 text-sm font-semibold text-rose-100 transition hover:bg-rose-500/15" data-billing-cancel-open>
                                Cancelar assinatura
                            </button>
                            <p class="mt-2 text-xs leading-6 text-slate-400">
                                Cancelamento e imediato e irreversivel. Seu acesso premium sera encerrado na hora.
                            </p>
                        </div>
                    @elseif (in_array($user->billing_plan_status, ['active', 'renewed'], true) && filled($user->billing_plan_code) && $user->billing_plan_code !== config('billing.default_plan', 'starter'))
                        <p class="mt-6 text-xs leading-6 text-slate-400">
                            Seu plano atual nao esta vinculado a uma assinatura recorrente cancelavel por aqui.
                        </p>
                    @endif
                </div>
            </div>
        </section>

        <section class="grid gap-6 lg:grid-cols-2">
            @foreach ($plans as $plan)
                @php
                    $isCurrent = ($user->billing_plan_code ?: config('billing.default_plan', 'starter')) === $plan['code'] && (($plan['price_cents'] === 0) || $user->hasActivePaidPlan());
                @endphp

                <article class="rounded-[2rem] border {{ $plan['highlight'] ? 'border-yellow-300/30 bg-gradient-to-br from-emerald-500/10 via-yellow-300/10 to-blue-500/10' : 'border-white/10 bg-slate-950/80' }} p-6 shadow-[0_16px_60px_rgba(2,6,23,0.28)]">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">{{ $plan['badge'] }}</p>
                            <h2 class="mt-3 text-2xl font-black text-white">{{ $plan['name'] }}</h2>
                        </div>
                        <div class="text-right">
                            @if ($plan['highlight'])
                                <div class="text-xs text-slate-500 line-through">R$ 29,90</div>
                            @endif
                            <div class="rounded-full border {{ $plan['highlight'] ? 'border-yellow-300/35 bg-yellow-300/10 text-yellow-100' : 'border-white/10 bg-white/5 text-white' }} px-3 py-1 text-sm font-semibold">
                                {{ $plan['formatted_price'] }}
                            </div>
                        </div>
                    </div>

                    <p class="mt-4 text-sm leading-7 text-slate-300">{{ $plan['description'] }}</p>

                    @if ($plan['highlight'])
                        <div class="mt-4 rounded-2xl border border-yellow-300/20 bg-slate-950/45 px-4 py-3 text-sm text-yellow-50">
                            <p class="font-semibold">Campanha Brasil na Copa</p>
                            <p class="mt-1 text-xs leading-5 text-slate-300">30% OFF especial enquanto a campanha estiver ativa. Depois da oferta, novos clientes podem voltar ao preco normal.</p>
                        </div>
                    @endif

                    <div class="mt-4 flex flex-wrap gap-2 text-xs font-semibold">
                        <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-slate-200">
                            {{ strtoupper($plan['frequency_label'] ?? 'livre') }}
                        </span>
                        <span class="rounded-full border border-indigo-300/20 bg-indigo-400/10 px-3 py-1 text-indigo-100">
                            {{ $plan['billing_mode_label'] ?? 'Pagamento unico' }}
                        </span>
                    </div>

                    <p class="mt-3 text-xs leading-6 text-slate-400">
                        {{ $plan['billing_mode_description'] ?? '' }}
                    </p>

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
                <p class="text-xs leading-6 text-slate-400">A oferta Pro Mensal renova automaticamente no cartao e pode ser cancelada pelo painel.</p>
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

    {{-- Cancelamento de assinatura (AbacatePay cancela na hora). --}}
    <div id="billing-cancel-modal" class="fixed inset-0 z-[999] hidden">
        <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" data-billing-cancel-close></div>
        <div class="relative mx-auto mt-24 w-[92%] max-w-lg rounded-[1.75rem] border border-white/10 bg-slate-950/90 p-6 shadow-[0_24px_90px_rgba(2,6,23,0.55)]">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.22em] text-rose-200/80">Cancelar assinatura</p>
                    <h3 class="mt-2 text-2xl font-black text-white">Confirmar cancelamento</h3>
                    <p class="mt-2 text-sm leading-7 text-slate-300">
                        Este cancelamento e imediato. Nenhuma cobranca futura sera gerada, mas seu acesso premium sera encerrado agora.
                    </p>
                </div>
                <button type="button" class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white hover:bg-white/10" data-billing-cancel-close>
                    Fechar
                </button>
            </div>

            <div class="mt-6 rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-slate-200">
                <p class="font-semibold text-white">Plano atual</p>
                <p class="mt-1">{{ $currentPlan['name'] }}</p>
                <p class="mt-1 text-xs leading-6 text-slate-400">{{ $currentPlan['billing_mode_description'] ?? '' }}</p>
            </div>

            <div class="mt-6 flex flex-col gap-3">
                <button type="button" class="inline-flex w-full items-center justify-center rounded-xl bg-rose-500 px-4 py-3 text-sm font-semibold text-white transition hover:bg-rose-400 disabled:cursor-not-allowed disabled:opacity-60" data-billing-cancel-confirm>
                    Sim, cancelar agora
                </button>
                <button type="button" class="inline-flex w-full items-center justify-center rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-sm font-semibold text-white transition hover:bg-white/10" data-billing-cancel-close>
                    Nao, manter assinatura
                </button>
                <div class="text-center text-xs text-slate-400" data-billing-cancel-loading style="display:none;">Cancelando...</div>
                <p class="hidden text-sm text-rose-300" data-billing-cancel-error></p>
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

    <script>
        (function () {
            const openBtn = document.querySelector('[data-billing-cancel-open]');
            const modal = document.getElementById('billing-cancel-modal');
            const closeBtns = modal?.querySelectorAll('[data-billing-cancel-close]') ?? [];
            const confirmBtn = modal?.querySelector('[data-billing-cancel-confirm]');
            const loadingEl = modal?.querySelector('[data-billing-cancel-loading]');
            const errorEl = modal?.querySelector('[data-billing-cancel-error]');

            if (!openBtn || !modal || !confirmBtn) return;

            function show() {
                if (errorEl) {
                    errorEl.textContent = '';
                    errorEl.classList.add('hidden');
                }
                modal.classList.remove('hidden');
            }

            function hide() {
                modal.classList.add('hidden');
                confirmBtn.disabled = false;
                if (loadingEl) loadingEl.style.display = 'none';
            }

            openBtn.addEventListener('click', show);
            closeBtns.forEach(btn => btn.addEventListener('click', hide));

            confirmBtn.addEventListener('click', async () => {
                confirmBtn.disabled = true;
                if (loadingEl) loadingEl.style.display = 'block';

                try {
                    const res = await fetch(\"{{ route('billing.subscription.cancel') }}\", {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': \"{{ csrf_token() }}\",
                        },
                    });

                    const data = await res.json().catch(() => ({}));

                    if (res.ok && data?.ok) {
                        window.location.href = \"{{ route('billing.plans') }}\";
                        return;
                    }

                    const msg = data?.message || 'Nao foi possivel cancelar sua assinatura agora.';
                    if (errorEl) {
                        errorEl.textContent = msg;
                        errorEl.classList.remove('hidden');
                    } else {
                        alert(msg);
                    }
                } catch (e) {
                    if (errorEl) {
                        errorEl.textContent = 'Falha ao cancelar. Tente novamente.';
                        errorEl.classList.remove('hidden');
                    }
                } finally {
                    confirmBtn.disabled = false;
                    if (loadingEl) loadingEl.style.display = 'none';
                }
            });
        })();
    </script>
</x-layouts.checkout>
