<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <style>
        .blur-gradient {
            background: radial-gradient(circle at 50% 50%, rgba(99, 102, 241, 0.15) 0%, rgba(7, 11, 20, 0) 70%);
        }
        .glass {
            background: rgba(30, 41, 59, 0.5);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
    </style>
    <body class="min-h-screen bg-space-950 text-slate-100 antialiased relative overflow-hidden">
        {{-- Background Effects --}}
        <div class="absolute inset-0 z-0 pointer-events-none">
            <div class="absolute top-[-10%] left-[-10%] w-[100%] h-[100%] blur-gradient opacity-60"></div>
            <div class="absolute bottom-[-10%] right-[-10%] w-[100%] h-[100%] blur-gradient opacity-40"></div>
        </div>

        <div class="relative z-10 flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div class="flex w-full max-w-sm flex-col gap-6">
                <a href="{{ route('home') }}" class="flex flex-col items-center gap-3 group" wire:navigate>
                    <div class="w-12 h-12 bg-primary rounded-xl flex items-center justify-center shadow-[0_0_20px_rgba(99,102,241,0.5)] transition-transform group-hover:scale-110">
                        <span class="text-2xl">🚀</span>
                    </div>
                    <span class="text-2xl font-bold tracking-tight text-white">InovaFinance</span>
                </a>
                <div class="flex flex-col gap-6">
                    {{ $slot }}
                </div>
            </div>
        </div>
        @fluxScripts
    </body>
</html>
