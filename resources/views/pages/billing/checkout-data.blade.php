<x-layouts.checkout title="Confirmar dados de cobranca" :back-href="route('billing.plans')">
    @php
        $user = auth()->user();
        $canContinue = filled($formattedPhoneNumber);
    @endphp

    <div class="mx-auto max-w-3xl space-y-8">
        @if (session('status'))
            <div class="rounded-2xl border border-sky-400/20 bg-sky-500/10 px-4 py-3 text-sm text-sky-100">
                {{ session('status') }}
            </div>
        @endif

        <section class="rounded-[2rem] border border-white/10 bg-gradient-to-br from-slate-950 via-slate-900 to-indigo-950/80 p-6 shadow-[0_24px_80px_rgba(2,6,23,0.34)] sm:p-8">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <div class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-300">
                        Checkout do plano
                    </div>
                    <h1 class="mt-5 text-3xl font-black tracking-tight text-white sm:text-4xl">Confirme seus dados antes de seguir.</h1>
                    <p class="mt-3 max-w-2xl text-sm leading-7 text-slate-300">
                        Vamos abrir o pagamento do plano <span class="font-semibold text-white">{{ $plan['name'] }}</span>. Antes disso, confirme o numero cadastrado e informe seu CPF ou CNPJ.
                    </p>
                </div>

                <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-slate-200">
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Plano</p>
                    <p class="mt-2 text-xl font-black text-white">{{ $plan['name'] }}</p>
                    <p class="mt-1">{{ $plan['formatted_price'] }}</p>
                </div>
            </div>
        </section>

        <section class="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
            <div class="rounded-[1.75rem] border border-white/10 bg-slate-950/80 p-6 shadow-[0_16px_60px_rgba(2,6,23,0.28)]">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Numero cadastrado</p>
                @if ($formattedPhoneNumber)
                    <p class="mt-3 text-2xl font-black text-white">{{ $formattedPhoneNumber }}</p>
                    <p class="mt-2 text-sm leading-7 text-slate-300">Esse numero sera usado como contato da cobranca na AbacatePay.</p>
                    <a href="{{ route('whatsapp.settings') }}" class="mt-5 inline-flex items-center justify-center rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm font-medium text-white transition hover:bg-white/10">
                        Alterar numero
                    </a>
                @else
                    <p class="mt-3 text-base font-semibold text-amber-200">Voce ainda nao configurou um numero de WhatsApp.</p>
                    <p class="mt-2 text-sm leading-7 text-slate-300">Precisamos desse dado antes de abrir o checkout.</p>
                    <a href="{{ route('whatsapp.settings') }}" class="mt-5 inline-flex items-center justify-center rounded-xl border border-amber-300/20 bg-amber-400/10 px-4 py-2 text-sm font-medium text-amber-50 transition hover:bg-amber-400/15">
                        Configurar WhatsApp
                    </a>
                @endif
            </div>

            <div class="rounded-[1.75rem] border border-white/10 bg-slate-950/80 p-6 shadow-[0_16px_60px_rgba(2,6,23,0.28)]">
                <form method="POST" action="{{ route('billing.checkout-data.store', $plan['code']) }}" class="space-y-5">
                    @csrf

                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Documento</p>
                        <flux:input
                            name="tax_id"
                            label="CPF ou CNPJ"
                            type="text"
                            placeholder="000.000.000-00"
                            value="{{ old('tax_id', \App\Support\BrazilTaxId::format($user->tax_id)) }}"
                            hint="A AbacatePay exige esse dado para gerar a cobranca."
                        />
                        @error('tax_id')
                            <p class="mt-2 text-sm text-rose-300">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-slate-300">
                        <p class="font-medium text-white">Resumo</p>
                        <p class="mt-1">Ao continuar, vamos abrir o checkout da AbacatePay em uma nova pagina para concluir o pagamento e liberar o acesso do periodo escolhido.</p>
                    </div>

                    <div class="flex flex-col gap-3">
                        <button
                            type="submit"
                            class="inline-flex w-full items-center justify-center rounded-xl px-4 py-3 text-sm font-semibold transition {{ $canContinue ? 'bg-indigo-500 text-white hover:bg-indigo-400' : 'cursor-not-allowed bg-slate-700 text-slate-300 opacity-70' }}"
                            @if (! $canContinue) disabled @endif
                        >
                            Confirmar dados e continuar
                        </button>
                        <a href="{{ route('billing.plans') }}" class="inline-flex items-center justify-center rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm font-medium text-white transition hover:bg-white/10">
                            Voltar para planos
                        </a>
                    </div>
                </form>
            </div>
        </section>
    </div>
</x-layouts.checkout>
