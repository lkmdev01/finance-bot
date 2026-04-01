<x-layouts.auth>
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Entrar na sua conta')" :description="__('Insira seu e-mail e senha abaixo para entrar')" />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <flux:button :href="route('google.redirect')" variant="ghost" class="w-full" icon="arrow-top-right-on-square">
            Entrar com Google
        </flux:button>

        <div class="relative">
            <div class="absolute inset-0 flex items-center">
                <div class="w-full border-t border-zinc-200 dark:border-zinc-700"></div>
            </div>
            <div class="relative flex justify-center text-xs uppercase">
                <span class="bg-white px-3 text-zinc-500 dark:bg-zinc-900 dark:text-zinc-400">ou continue com e-mail</span>
            </div>
        </div>

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-6">
            @csrf

            <flux:input
                name="email"
                :label="__('E-mail')"
                :value="old('email')"
                type="email"
                required
                autofocus
                autocomplete="email"
                placeholder="email@exemplo.com.br"
            />

            <div class="relative">
                <flux:input
                    name="password"
                    :label="__('Senha')"
                    type="password"
                    required
                    autocomplete="current-password"
                    :placeholder="__('Sua senha')"
                    viewable
                />

                @if (Route::has('password.request'))
                    <flux:link class="absolute top-0 text-sm end-0" :href="route('password.request')" wire:navigate>
                        {{ __('Esqueceu sua senha?') }}
                    </flux:link>
                @endif
            </div>

            <flux:checkbox name="remember" :label="__('Lembrar de mim')" :checked="old('remember')" />

            <div class="flex items-center justify-end">
                <flux:button variant="primary" type="submit" class="w-full" data-test="login-button">
                    {{ __('Entrar') }}
                </flux:button>
            </div>
        </form>

        @if (Route::has('register'))
            <div class="space-x-1 text-sm text-center rtl:space-x-reverse text-zinc-600 dark:text-zinc-400">
                <span>{{ __('Ainda não tem uma conta?') }}</span>
                <flux:link :href="route('register')" wire:navigate>{{ __('Cadastrar-se') }}</flux:link>
            </div>
        @endif
    </div>
</x-layouts.auth>
