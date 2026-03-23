<x-layouts.auth>
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Entrar na sua conta')" :description="__('Insira seu e-mail e senha abaixo para entrar')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-6">
            @csrf

            <!-- Email Address -->
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

            <!-- Password -->
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

            <!-- Remember Me -->
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
