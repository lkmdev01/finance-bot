@php
    $stepOneFields = ['name', 'email', 'email_confirmation', 'phone_number', 'password', 'password_confirmation', 'terms'];
    $stepTwoFields = ['category_setup'];
    $stepThreeFields = ['activation_code'];

    $initialStep = 1;

    if ($errors->any()) {
        if (collect($stepThreeFields)->contains(fn (string $field) => $errors->has($field))) {
            $initialStep = 3;
        } elseif (collect($stepTwoFields)->contains(fn (string $field) => $errors->has($field))) {
            $initialStep = 2;
        } elseif (collect($stepOneFields)->contains(fn (string $field) => $errors->has($field))) {
            $initialStep = 1;
        }
    }
@endphp

<x-layouts.auth.immersive>
    <div
        id="register-wizard"
        data-initial-step="{{ $initialStep }}"
        data-initial-category="{{ old('category_setup', 'recommended') }}"
        class="space-y-6"
    >
        <div class="space-y-3">
            <p class="text-sm font-semibold uppercase tracking-[0.24em] text-fuchsia-300" data-register-step-indicator>
                Passo {{ $initialStep }} de 3
            </p>

            <div data-register-copy="1" class="space-y-3 {{ $initialStep === 1 ? 'block' : 'hidden' }}">
                <h1 class="text-3xl font-black tracking-tight text-white md:text-[2.6rem]">Dados da conta</h1>
                <p class="max-w-2xl text-base leading-7 text-slate-300">
                    Crie sua conta com o número que você realmente vai usar no WhatsApp. É ele que o sistema vai reconhecer depois.
                </p>
            </div>

            <div data-register-copy="2" class="space-y-3 {{ $initialStep === 2 ? 'block' : 'hidden' }}">
                <h1 class="text-3xl font-black tracking-tight text-white md:text-[2.6rem]">Configuração inicial</h1>
                <p class="max-w-2xl text-base leading-7 text-slate-300">
                    Escolha o jeito mais confortável de começar para já entrar com o painel pronto para uso.
                </p>
            </div>

            <div data-register-copy="3" class="space-y-3 {{ $initialStep === 3 ? 'block' : 'hidden' }}">
                <h1 class="text-3xl font-black tracking-tight text-white md:text-[2.6rem]">Finalização</h1>
                <p class="max-w-2xl text-base leading-7 text-slate-300">
                    Envie o código abaixo no nosso WhatsApp oficial. Assim que ele bater com o que foi gerado, seu número fica ativado e a conta pode ser concluída.
                </p>
            </div>

            <div class="h-1.5 overflow-hidden rounded-full bg-white/10">
                <div
                    data-register-progress
                    class="h-full rounded-full bg-[linear-gradient(90deg,#a855f7,#ec4899)] transition-all duration-300"
                    style="width: {{ $initialStep === 1 ? '33.333%' : ($initialStep === 2 ? '66.666%' : '100%') }};"
                ></div>
            </div>
        </div>

        @if ($errors->any())
            <div class="rounded-2xl border border-rose-400/30 bg-rose-500/10 px-5 py-4 text-sm text-rose-100">
                <p class="font-semibold">Ainda faltam alguns ajustes.</p>
                <ul class="mt-2 space-y-1 text-rose-100/90">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <x-auth-session-status class="rounded-2xl border border-emerald-400/20 bg-emerald-500/10 px-5 py-4 text-sm text-emerald-100" :status="session('status')" />

        <form method="POST" action="{{ route('register.store') }}" class="space-y-6" data-register-form>
            @csrf

            <input type="hidden" name="category_setup" value="{{ old('category_setup', 'recommended') }}" data-category-input />
            <input type="hidden" name="activation_code" value="{{ $activationCode }}" />

            <div data-step-panel="1" class="space-y-6 {{ $initialStep === 1 ? 'block' : 'hidden' }}">
                <div class="grid gap-5 md:grid-cols-2">
                    <div class="space-y-2 md:col-span-2">
                        <label for="name" class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-300">Nome completo</label>
                        <flux:input
                            id="name"
                            name="name"
                            :value="old('name')"
                            type="text"
                            required
                            autofocus
                            autocomplete="name"
                            placeholder="Ex: João Silva"
                        />
                    </div>

                    <div class="space-y-2">
                        <label for="email" class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-300">E-mail</label>
                        <flux:input
                            id="email"
                            name="email"
                            :value="old('email')"
                            type="email"
                            required
                            autocomplete="email"
                            placeholder="seu@email.com"
                        />
                    </div>

                    <div class="space-y-2">
                        <label for="email_confirmation" class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-300">Confirmar e-mail</label>
                        <flux:input
                            id="email_confirmation"
                            name="email_confirmation"
                            :value="old('email_confirmation')"
                            type="email"
                            required
                            autocomplete="email"
                            placeholder="Confirme seu e-mail"
                        />
                    </div>

                    <div class="space-y-2 md:col-span-2">
                        <label for="phone_number" class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-300">Telefone / WhatsApp</label>
                        <flux:input
                            id="phone_number"
                            name="phone_number"
                            :value="old('phone_number')"
                            type="tel"
                            required
                            autocomplete="tel"
                            placeholder="(13) 97605-4715"
                            data-phone-input
                        />
                        <p class="text-sm leading-6 text-slate-400">
                            Use o mesmo número que você vai usar para falar com o robô. É isso que permite o reconhecimento automático.
                        </p>
                    </div>

                    <div class="space-y-2">
                        <label for="password" class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-300">Senha</label>
                        <flux:input
                            id="password"
                            name="password"
                            type="password"
                            required
                            autocomplete="new-password"
                            placeholder="Crie sua senha"
                            viewable
                        />
                    </div>

                    <div class="space-y-2">
                        <label for="password_confirmation" class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-300">Confirmar senha</label>
                        <flux:input
                            id="password_confirmation"
                            name="password_confirmation"
                            type="password"
                            required
                            autocomplete="new-password"
                            placeholder="Repita sua senha"
                            viewable
                        />
                    </div>
                </div>

                <label class="flex items-start gap-3 rounded-2xl border border-white/8 bg-white/5 px-4 py-4">
                    <input
                        id="terms"
                        name="terms"
                        type="checkbox"
                        value="1"
                        @checked(old('terms'))
                        class="mt-1 h-5 w-5 rounded border-white/20 bg-transparent text-fuchsia-500 focus:ring-fuchsia-400"
                    />
                    <span class="text-sm leading-7 text-slate-300">
                        Li e concordo com os <span class="font-semibold text-fuchsia-200">termos de uso</span> e a
                        <span class="font-semibold text-fuchsia-200">política de privacidade</span>.
                    </span>
                </label>

                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div class="space-y-2">
                        <p class="text-sm text-slate-400">Prefere entrar com sua conta Google?</p>
                        <flux:button :href="route('google.redirect')" variant="ghost" class="w-full md:w-auto" icon="arrow-top-right-on-square">
                            Continuar com Google
                        </flux:button>
                    </div>

                    <flux:button type="button" variant="primary" class="w-full md:w-auto md:min-w-48" data-register-next="2">
                        Prosseguir
                    </flux:button>
                </div>
            </div>

            <div data-step-panel="2" class="space-y-6 {{ $initialStep === 2 ? 'block' : 'hidden' }}">
                <div class="rounded-2xl border border-white/8 bg-white/5 px-5 py-4 text-sm leading-7 text-slate-300">
                    Seu teste Pro já começa com tudo liberado. Escolha se quer iniciar com categorias prontas ou montar tudo do seu jeito.
                </div>

                <div class="space-y-4">
                    <button
                        type="button"
                        data-category-option="recommended"
                        class="w-full rounded-[24px] border border-fuchsia-400/50 bg-[linear-gradient(135deg,rgba(168,85,247,0.24),rgba(236,72,153,0.18))] px-5 py-5 text-left transition"
                    >
                        <div class="flex items-start gap-4">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-fuchsia-300 bg-fuchsia-400/25 text-white" data-category-check="recommended">
                                <span>✓</span>
                            </div>
                            <div>
                                <p class="text-xl font-bold text-white">Usar categorias recomendadas</p>
                                <p class="mt-2 max-w-2xl text-sm leading-7 text-slate-300">
                                    Comece com um plano de contas pronto para gastos do dia a dia, receitas e organização inicial do painel.
                                </p>
                            </div>
                        </div>
                    </button>

                    <button
                        type="button"
                        data-category-option="custom"
                        class="w-full rounded-[24px] border border-white/8 bg-white/5 px-5 py-5 text-left transition"
                    >
                        <div class="flex items-start gap-4">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-white/20 text-transparent" data-category-check="custom">
                                <span>✓</span>
                            </div>
                            <div>
                                <p class="text-xl font-bold text-white">Eu mesmo quero cadastrar</p>
                                <p class="mt-2 max-w-2xl text-sm leading-7 text-slate-300">
                                    Comece com a conta limpa e crie suas próprias categorias depois, dentro do painel.
                                </p>
                            </div>
                        </div>
                    </button>
                </div>

                <div class="grid gap-4 md:grid-cols-3">
                    <div class="rounded-[20px] border border-white/8 bg-white/5 p-5">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-fuchsia-200">1. Reconhecimento</p>
                        <p class="mt-3 text-lg font-bold text-white">Seu número entra preparado</p>
                        <p class="mt-2 text-sm leading-6 text-slate-300">Usamos o número do passo 1 para validar a ativação com o WhatsApp certo.</p>
                    </div>
                    <div class="rounded-[20px] border border-white/8 bg-white/5 p-5">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-fuchsia-200">2. Código único</p>
                        <p class="mt-3 text-lg font-bold text-white">Você envia uma mensagem</p>
                        <p class="mt-2 text-sm leading-6 text-slate-300">O passo final gera um código exclusivo e já abre a conversa oficial com ele preenchido.</p>
                    </div>
                    <div class="rounded-[20px] border border-white/8 bg-white/5 p-5">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-fuchsia-200">3. Conta ativa</p>
                        <p class="mt-3 text-lg font-bold text-white">Cadastro concluído de verdade</p>
                        <p class="mt-2 text-sm leading-6 text-slate-300">Quando o bot confirmar o código, seu número fica ativado e a conta pode ser finalizada.</p>
                    </div>
                </div>

                <div class="flex flex-col-reverse gap-4 md:flex-row md:items-center md:justify-between">
                    <flux:button type="button" variant="ghost" class="w-full md:w-auto" data-register-prev="1">
                        Voltar
                    </flux:button>

                    <flux:button type="button" variant="primary" class="w-full md:w-auto md:min-w-48" data-register-next="3">
                        Continuar
                    </flux:button>
                </div>
            </div>

            <div data-step-panel="3" class="space-y-6 {{ $initialStep === 3 ? 'block' : 'hidden' }}">
                <div class="mx-auto flex max-w-xl flex-col items-center text-center">
                    <div class="flex h-20 w-20 items-center justify-center rounded-full border border-emerald-400/15 bg-emerald-500/10 text-4xl">
                        💬
                    </div>

                    <h2 class="mt-6 text-3xl font-black tracking-tight text-white">Conecte seu WhatsApp</h2>
                    <p class="mt-3 text-base leading-7 text-slate-300">
                        Use o mesmo WhatsApp informado no cadastro e envie esse código para a nossa conta oficial. O bot vai responder confirmando a conexão.
                    </p>

                    <div class="mt-6 w-full rounded-[24px] border border-white/8 bg-[#0d0d12] px-6 py-6 text-left shadow-[0_20px_60px_rgba(0,0,0,0.25)]">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.26em] text-slate-500">Seu código único</p>
                                <p class="mt-4 font-mono text-3xl font-bold tracking-[0.18em] text-white md:text-[2.5rem]">{{ $activationCode }}</p>
                            </div>

                            <button
                                type="button"
                                class="inline-flex h-11 w-11 items-center justify-center rounded-2xl border border-white/10 bg-white/5 text-slate-300 transition hover:border-white/20 hover:text-white"
                                data-copy-activation-code
                                data-code="{{ $activationCode }}"
                                aria-label="Copiar código"
                            >
                                ⧉
                            </button>
                        </div>
                    </div>

                    <div class="mt-5 w-full rounded-[20px] border border-emerald-400/15 bg-emerald-500/8 px-5 py-4 text-left text-sm leading-7 text-emerald-100">
                        Depois de enviar, você deve receber algo como: <span class="font-semibold">✅ WhatsApp conectado com sucesso! Seu número foi atualizado.</span>
                    </div>

                    <a
                        href="{{ $activationWhatsAppUrl }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="mt-6 inline-flex w-full items-center justify-center gap-3 rounded-2xl bg-emerald-600 px-5 py-4 text-base font-semibold text-white transition hover:bg-emerald-500"
                    >
                        Enviar código no WhatsApp
                        <span aria-hidden="true">↗</span>
                    </a>
                </div>

                <div class="flex flex-col-reverse gap-4 md:flex-row md:items-center md:justify-between">
                    <div class="space-x-1 text-center text-sm text-slate-400 md:text-left">
                        <span>Já tem uma conta?</span>
                        <flux:link :href="route('login')" wire:navigate>Entrar</flux:link>
                    </div>

                    <div class="flex w-full flex-col gap-3 md:w-auto md:flex-row">
                        <flux:button type="button" variant="ghost" class="w-full md:w-auto" data-register-prev="2">
                            Voltar
                        </flux:button>

                        <flux:button type="submit" variant="primary" class="w-full md:min-w-56" data-test="register-user-button">
                            Já enviei o código
                        </flux:button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</x-layouts.auth.immersive>

<script>
    (() => {
        const wizard = document.getElementById('register-wizard');
        if (!wizard) return;

        const form = wizard.querySelector('[data-register-form]');
        const progress = wizard.querySelector('[data-register-progress]');
        const indicator = wizard.querySelector('[data-register-step-indicator]');
        const copyBlocks = wizard.querySelectorAll('[data-register-copy]');
        const panels = wizard.querySelectorAll('[data-step-panel]');
        const categoryInput = wizard.querySelector('[data-category-input]');
        const phoneInput = wizard.querySelector('[data-phone-input]');
        const categoryOptions = wizard.querySelectorAll('[data-category-option]');
        const copyButton = wizard.querySelector('[data-copy-activation-code]');

        let currentStep = Number(wizard.dataset.initialStep || 1);

        const updateStepUI = () => {
            indicator.textContent = `Passo ${currentStep} de 3`;

            copyBlocks.forEach((block) => {
                block.classList.toggle('hidden', Number(block.dataset.registerCopy) !== currentStep);
            });

            panels.forEach((panel) => {
                panel.classList.toggle('hidden', Number(panel.dataset.stepPanel) !== currentStep);
            });

            const width = currentStep === 1 ? '33.333%' : currentStep === 2 ? '66.666%' : '100%';
            progress.style.width = width;
        };

        const updateCategoryUI = () => {
            const selected = categoryInput.value || 'recommended';

            categoryOptions.forEach((option) => {
                const isSelected = option.dataset.categoryOption === selected;
                option.className = isSelected
                    ? 'w-full rounded-[24px] border border-fuchsia-400/50 bg-[linear-gradient(135deg,rgba(168,85,247,0.24),rgba(236,72,153,0.18))] px-5 py-5 text-left transition'
                    : 'w-full rounded-[24px] border border-white/8 bg-white/5 px-5 py-5 text-left transition';

                const check = option.querySelector('[data-category-check]');
                if (check) {
                    check.className = isSelected
                        ? 'flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-fuchsia-300 bg-fuchsia-400/25 text-white'
                        : 'flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-white/20 text-transparent';
                }
            });
        };

        const formatPhone = (value) => {
            const digits = value.replace(/\D/g, '').slice(0, 11);
            if (!digits) return '';
            if (digits.length <= 2) return `(${digits}`;
            if (digits.length <= 7) return `(${digits.slice(0, 2)}) ${digits.slice(2)}`;
            if (digits.length <= 10) return `(${digits.slice(0, 2)}) ${digits.slice(2, 6)}-${digits.slice(6)}`;
            return `(${digits.slice(0, 2)}) ${digits.slice(2, 7)}-${digits.slice(7)}`;
        };

        const validateStepOne = () => {
            const requiredFields = ['name', 'email', 'email_confirmation', 'phone_number', 'password', 'password_confirmation'];

            for (const fieldName of requiredFields) {
                const field = form.querySelector(`[name="${fieldName}"]`);
                if (!field) continue;

                field.setCustomValidity('');

                if (!field.value.trim()) {
                    field.reportValidity();
                    field.focus();
                    return false;
                }
            }

            const email = form.querySelector('[name="email"]');
            const emailConfirmation = form.querySelector('[name="email_confirmation"]');
            if (email.value.trim() !== emailConfirmation.value.trim()) {
                emailConfirmation.setCustomValidity('Os e-mails precisam ser iguais.');
                emailConfirmation.reportValidity();
                emailConfirmation.focus();
                return false;
            }

            const password = form.querySelector('[name="password"]');
            const passwordConfirmation = form.querySelector('[name="password_confirmation"]');
            if (password.value !== passwordConfirmation.value) {
                passwordConfirmation.setCustomValidity('As senhas precisam ser iguais.');
                passwordConfirmation.reportValidity();
                passwordConfirmation.focus();
                return false;
            }

            const phoneDigits = phoneInput.value.replace(/\D/g, '');
            if (phoneDigits.length < 10) {
                phoneInput.setCustomValidity('Informe um número válido com DDD.');
                phoneInput.reportValidity();
                phoneInput.focus();
                return false;
            }

            const terms = form.querySelector('[name="terms"]');
            if (!terms.checked) {
                terms.setCustomValidity('Você precisa concordar para continuar.');
                terms.reportValidity();
                terms.focus();
                return false;
            }

            return true;
        };

        wizard.querySelectorAll('[data-register-next]').forEach((button) => {
            button.addEventListener('click', () => {
                const nextStep = Number(button.dataset.registerNext);

                if (nextStep === 2 && !validateStepOne()) {
                    return;
                }

                currentStep = nextStep;
                updateStepUI();
            });
        });

        wizard.querySelectorAll('[data-register-prev]').forEach((button) => {
            button.addEventListener('click', () => {
                currentStep = Number(button.dataset.registerPrev);
                updateStepUI();
            });
        });

        categoryOptions.forEach((option) => {
            option.addEventListener('click', () => {
                categoryInput.value = option.dataset.categoryOption || 'recommended';
                updateCategoryUI();
            });
        });

        if (phoneInput) {
            phoneInput.addEventListener('input', () => {
                phoneInput.value = formatPhone(phoneInput.value);
                phoneInput.setCustomValidity('');
            });
        }

        ['email_confirmation', 'password_confirmation'].forEach((fieldName) => {
            const field = form.querySelector(`[name="${fieldName}"]`);
            field?.addEventListener('input', () => field.setCustomValidity(''));
        });

        form.querySelector('[name="terms"]')?.addEventListener('change', (event) => {
            event.target.setCustomValidity('');
        });

        copyButton?.addEventListener('click', async () => {
            const code = copyButton.dataset.code;
            if (!code) return;

            try {
                await navigator.clipboard.writeText(code);
                const original = copyButton.textContent;
                copyButton.textContent = '✓';
                setTimeout(() => {
                    copyButton.textContent = original;
                }, 1400);
            } catch {
                // Silently ignore clipboard failures.
            }
        });

        updateCategoryUI();
        updateStepUI();
    })();
</script>
