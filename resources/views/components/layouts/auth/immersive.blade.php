<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
        <style>
            html,
            body {
                margin: 0 !important;
                padding: 0 !important;
                width: 100%;
                height: 100dvh;
                overflow: hidden;
            }
        </style>
    </head>
    <body class="h-dvh overflow-hidden bg-[#05060a] text-slate-100 antialiased">
        <div class="pointer-events-none fixed inset-0 overflow-hidden">
            <div class="absolute inset-y-0 left-[-18%] w-[48vw] bg-[radial-gradient(circle_at_center,_rgba(140,69,255,0.16),_transparent_68%)]"></div>
            <div class="absolute inset-y-0 right-[-12%] w-[38vw] bg-[radial-gradient(circle_at_center,_rgba(236,72,153,0.11),_transparent_70%)]"></div>
            <div class="absolute inset-0 bg-[linear-gradient(180deg,rgba(255,255,255,0.02),transparent_34%)]"></div>
        </div>

        <div class="fixed inset-0 grid lg:grid-cols-[minmax(360px,0.88fr)_minmax(620px,1.12fr)] xl:grid-cols-[minmax(380px,0.84fr)_minmax(700px,1.16fr)]">
            <aside class="hidden h-dvh overflow-hidden border-r border-white/6 px-8 py-8 lg:flex lg:flex-col xl:px-10">
                <div class="flex h-full flex-col justify-center">
                <div class="max-w-[28rem] space-y-10">
                    <a href="{{ route('home') }}" class="inline-flex items-center gap-3" wire:navigate>
                        <img src="{{ asset('logo.png') }}" alt="InovaFinance" class="h-11 w-11 rounded-2xl object-contain" />
                        <span class="text-3xl font-light tracking-tight text-white">InovaFinance</span>
                    </a>

                    <div class="space-y-5">
                        <div class="inline-flex rounded-full border border-fuchsia-400/25 bg-fuchsia-500/10 px-4 py-2 text-[11px] font-semibold uppercase tracking-[0.24em] text-fuchsia-200">
                            Organização financeira no WhatsApp
                        </div>

                        <div class="space-y-5">
                            <h1 class="max-w-md text-[2.7rem] font-black leading-[1.02] tracking-tight text-white xl:text-[3.1rem]">
                                Comece sua jornada de forma guiada
                            </h1>

                            <p class="max-w-md text-base leading-7 text-slate-300">
                                Crie sua conta, valide seu número e deixe o robô pronto para reconhecer suas mensagens desde o primeiro acesso.
                            </p>
                        </div>
                    </div>

                    <div
                        class="relative h-[190px] overflow-hidden rounded-[28px] border border-white/12 bg-white/[0.04] p-6 shadow-[0_20px_80px_rgba(0,0,0,0.28)] backdrop-blur-xl"
                        data-auth-rotating-cards
                    >
                        <div class="pointer-events-none absolute inset-0 bg-[linear-gradient(135deg,rgba(255,255,255,0.08),rgba(255,255,255,0.01)_42%,rgba(168,85,247,0.10))]"></div>
                        <div class="pointer-events-none absolute inset-x-6 top-0 h-px bg-gradient-to-r from-transparent via-white/25 to-transparent"></div>

                        <div
                            class="absolute inset-0 flex items-end p-6 transition-all duration-500"
                            data-auth-card
                            data-active="true"
                        >
                            <div class="space-y-4">
                                <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-fuchsia-500/18 text-lg text-fuchsia-200 ring-1 ring-white/10">
                                    💬
                                </div>
                                <div class="space-y-2">
                                    <p class="text-lg font-bold text-white">Registro pelo WhatsApp</p>
                                    <p class="max-w-sm text-sm leading-6 text-slate-300">
                                        Envie mensagens como “gastei 32 no Uber” ou “recebi 500” e o sistema organiza tudo para você.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div
                            class="absolute inset-0 flex items-end p-6 opacity-0 transition-all duration-500"
                            data-auth-card
                        >
                            <div class="space-y-4">
                                <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-violet-500/18 text-lg text-violet-200 ring-1 ring-white/10">
                                    ✨
                                </div>
                                <div class="space-y-2">
                                    <p class="text-lg font-bold text-white">Teste Pro grátis</p>
                                    <p class="max-w-sm text-sm leading-6 text-slate-300">
                                        Relatórios, projeções e Orbita liberados desde o primeiro acesso para você sentir valor rápido.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div
                            class="absolute inset-0 flex items-end p-6 opacity-0 transition-all duration-500"
                            data-auth-card
                        >
                            <div class="space-y-4">
                                <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-emerald-500/18 text-lg text-emerald-200 ring-1 ring-white/10">
                                    ✅
                                </div>
                                <div class="space-y-2">
                                    <p class="text-lg font-bold text-white">Ativação imediata</p>
                                    <p class="max-w-sm text-sm leading-6 text-slate-300">
                                        O cadastro já termina com o WhatsApp conectado, sem depender de tutorial no dashboard.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="absolute inset-x-6 bottom-5 flex gap-2">
                            <span class="h-1.5 w-8 rounded-full bg-white" data-auth-card-dot data-active="true"></span>
                            <span class="h-1.5 w-5 rounded-full bg-white/20" data-auth-card-dot></span>
                            <span class="h-1.5 w-5 rounded-full bg-white/20" data-auth-card-dot></span>
                        </div>
                    </div>
                </div>
                </div>
            </aside>

            <main class="flex h-dvh items-stretch justify-stretch overflow-hidden">
                <div class="flex h-dvh w-full flex-col overflow-y-auto border-l border-white/8 bg-[linear-gradient(180deg,rgba(29,24,39,0.96),rgba(18,16,24,0.98))] shadow-[0_28px_100px_rgba(0,0,0,0.42)]">
                    <div class="block border-b border-white/6 px-5 py-4 lg:hidden">
                        <a href="{{ route('home') }}" class="inline-flex items-center gap-3" wire:navigate>
                            <img src="{{ asset('logo.png') }}" alt="InovaFinance" class="h-10 w-10 rounded-2xl object-contain" />
                            <div>
                                <p class="text-lg font-bold text-white">InovaFinance</p>
                                <p class="text-sm text-slate-400">Gestão financeira no WhatsApp</p>
                            </div>
                        </a>
                    </div>

                    <div class="flex-1 px-5 py-6 md:px-7 md:py-7 lg:px-14 lg:py-14 xl:px-16">
                        {{ $slot }}
                    </div>
                </div>
            </main>
        </div>

        @fluxScripts
        <script>
            (() => {
                const wrapper = document.querySelector('[data-auth-rotating-cards]');
                if (!wrapper) return;

                const cards = Array.from(wrapper.querySelectorAll('[data-auth-card]'));
                const dots = Array.from(wrapper.querySelectorAll('[data-auth-card-dot]'));

                if (cards.length <= 1) return;

                let index = 0;

                const render = () => {
                    cards.forEach((card, cardIndex) => {
                        const active = cardIndex === index;
                        card.classList.toggle('opacity-0', !active);
                        card.classList.toggle('translate-y-2', !active);
                        card.classList.toggle('pointer-events-none', !active);
                        card.dataset.active = active ? 'true' : 'false';
                    });

                    dots.forEach((dot, dotIndex) => {
                        const active = dotIndex === index;
                        dot.classList.toggle('w-8', active);
                        dot.classList.toggle('w-5', !active);
                        dot.classList.toggle('bg-white', active);
                        dot.classList.toggle('bg-white/20', !active);
                        dot.dataset.active = active ? 'true' : 'false';
                    });
                };

                render();

                window.setInterval(() => {
                    index = (index + 1) % cards.length;
                    render();
                }, 3200);
            })();
        </script>
    </body>
</html>
