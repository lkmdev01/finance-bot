<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800 overflow-x-hidden">
        <flux:sidebar sticky collapsible class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <a href="{{ route('dashboard') }}" class="flex items-center space-x-2 rtl:space-x-reverse shrink-0 min-w-0 justify-center" wire:navigate>
                    <x-app-logo />
                </a>
            </flux:sidebar.header>
            
            <!-- Botão de collapse escondido para ser acionado pelo botão customizado -->
            <div class="hidden" data-flux-sidebar-collapse-trigger>
                <flux:sidebar.collapse />
            </div>

            <flux:sidebar.nav>
                <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                    {{ __('Dashboard') }}
                </flux:sidebar.item>
                <flux:sidebar.item icon="currency-dollar" :href="route('transactions.index')" :current="request()->routeIs('transactions.*')" wire:navigate>
                    Transações
                </flux:sidebar.item>
                <flux:sidebar.item icon="tag" :href="route('categories.index')" :current="request()->routeIs('categories.*')" wire:navigate>
                    Categorias
                </flux:sidebar.item>
                <flux:sidebar.item icon="chart-bar" :href="route('budgets.index')" :current="request()->routeIs('budgets.*')" wire:navigate>
                    Orçamentos
                </flux:sidebar.item>
                <flux:sidebar.item icon="document-text" :href="route('reports.index')" :current="request()->routeIs('reports.*')" wire:navigate>
                    Relatórios
                </flux:sidebar.item>
                <flux:sidebar.item icon="chart-bar" :href="route('financial-projections.index')" :current="request()->routeIs('financial-projections.*')" wire:navigate>
                    Projeções
                </flux:sidebar.item>
                <flux:sidebar.item icon="trophy" :href="route('savings-goals.index')" :current="request()->routeIs('savings-goals.*')" wire:navigate>
                    Metas de Economia
                </flux:sidebar.item>
                <flux:sidebar.item icon="arrow-path" :href="route('recurring-transactions.index')" :current="request()->routeIs('recurring-transactions.*')" wire:navigate>
                    Transações Recorrentes
                </flux:sidebar.item>
            </flux:sidebar.nav>

            <flux:sidebar.spacer />

            <!-- Desktop User Menu -->
            <flux:dropdown class="hidden lg:block" position="bottom" align="start">
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
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>{{ __('Settings') }}</flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full" data-test="logout-button">
                            {{ __('Log Out') }}
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
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>{{ __('Settings') }}</flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full" data-test="logout-button">
                            {{ __('Log Out') }}
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
                                <template x-if="notification.type === 'success'">✓</template>
                                <template x-if="notification.type === 'error'">✗</template>
                                <template x-if="notification.type === 'warning'">⚠</template>
                                <template x-if="notification.type === 'info'">ℹ</template>
                            </span>
                        </div>
                        <p class="flex-1 text-sm font-medium text-zinc-900 dark:text-zinc-100" x-text="notification.message"></p>
                        <button 
                            @click="removeNotification(notification.id)"
                            class="flex-shrink-0 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200"
                        >
                            ✕
                        </button>
                    </div>
                </template>
            </div>

            {{ $slot }}
        </flux:main>

        @fluxScripts
        
        <style>
            html, body {
                height: 100%;
                overflow-x: hidden !important;
            }
            
            [data-flux-sidebar] {
                position: fixed !important;
                top: 0;
                left: 0;
                height: 100vh !important;
                max-height: 100vh !important;
                overflow: hidden !important;
                max-width: 100%;
                z-index: 40;
                display: flex;
                flex-direction: column;
            }
            
            [data-flux-sidebar] > *:not([data-flux-sidebar-nav]):not(nav) {
                flex-shrink: 0;
            }
            
            [data-flux-sidebar] > nav,
            [data-flux-sidebar] [data-flux-sidebar-nav] {
                flex: 1 1 auto;
                overflow-y: auto;
                overflow-x: hidden;
                min-height: 0;
                -webkit-overflow-scrolling: touch;
            }
            
            [data-flux-sidebar-collapsed-desktop] {
                overflow-x: hidden !important;
                overflow-y: hidden !important;
                width: 4rem !important;
                max-width: 4rem !important;
            }
            
            [data-flux-sidebar-collapsed-desktop] > nav,
            [data-flux-sidebar-collapsed-desktop] [data-flux-sidebar-nav] {
                overflow-y: auto;
                overflow-x: hidden;
                padding: 0.5rem 0;
            }
            
            
            [data-flux-main] {
                margin-left: var(--flux-sidebar-width, 16rem);
                overflow-x: hidden;
                min-height: 100vh;
                transition: margin-left 0.3s ease;
            }
            
            [data-flux-sidebar-collapsed-desktop] ~ [data-flux-main] {
                margin-left: 4rem;
            }
            
            @media (max-width: 1024px) {
                [data-flux-sidebar] {
                    position: relative !important;
                    height: auto !important;
                }
                
                [data-flux-main] {
                    margin-left: 0 !important;
                }
            }
            
            [data-flux-sidebar-header] {
                display: flex;
                gap: 0.5rem;
                min-width: 0;
                width: 100%;
                flex-shrink: 0;
            }
            
            [data-flux-sidebar] > nav,
            [data-flux-sidebar] > [data-flux-sidebar-nav] {
                flex: 0 1 auto;
                overflow-y: auto;
                overflow-x: hidden;
            }
            
            [data-flux-sidebar-collapsed-desktop] [data-flux-sidebar-header] a {
                display: flex;
                justify-content: center;
                width: 100%;
            }
            
            [data-flux-sidebar-collapsed-desktop] [data-flux-sidebar-header] {
                justify-content: center;
                padding: 0.5rem;
            }
            
            [data-flux-sidebar-collapsed-desktop] [data-flux-sidebar-header] .ms-1 {
                display: none;
            }
            
            [data-flux-sidebar-collapsed-desktop] [data-flux-sidebar-header] a > div:last-child {
                display: none;
            }
        </style>
        
        <script>
            (function() {
                localStorage.setItem('flux-sidebar-collapsed-desktop', 'true');
                
                function collapseSidebar() {
                    const sidebar = document.querySelector('[data-flux-sidebar]');
                    if (sidebar) {
                        sidebar.setAttribute('data-flux-sidebar-collapsed-desktop', '');
                        return true;
                    }
                    return false;
                }
                
                if (!collapseSidebar()) {
                    document.addEventListener('DOMContentLoaded', collapseSidebar);
                    window.addEventListener('load', collapseSidebar);
                    
                    const observer = new MutationObserver(function() {
                        if (collapseSidebar()) {
                            observer.disconnect();
                        }
                    });
                    
                    observer.observe(document.body, { childList: true, subtree: true });
                }
                
                // Listener para eventos de toggle do sidebar
                document.addEventListener('flux-sidebar:toggle', function(event) {
                    const sidebar = document.querySelector('[data-flux-sidebar]');
                    if (sidebar) {
                        const isCollapsed = sidebar.hasAttribute('data-flux-sidebar-collapsed-desktop');
                        if (event.detail && event.detail.collapsed !== undefined) {
                            if (event.detail.collapsed && !isCollapsed) {
                                sidebar.setAttribute('data-flux-sidebar-collapsed-desktop', '');
                                localStorage.setItem('flux-sidebar-collapsed-desktop', 'true');
                            } else if (!event.detail.collapsed && isCollapsed) {
                                sidebar.removeAttribute('data-flux-sidebar-collapsed-desktop');
                                localStorage.removeItem('flux-sidebar-collapsed-desktop');
                            }
                        }
                    }
                });
            })();
        </script>
    </body>
</html>
