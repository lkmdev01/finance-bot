<x-layouts.auth.immersive>
    <div class="space-y-6">
        <div class="space-y-3">
            <p class="text-sm font-semibold uppercase tracking-[0.24em] text-fuchsia-300">Ativacao do WhatsApp</p>
            <h1 class="text-3xl font-black tracking-tight text-white md:text-[2.6rem]">Conecte seu numero</h1>
            <p class="max-w-2xl text-base leading-7 text-slate-300">
                Para concluir sua entrada com Google, precisamos validar o WhatsApp que vai conversar com o InovaFinance.
            </p>
        </div>

        <x-auth-session-status class="rounded-2xl border border-emerald-400/20 bg-emerald-500/10 px-5 py-4 text-sm text-emerald-100" :status="session('status')" />

        @if ($errors->any())
            <div class="rounded-2xl border border-rose-400/30 bg-rose-500/10 px-5 py-4 text-sm text-rose-100">
                <p class="font-semibold">Ainda falta concluir a ativacao.</p>
                <ul class="mt-2 space-y-1 text-rose-100/90">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (! $activationCode)
            <form method="POST" action="{{ route('whatsapp.activation.phone') }}" class="space-y-6">
                @csrf

                <div class="space-y-2">
                    <label for="phone_number" class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-300">Telefone / WhatsApp</label>
                    <flux:input
                        id="phone_number"
                        name="phone_number"
                        :value="old('phone_number', $displayPhoneNumber)"
                        type="tel"
                        required
                        autofocus
                        autocomplete="tel"
                        placeholder="(13) 97605-4715"
                    />
                    <p class="text-sm leading-6 text-slate-400">
                        Use o mesmo numero que voce vai usar para mandar a mensagem de ativacao.
                    </p>
                </div>

                <div class="rounded-2xl border border-white/8 bg-white/5 px-5 py-4 text-sm leading-7 text-slate-300">
                    <p class="font-semibold text-white">Quer agilizar a ativacao?</p>
                    <p class="mt-1">
                        Abra o WhatsApp oficial do InovaFinance para falar com a gente. Depois de salvar seu numero aqui, voce conclui a ativacao no mesmo fluxo.
                    </p>
                    <a
                        href="{{ $supportWhatsAppUrl }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-emerald-300 transition hover:text-emerald-200"
                    >
                        Abrir WhatsApp oficial
                        <span aria-hidden="true">-></span>
                    </a>
                </div>

                <div class="flex justify-end">
                    <flux:button type="submit" variant="primary" class="w-full md:w-auto md:min-w-56">
                        Gerar codigo de ativacao
                    </flux:button>
                </div>
            </form>
        @else
            <div class="space-y-6">
                <div class="rounded-2xl border border-white/8 bg-white/5 px-5 py-4 text-sm leading-7 text-slate-300">
                    Numero vinculado nesta etapa: <span class="font-semibold text-white">{{ $displayPhoneNumber }}</span>
                </div>

                <div class="mx-auto flex max-w-xl flex-col items-center text-center">
                    <div class="flex h-20 w-20 items-center justify-center rounded-full border border-emerald-400/15 bg-emerald-500/10 text-4xl">
                        #
                    </div>

                    <h2 class="mt-6 text-3xl font-black tracking-tight text-white">Envie seu codigo</h2>
                    <p class="mt-3 text-base leading-7 text-slate-300">
                        Abra o WhatsApp oficial do InovaFinance e mande a mensagem abaixo usando o mesmo numero informado.
                    </p>

                    <a
                        href="{{ $supportWhatsAppUrl }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-emerald-300 transition hover:text-emerald-200"
                    >
                        Abrir conversa oficial
                        <span aria-hidden="true">-></span>
                    </a>

                    <div class="mt-6 w-full rounded-[24px] border border-white/8 bg-[#0d0d12] px-6 py-6 text-left shadow-[0_20px_60px_rgba(0,0,0,0.25)]">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.26em] text-slate-500">Seu codigo unico</p>
                                <p class="mt-4 font-mono text-3xl font-bold tracking-[0.18em] text-white md:text-[2.5rem]">{{ $activationCode }}</p>
                            </div>

                            <button
                                type="button"
                                class="inline-flex h-11 w-11 items-center justify-center rounded-2xl border border-white/10 bg-white/5 text-slate-300 transition hover:border-white/20 hover:text-white"
                                data-copy-activation-code
                                data-code="{{ $activationCode }}"
                                aria-label="Copiar codigo"
                            >
                                []
                            </button>
                        </div>
                    </div>

                    <div class="mt-5 w-full rounded-[20px] border border-emerald-400/15 bg-emerald-500/8 px-5 py-4 text-left text-sm leading-7 text-emerald-100">
                        Voce deve receber a resposta: <span class="font-semibold">WhatsApp conectado com sucesso. Seu numero foi atualizado.</span>
                    </div>

                    <a
                        href="{{ $activationWhatsAppUrl }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="mt-6 inline-flex w-full items-center justify-center gap-3 rounded-2xl bg-emerald-600 px-5 py-4 text-base font-semibold text-white transition hover:bg-emerald-500"
                    >
                        Enviar codigo no WhatsApp
                        <span aria-hidden="true">-></span>
                    </a>
                </div>

                <form method="POST" action="{{ route('whatsapp.activation.complete') }}" class="flex justify-end">
                    @csrf

                    <flux:button type="submit" variant="primary" class="w-full md:w-auto md:min-w-56">
                        Ja enviei o codigo
                    </flux:button>
                </form>
            </div>
        @endif
    </div>

    <script>
        (() => {
            const copyButton = document.querySelector('[data-copy-activation-code]');
            if (!copyButton) return;

            copyButton.addEventListener('click', async () => {
                const code = copyButton.dataset.code;
                if (!code) return;

                try {
                    await navigator.clipboard.writeText(code);
                    const original = copyButton.textContent;
                    copyButton.textContent = 'OK';
                    setTimeout(() => {
                        copyButton.textContent = original;
                    }, 1400);
                } catch {
                    // noop
                }
            });
        })();
    </script>
</x-layouts.auth.immersive>
