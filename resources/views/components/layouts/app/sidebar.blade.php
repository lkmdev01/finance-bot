<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    <style>
            .blur-gradient {
                background: radial-gradient(circle at 50% 50%, rgba(99, 102, 241, 0.12) 0%, rgba(7, 11, 20, 0) 70%);
            }
            /* Fix Dropdown Menus visuals */
            [data-flux-menu], [data-flux-popover] {
                background-color: #1e293b !important;
                border: 1px solid rgba(99, 102, 241, 0.3) !important;
                box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.5) !important;
                backdrop-filter: blur(8px);
                z-index: 9999 !important;
            }
            [data-flux-menu-item]:hover {
                background-color: rgba(99, 102, 241, 0.1) !important;
            }
            
            /* Melhoria do Sidebar Fixo para evitar pulos */
            [data-flux-sidebar] {
                position: fixed !important;
                top: 0;
                left: 0;
                bottom: 0;
                height: 100vh !important;
                transition: width 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            }

            /* CompensaÃ§Ã£o para o conteÃºdo principal */
            [data-flux-main] {
                margin-left: 16rem;
                transition: margin-left 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            }

            /* Quando colapsado (Atributo oficial do Flux) */
            [data-flux-sidebar][data-flux-sidebar-collapsed-desktop] {
                width: 4rem !important;
            }

            [data-flux-sidebar][data-flux-sidebar-collapsed-desktop] + [data-flux-main],
            [data-flux-sidebar][data-flux-sidebar-collapsed-desktop] ~ [data-flux-main] {
                margin-left: 4rem !important;
            }

            /* Ocultar texto InovaFinance no modo colapsado */
            [data-flux-sidebar][data-flux-sidebar-collapsed-desktop] [data-flux-sidebar-header] a > div:last-child {
                display: none !important;
            }

            [data-flux-sidebar][data-flux-sidebar-collapsed-desktop] [data-flux-sidebar-header] {
                flex-direction: column;
                gap: 1rem;
                justify-content: center;
                padding: 1.5rem 0;
            }

            /* Posicionamento do Toggle no modo colapsado */
            [data-flux-sidebar][data-flux-sidebar-collapsed-desktop] [data-flux-sidebar-header] [data-flux-sidebar-toggle] {
                position: static !important;
                inset: auto !important;
                align-self: center;
                margin-top: 0.5rem;
            }

            @media (max-width: 1024px) {
                [data-flux-sidebar] {
                    position: relative !important;
                    height: auto !important;
                    width: 100% !important;
                }
                [data-flux-main] {
                    margin-left: 0 !important;
                }
            }

            /* Troca de Ã­cones do Toggle */
            [data-flux-sidebar-collapsed-desktop] .toggle-collapse {
                display: none !important;
            }
            [data-flux-sidebar-collapsed-desktop] .toggle-expand {
                display: block !important;
            }
        </style>
    </head>
    <body class="min-h-screen bg-space-950 text-slate-100 antialiased overflow-x-hidden pt-0">
        {{-- Background Effects --}}
        <div class="fixed inset-0 z-0 pointer-events-none">
            <div class="absolute top-[-10%] left-[-10%] w-[100%] h-[100%] blur-gradient opacity-40"></div>
            <div class="absolute bottom-[-10%] right-[-10%] w-[100%] h-[100%] blur-gradient opacity-30"></div>
        </div>

        <flux:sidebar collapsible class="z-50 border-e border-white/5 bg-black/40 backdrop-blur-xl">
            <flux:sidebar.header class="relative py-6 border-b border-white/5 mb-4 px-4 flex items-center justify-between min-h-[80px] transition-all duration-300">
                <a href="{{ route('dashboard') }}" class="flex items-center gap-3 shrink-0 min-w-0 group" wire:navigate title="Dashboard">
                   <x-app-logo />
                </a>
                
                <flux:sidebar.collapse class="hidden lg:flex items-center justify-center rounded-lg hover:bg-white/5 text-slate-400 hover:text-white transition-colors cursor-pointer group" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                    {{ __('Dashboard') }}
                </flux:sidebar.item>
                <flux:sidebar.item icon="currency-dollar" :href="route('transactions.index')" :current="request()->routeIs('transactions.*')" wire:navigate>
                    TransaÃ§Ãµes
                </flux:sidebar.item>
                <flux:sidebar.item icon="tag" :href="route('categories.index')" :current="request()->routeIs('categories.*')" wire:navigate>
                    Categorias
                </flux:sidebar.item>
                <flux:sidebar.item icon="chart-bar" :href="route('budgets.index')" :current="request()->routeIs('budgets.*')" wire:navigate>
                    OrÃ§amentos
                </flux:sidebar.item>
                <flux:sidebar.item icon="document-text" :href="route('reports.index')" :current="request()->routeIs('reports.*')" wire:navigate>
                    RelatÃ³rios
                </flux:sidebar.item>
                <flux:sidebar.item icon="chart-bar" :href="route('financial-projections.index')" :current="request()->routeIs('financial-projections.*')" wire:navigate>
                    ProjeÃ§Ãµes
                </flux:sidebar.item>
                <flux:sidebar.item icon="trophy" :href="route('savings-goals.index')" :current="request()->routeIs('savings-goals.*')" wire:navigate>
                    Metas de Economia
                </flux:sidebar.item>
                <flux:sidebar.item icon="building-library" :href="route('bank-accounts.index')" :current="request()->routeIs('bank-accounts.*')" wire:navigate>
                    Contas Bancarias
                </flux:sidebar.item>
                <flux:sidebar.item icon="credit-card" :href="route('credit-cards.index')" :current="request()->routeIs('credit-cards.*')" wire:navigate>
                    Cartoes de Credito
                </flux:sidebar.item>
                <flux:sidebar.item icon="arrow-path" :href="route('recurring-transactions.index')" :current="request()->routeIs('recurring-transactions.*')" wire:navigate>
                    TransaÃ§Ãµes Recorrentes
                </flux:sidebar.item>
                <flux:sidebar.item icon="calendar-days" :href="route('subscriptions.index')" :current="request()->routeIs('subscriptions.*')" wire:navigate>
                    Assinaturas
                </flux:sidebar.item>
            </flux:sidebar.nav>

            <flux:sidebar.spacer />

            <!-- Desktop User Menu -->
            <flux:dropdown class="hidden lg:block" position="top" align="start">
                <flux:sidebar.profile
                    :name="auth()->user()->name"
                    :initials="auth()->user()->initials()"
                />

                <flux:menu class="w-[220px]">
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <span class="relative flex h-8 w-8 shrink-0 overflow-hidden rounded-lg">
                                    <span
                                        class="flex h-full w-full items-center justify-center rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white"
                                    >
                                        {{ auth()->user()->initials() }}
                                    </span>
                                </span>

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <span class="truncate font-semibold">{{ auth()->user()->name }}</span>
                                    <span class="truncate text-xs">{{ auth()->user()->email }}</span>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>{{ __('ConfiguraÃ§Ãµes') }}</flux:menu.item>
                    </flux:menu.radio.group>

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
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <span class="relative flex h-8 w-8 shrink-0 overflow-hidden rounded-lg">
                                    <span
                                        class="flex h-full w-full items-center justify-center rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white"
                                    >
                                        {{ auth()->user()->initials() }}
                                    </span>
                                </span>

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <span class="truncate font-semibold">{{ auth()->user()->name }}</span>
                                    <span class="truncate text-xs">{{ auth()->user()->email }}</span>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>{{ __('ConfiguraÃ§Ãµes') }}</flux:menu.item>
                    </flux:menu.radio.group>

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
                                <template x-if="notification.type === 'success'">âœ“</template>
                                <template x-if="notification.type === 'error'">âœ—</template>
                                <template x-if="notification.type === 'warning'">âš </template>
                                <template x-if="notification.type === 'info'">â„¹</template>
                            </span>
                        </div>
                        <p class="flex-1 text-sm font-medium text-zinc-900 dark:text-zinc-100" x-text="notification.message"></p>
                        <button 
                            @click="removeNotification(notification.id)"
                            class="flex-shrink-0 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200"
                        >
                            âœ•
                        </button>
                    </div>
                </template>
            </div>

            {{ $slot }}
        </flux:main>

        @fluxScripts
    </body>
</html>

