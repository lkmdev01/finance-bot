<x-layouts.auth>
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Criar uma conta')" :description="__('Insira seus dados abaixo para criar sua conta')" />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <flux:button :href="route('google.redirect')" variant="ghost" class="w-full" icon="arrow-top-right-on-square">
            Continuar com Google
        </flux:button>

        <div class="relative">
            <div class="absolute inset-0 flex items-center">
                <div class="w-full border-t border-zinc-200 dark:border-zinc-700"></div>
            </div>
            <div class="relative flex justify-center text-xs uppercase">
                <span class="bg-white px-3 text-zinc-500 dark:bg-zinc-900 dark:text-zinc-400">ou cadastre-se com e-mail</span>
            </div>
        </div>

        <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-6">
            @csrf
            <flux:input
                name="name"
                :label="__('Nome')"
                :value="old('name')"
                type="text"
                required
                autofocus
                autocomplete="name"
                :placeholder="__('Nome completo')"
            />

            <flux:input
                name="email"
                :label="__('E-mail')"
                :value="old('email')"
                type="email"
                required
                autocomplete="email"
                placeholder="email@exemplo.com.br"
            />

            <flux:input
                name="password"
                :label="__('Senha')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Sua senha')"
                viewable
            />

            <flux:input
                name="password_confirmation"
                :label="__('Confirmar Senha')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Confirme sua senha')"
                viewable
            />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full" data-test="register-user-button">
                    {{ __('Criar conta') }}
                </flux:button>
            </div>
        </form>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-600 dark:text-zinc-400">
            <span>{{ __('Já tem uma conta?') }}</span>
            <flux:link :href="route('login')" wire:navigate>{{ __('Entrar') }}</flux:link>
        </div>
    </div>
</x-layouts.auth>
