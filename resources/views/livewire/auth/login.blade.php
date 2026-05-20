<x-layouts.auth.immersive>
    <div class="space-y-7">
        <div class="space-y-4">
            <div class="inline-flex rounded-full border border-fuchsia-400/20 bg-fuchsia-500/10 px-3 py-1 text-xs font-semibold uppercase tracking-[0.28em] text-fuchsia-200">
                Bem-vindo de volta
            </div>

            <div class="space-y-3">
                <h1 class="text-3xl font-black tracking-tight text-white md:text-[2.6rem]">Entre na sua conta</h1>
                <p class="max-w-2xl text-base leading-7 text-slate-300">
                    Retome seu painel, abra o WhatsApp e continue registrando suas finanças sem perder contexto.
                </p>
            </div>
        </div>

        <x-auth-session-status class="rounded-2xl border border-emerald-400/20 bg-emerald-500/10 px-5 py-4 text-sm text-emerald-100" :status="session('status')" />

        @if ($errors->any())
            <div class="rounded-2xl border border-rose-400/30 bg-rose-500/10 px-5 py-4 text-sm text-rose-100">
                <p class="font-semibold">Ainda não deu para entrar.</p>
                <ul class="mt-2 space-y-1 text-rose-100/90">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="grid gap-5 lg:grid-cols-[1.15fr_0.85fr]">
            <div class="rounded-[24px] border border-white/8 bg-black/10 p-5 md:p-6">
                <form method="POST" action="{{ route('login.store') }}" class="space-y-6">
                    @csrf

                    <div class="space-y-2">
                        <label for="email" class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-300">E-mail</label>
                        <flux:input
                            id="email"
                            name="email"
                            :value="old('email')"
                            type="email"
                            required
                            autofocus
                            autocomplete="email"
                            placeholder="email@exemplo.com.br"
                        />
                    </div>

                    <div class="space-y-2">
                        <div class="flex items-center justify-between gap-4">
                            <label for="password" class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-300">Senha</label>

                            @if (Route::has('password.request'))
                                <flux:link class="text-sm" :href="route('password.request')" wire:navigate>
                                    Esqueceu sua senha?
                                </flux:link>
                            @endif
                        </div>

                        <flux:input
                            id="password"
                            name="password"
                            type="password"
                            required
                            autocomplete="current-password"
                            placeholder="Sua senha"
                            viewable
                        />
                    </div>

                    <label class="flex items-center gap-3 rounded-2xl border border-white/8 bg-white/5 px-4 py-4">
                        <input
                            name="remember"
                            type="checkbox"
                            value="1"
                            @checked(old('remember'))
                            class="h-5 w-5 rounded border-white/20 bg-transparent text-fuchsia-500 focus:ring-fuchsia-400"
                        />
                        <span class="text-sm text-slate-300">Lembrar de mim neste dispositivo</span>
                    </label>

                    <flux:button variant="primary" type="submit" class="w-full md:min-h-13" data-test="login-button">
                        Entrar no painel
                    </flux:button>
                </form>
            </div>

            <div class="space-y-4">
                <div class="rounded-[24px] border border-white/8 bg-white/5 p-5 shadow-[0_18px_60px_rgba(0,0,0,0.22)]">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-fuchsia-200">Acesso rápido</p>
                    <p class="mt-3 text-lg font-bold text-white">Continue com Google</p>
                    <p class="mt-2 text-sm leading-6 text-slate-300">
                        Entre com sua conta Google e volte direto para o dashboard com o mesmo contexto do seu teste e do seu WhatsApp.
                    </p>

                    <flux:button :href="route('google.redirect')" variant="ghost" class="mt-5 w-full" icon="arrow-top-right-on-square">
                        Entrar com Google
                    </flux:button>
                </div>

                <div class="rounded-[24px] border border-white/8 bg-white/5 p-5 shadow-[0_18px_60px_rgba(0,0,0,0.22)]">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-fuchsia-200">Novo por aqui?</p>
                    <p class="mt-3 text-lg font-bold text-white">Crie sua conta e ative o WhatsApp</p>
                    <p class="mt-2 text-sm leading-6 text-slate-300">
                        O cadastro guiado termina com o seu número validado. Você já entra com tudo pronto para começar a usar.
                    </p>

                    <flux:button :href="route('register')" variant="primary" class="mt-5 w-full" wire:navigate>
                        Criar conta
                    </flux:button>
                </div>
            </div>
        </div>
    </div>
</x-layouts.auth.immersive>
