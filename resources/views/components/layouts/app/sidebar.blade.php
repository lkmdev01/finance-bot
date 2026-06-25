<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-space-950 text-slate-100 antialiased overflow-x-clip pt-0">
        {{-- Background Effects --}}
        <div class="fixed inset-0 z-0 pointer-events-none">
            <div class="absolute top-[-10%] left-[-10%] w-[100%] h-[100%] blur-gradient opacity-40"></div>
            <div class="absolute bottom-[-10%] right-[-10%] w-[100%] h-[100%] blur-gradient opacity-30"></div>
        </div>

        <flux:sidebar sticky collapsible class="sticky top-0 z-50 border-e border-white/5 bg-black/40 backdrop-blur-xl overflow-x-hidden">
            <flux:sidebar.header class="relative py-6 border-b border-white/5 mb-4 px-4 flex items-center justify-between min-h-[80px] transition-all duration-300 overflow-hidden">
                {{-- Logo: hidden via CSS when [data-flux-sidebar-collapsed-desktop] is set --}}
                <a data-sidebar-logo href="{{ route('dashboard') }}" class="flex items-center gap-3 min-w-0 group" wire:navigate title="Dashboard">
                   <x-app-logo />
                </a>

                {{-- Toggle: always visible --}}
                <flux:sidebar.collapse class="hidden lg:flex shrink-0 items-center justify-center rounded-lg hover:bg-white/5 text-slate-400 hover:text-white transition-colors cursor-pointer" />
            </flux:sidebar.header>

            <flux:sidebar.nav class="gap-1 px-2">
                <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                    Dashboard
                </flux:sidebar.item>
                <flux:sidebar.item icon="sparkles" :href="route(config('mascot.route_name', 'mascot.index'))" :current="request()->routeIs(config('mascot.route_name', 'mascot.index'))" wire:navigate>
                    {{ config('mascot.name', 'Orbita') }}
                </flux:sidebar.item>

                <flux:separator class="my-3 bg-white/10" />

                <div x-data="{ open: {{ request()->routeIs('transactions.*', 'bank-accounts.*', 'credit-cards.*', 'categories.*', 'budgets.*', 'savings-goals.*', 'recurring-transactions.*', 'subscriptions.*', 'billing.*', 'integrations.open-finance*') ? 'true' : 'false' }} }" class="space-y-1">
                    <button type="button" data-sidebar-group-toggle @click="open = ! open" class="flex w-full items-center justify-between rounded-xl px-3 py-2 text-[10px] font-black uppercase tracking-[0.2em] text-slate-500 transition hover:bg-white/5 hover:text-slate-300">
                        <span>Financas</span>
                        <span class="text-xs transition-transform" :class="open ? 'rotate-90' : ''">&rsaquo;</span>
                    </button>
                    <div x-show="open" data-sidebar-group-panel class="space-y-1" x-cloak>
                        <flux:sidebar.item icon="currency-dollar" :href="route('transactions.index')" :current="request()->routeIs('transactions.*')" wire:navigate>
                            Transacoes
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="building-library" :href="route('bank-accounts.index')" :current="request()->routeIs('bank-accounts.*')" wire:navigate>
                            Contas Bancarias
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="credit-card" :href="route('credit-cards.index')" :current="request()->routeIs('credit-cards.*')" wire:navigate>
                            Cartoes de Credito
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="tag" :href="route('categories.index')" :current="request()->routeIs('categories.*')" wire:navigate>
                            Categorias
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="chart-bar" :href="route('budgets.index')" :current="request()->routeIs('budgets.*')" wire:navigate>
                            Orcamentos
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="trophy" :href="route('savings-goals.index')" :current="request()->routeIs('savings-goals.*')" wire:navigate>
                            Metas
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="arrow-path" :href="route('recurring-transactions.index')" :current="request()->routeIs('recurring-transactions.*')" wire:navigate>
                            Recorrencias
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="calendar-days" :href="route('subscriptions.index')" :current="request()->routeIs('subscriptions.*')" wire:navigate>
                            Assinaturas
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="rocket-launch" :href="route('billing.plans')" :current="request()->routeIs('billing.*')" wire:navigate>
                            Planos
                        </flux:sidebar.item>
                        @if (config('openfinance.enabled', false))
                            <flux:sidebar.item icon="wifi" :href="route('integrations.open-finance')" :current="request()->routeIs('integrations.open-finance*')" wire:navigate>
                                Open Finance
                            </flux:sidebar.item>
                        @endif
                    </div>
                </div>

                <div x-data="{ open: {{ request()->routeIs('reminders.*', 'notes.*', 'drive.*') ? 'true' : 'false' }} }" class="space-y-1">
                    <button type="button" data-sidebar-group-toggle @click="open = ! open" class="flex w-full items-center justify-between rounded-xl px-3 py-2 text-[10px] font-black uppercase tracking-[0.2em] text-slate-500 transition hover:bg-white/5 hover:text-slate-300">
                        <span>Organizacao</span>
                        <span class="text-xs transition-transform" :class="open ? 'rotate-90' : ''">&rsaquo;</span>
                    </button>
                    <div x-show="open" data-sidebar-group-panel class="space-y-1" x-cloak>
                        <flux:sidebar.item icon="bell" :href="route('reminders.index')" :current="request()->routeIs('reminders.*')" wire:navigate>
                            Lembretes
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="document-duplicate" :href="route('notes.index')" :current="request()->routeIs('notes.*')" wire:navigate>
                            Notas
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="folder" :href="route('drive.index')" :current="request()->routeIs('drive.*')" wire:navigate>
                            Drive Inteligente
                        </flux:sidebar.item>
                    </div>
                </div>

                <div x-data="{ open: {{ request()->routeIs('reports.*', 'financial-projections.*') ? 'true' : 'false' }} }" class="space-y-1">
                    <button type="button" data-sidebar-group-toggle @click="open = ! open" class="flex w-full items-center justify-between rounded-xl px-3 py-2 text-[10px] font-black uppercase tracking-[0.2em] text-slate-500 transition hover:bg-white/5 hover:text-slate-300">
                        <span>Analise</span>
                        <span class="text-xs transition-transform" :class="open ? 'rotate-90' : ''">&rsaquo;</span>
                    </button>
                    <div x-show="open" data-sidebar-group-panel class="space-y-1" x-cloak>
                        <flux:sidebar.item icon="document-text" :href="route('reports.index')" :current="request()->routeIs('reports.*')" wire:navigate>
                            Relatorios
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="chart-bar" :href="route('financial-projections.index')" :current="request()->routeIs('financial-projections.*')" wire:navigate>
                            Projecoes
                        </flux:sidebar.item>
                    </div>
                </div>

                @if(auth()->user()?->isAdmin())
                    <div x-data="{ open: {{ request()->routeIs('assistant.observability', 'admin.whatsapp-broadcasts.*') ? 'true' : 'false' }} }" class="space-y-1">
                        <button type="button" data-sidebar-group-toggle @click="open = ! open" class="flex w-full items-center justify-between rounded-xl px-3 py-2 text-[10px] font-black uppercase tracking-[0.2em] text-emerald-300/80 transition hover:bg-emerald-400/10 hover:text-emerald-200">
                            <span>Admin</span>
                            <span class="text-xs transition-transform" :class="open ? 'rotate-90' : ''">&rsaquo;</span>
                        </button>
                        <div x-show="open" data-sidebar-group-panel class="space-y-1" x-cloak>
                            @can('viewAssistantObservability')
                                <flux:sidebar.item icon="command-line" :href="route('assistant.observability')" :current="request()->routeIs('assistant.observability')" wire:navigate>
                                    Observabilidade IA
                                </flux:sidebar.item>
                            @endcan
                            @can('manageWhatsAppBroadcasts')
                                <flux:sidebar.item icon="paper-airplane" :href="route('admin.whatsapp-broadcasts.index')" :current="request()->routeIs('admin.whatsapp-broadcasts.*')" wire:navigate>
                                    Disparos WhatsApp
                                </flux:sidebar.item>
                            @endcan
                        </div>
                    </div>
                @endif
            </flux:sidebar.nav>

            <flux:sidebar.spacer />

            <!-- Desktop User Menu -->
            <flux:dropdown class="hidden lg:block" position="top" align="start">
                <flux:sidebar.profile
                    :name="auth()->user()->name"
                    :initials="auth()->user()->initials()"
                />

                <flux:menu class="w-[220px]">
                    <div class="px-3 py-2 border-b border-white/5 mb-1">
                        <div class="flex items-center gap-2">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white text-xs font-bold">
                                {{ auth()->user()->initials() }}
                            </span>
                            <div class="grid flex-1 text-left text-sm leading-tight">
                                <span class="truncate font-semibold text-white">{{ auth()->user()->name }}</span>
                                <span class="truncate text-xs text-slate-400">{{ auth()->user()->email }}</span>
                            </div>
                        </div>
                    </div>

                    <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>Configuracoes</flux:menu.item>
                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full" data-test="logout-button">
                            {{ __('Sair') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <div class="px-3 py-2 border-b border-white/5 mb-1">
                        <div class="flex items-center gap-2">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white text-xs font-bold">
                                {{ auth()->user()->initials() }}
                            </span>
                            <div class="grid flex-1 text-left text-sm leading-tight">
                                <span class="truncate font-semibold text-white">{{ auth()->user()->name }}</span>
                                <span class="truncate text-xs text-slate-400">{{ auth()->user()->email }}</span>
                            </div>
                        </div>
                    </div>

                    <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>Configuracoes</flux:menu.item>
                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full" data-test="logout-button">
                            {{ __('Sair') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        <flux:main>
            <!-- Toast Notifications -->
            <div
                x-data="{
                    notifications: [],
                    addNotification(message, type = 'success') {
                        const id = Date.now();
                        this.notifications.push({ id, message, type });
                        setTimeout(() => this.removeNotification(id), 5000);
                    },
                    removeNotification(id) {
                        this.notifications = this.notifications.filter(n => n.id !== id);
                    }
                }"
                x-init="
                    Livewire.on('toast', (data) => {
                        addNotification(data.message, data.type || 'success');
                    });
                    @if(session('message'))
                        addNotification('{{ session('message') }}', 'success');
                    @endif
                "
                class="fixed top-4 right-4 z-50 space-y-2"
            >
                <template x-for="notification in notifications" :key="notification.id">
                    <div
                        x-show="true"
                        x-transition:enter="transition ease-out duration-300"
                        x-transition:enter-start="opacity-0 transform translate-x-full"
                        x-transition:enter-end="opacity-100 transform translate-x-0"
                        x-transition:leave="transition ease-in duration-200"
                        x-transition:leave-start="opacity-100"
                        x-transition:leave-end="opacity-0"
                        class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-700 rounded-lg shadow-lg p-4 min-w-[300px] flex items-center gap-3"
                        :class="{
                            'border-green-500 dark:border-green-600': notification.type === 'success',
                            'border-red-500 dark:border-red-600': notification.type === 'error',
                            'border-yellow-500 dark:border-yellow-600': notification.type === 'warning',
                            'border-blue-500 dark:border-blue-600': notification.type === 'info'
                        }"
                    >
                        <div
                            class="flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center"
                            :class="{
                                'bg-green-100 dark:bg-green-900': notification.type === 'success',
                                'bg-red-100 dark:bg-red-900': notification.type === 'error',
                                'bg-yellow-100 dark:bg-yellow-900': notification.type === 'warning',
                                'bg-blue-100 dark:bg-blue-900': notification.type === 'info'
                            }"
                        >
                            <span
                                class="text-sm"
                                :class="{
                                    'text-green-600 dark:text-green-400': notification.type === 'success',
                                    'text-red-600 dark:text-red-400': notification.type === 'error',
                                    'text-yellow-600 dark:text-yellow-400': notification.type === 'warning',
                                    'text-blue-600 dark:text-blue-400': notification.type === 'info'
                                }"
                            >
                                <template x-if="notification.type === 'success'">&#10003;</template>
                                <template x-if="notification.type === 'error'">&#10005;</template>
                                <template x-if="notification.type === 'warning'">&#9888;</template>
                                <template x-if="notification.type === 'info'">&#8505;</template>
                            </span>
                        </div>
                        <p class="flex-1 text-sm font-medium text-zinc-900 dark:text-zinc-100" x-text="notification.message"></p>
                        <button
                            @click="removeNotification(notification.id)"
                            class="flex-shrink-0 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200"
                        >
                            &times;
                        </button>
                    </div>
                </template>
            </div>

            {{ $slot }}
        </flux:main>
        @livewireScripts
        @fluxScripts
        @stack('scripts')
    </body>
</html>
