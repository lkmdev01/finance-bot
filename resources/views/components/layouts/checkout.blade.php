<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-space-950 text-slate-100 antialiased overflow-x-clip pt-0">
        {{-- Background Effects (same vibe as the app shell, but no sidebar). --}}
        <div class="fixed inset-0 z-0 pointer-events-none">
            <div class="absolute top-[-10%] left-[-10%] w-[100%] h-[100%] blur-gradient opacity-40"></div>
            <div class="absolute bottom-[-10%] right-[-10%] w-[100%] h-[100%] blur-gradient opacity-30"></div>
        </div>

        <header class="relative z-10 mx-auto max-w-6xl px-4 pt-8">
            <div class="flex items-center justify-between gap-4 rounded-2xl border border-white/10 bg-black/35 px-4 py-3 backdrop-blur-xl">
                <a href="{{ $backHref ?? route('dashboard') }}" class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm font-semibold text-white transition hover:bg-white/10">
                    <span class="text-slate-200">Voltar</span>
                </a>

                <a href="{{ route('dashboard') }}" class="flex items-center gap-3 min-w-0" title="Dashboard">
                    <x-app-logo />
                    <span class="hidden sm:block text-sm font-semibold text-slate-200">{{ config('app.name', 'InovaFinance') }}</span>
                </a>

                <div class="text-right">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-400">Checkout</p>
                    <p class="text-sm font-semibold text-white">{{ $title ?? 'Pagamento' }}</p>
                </div>
            </div>
        </header>

        <main class="relative z-10 mx-auto max-w-6xl px-4 py-10">
            {{ $slot }}
        </main>

        @fluxScripts
    </body>
</html>

