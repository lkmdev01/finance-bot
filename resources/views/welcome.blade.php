<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        @php
            $seoTitle = 'InovaFinance | Controle financeiro pelo WhatsApp';
            $seoDescription = 'Organize gastos, receitas, metas, lembretes, notas e arquivos do Drive conversando com o InovaFinance no WhatsApp.';
            $seoImage = asset('social-card.png');
            $seoUrl = route('home');
            $seoKeywords = 'controle financeiro, finanças pessoais, WhatsApp financeiro, organização financeira, gastos, receitas, metas, orçamento';
            $faviconVersion = file_exists(public_path('favicon.ico')) ? filemtime(public_path('favicon.ico')) : time();
            $structuredData = [
                [
                    '@context' => 'https://schema.org',
                    '@type' => 'Organization',
                    'name' => 'InovaFinance',
                    'url' => $seoUrl,
                    'logo' => asset('logo.png'),
                ],
                [
                    '@context' => 'https://schema.org',
                    '@type' => 'WebSite',
                    'name' => 'InovaFinance',
                    'url' => $seoUrl,
                    'description' => $seoDescription,
                    'inLanguage' => 'pt-BR',
                ],
                [
                    '@context' => 'https://schema.org',
                    '@type' => 'SoftwareApplication',
                    'name' => 'InovaFinance',
                    'applicationCategory' => 'FinanceApplication',
                    'operatingSystem' => 'Web',
                    'description' => $seoDescription,
                    'url' => $seoUrl,
                    'offers' => [
                        '@type' => 'Offer',
                        'price' => '19.97',
                        'priceCurrency' => 'BRL',
                    ],
                ],
            ];

            $trialCtaUrl = Route::has('register') ? route('register') : (Route::has('login') ? route('login') : '#');
            $paidCtaUrl = Route::has('billing.plans') ? route('billing.plans') : $trialCtaUrl;
            $loginUrl = Route::has('login') ? route('login') : '#';
            $dashboardUrl = Route::has('dashboard') ? route('dashboard') : '#';
            $supportEmail = (string) (config('mail.from.address') ?: 'suporte@inovaforce.com.br');
            $tutorialContactNumber = config('whatsapp.tutorial.contact_number');
            $tutorialContactDigits = preg_replace('/\D+/', '', (string) $tutorialContactNumber);
            $supportWhatsappUrl = $tutorialContactDigits
                ? 'https://wa.me/'.$tutorialContactDigits.'?text='.urlencode('Oi! Quero conhecer o InovaFinance.')
                : null;
        @endphp

        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $seoTitle }}</title>
        <meta name="description" content="{{ $seoDescription }}">
        <meta name="keywords" content="{{ $seoKeywords }}">
        <meta name="robots" content="index, follow">
        <meta name="googlebot" content="index, follow">
        <meta name="theme-color" content="#07110b">
        <link rel="canonical" href="{{ $seoUrl }}">

        <meta property="og:type" content="website">
        <meta property="og:site_name" content="InovaFinance">
        <meta property="og:locale" content="pt_BR">
        <meta property="og:title" content="{{ $seoTitle }}">
        <meta property="og:description" content="{{ $seoDescription }}">
        <meta property="og:image" content="{{ $seoImage }}">
        <meta property="og:image:alt" content="{{ $seoTitle }}">
        <meta property="og:url" content="{{ $seoUrl }}">

        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="{{ $seoTitle }}">
        <meta name="twitter:description" content="{{ $seoDescription }}">
        <meta name="twitter:image" content="{{ $seoImage }}">
        <meta name="twitter:image:alt" content="{{ $seoTitle }}">

        <link rel="icon" href="/favicon.ico?v={{ $faviconVersion }}" sizes="any">
        <link rel="shortcut icon" href="/favicon.ico?v={{ $faviconVersion }}">
        <link rel="icon" href="/favicon-512.png?v={{ $faviconVersion }}" type="image/png">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png?v={{ $faviconVersion }}">

        @foreach($structuredData as $schema)
            <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}</script>
        @endforeach

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,650;9..144,800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

        <script src="https://cdn.tailwindcss.com"></script>
        <script>
            tailwind.config = {
                theme: {
                    extend: {
                        fontFamily: {
                            display: ['Fraunces', 'serif'],
                            sans: ['Plus Jakarta Sans', 'sans-serif'],
                        },
                    },
                },
            };
        </script>

        <style>
            :root {
                --ink: #07110b;
                --paper: #f3eddc;
                --paper-2: #fff8e8;
                --line: rgba(243, 237, 220, .16);
                --green: #19d66b;
                --green-deep: #086c3b;
                --lime: #d4ff68;
                --gold: #f4c856;
            }

            * {
                box-sizing: border-box;
            }

            html,
            body {
                min-height: 100%;
                max-width: 100%;
                overflow-x: hidden;
                background: var(--ink);
                color: var(--paper-2);
                font-family: 'Plus Jakarta Sans', sans-serif;
            }

            body {
                overflow-x: hidden;
                position: relative;
            }

            @supports (overflow: clip) {
                html,
                body {
                    overflow-x: clip;
                }
            }

            body::before {
                content: '';
                position: fixed;
                inset: 0;
                z-index: -3;
                pointer-events: none;
                background:
                    radial-gradient(circle at 15% 10%, rgba(25, 214, 107, .25), transparent 28rem),
                    radial-gradient(circle at 86% 15%, rgba(244, 200, 86, .16), transparent 25rem),
                    radial-gradient(circle at 50% 100%, rgba(126, 231, 207, .12), transparent 35rem),
                    linear-gradient(145deg, #061009 0%, #0a1610 42%, #05090d 100%);
            }

            body::after {
                content: '';
                position: fixed;
                inset: 0;
                z-index: -2;
                pointer-events: none;
                opacity: .14;
                background-image:
                    linear-gradient(rgba(255, 255, 255, .08) 1px, transparent 1px),
                    linear-gradient(90deg, rgba(255, 255, 255, .08) 1px, transparent 1px);
                background-size: 44px 44px;
                mask-image: linear-gradient(to bottom, black, transparent 85%);
            }

            .noise {
                position: fixed;
                inset: 0;
                z-index: -1;
                pointer-events: none;
                opacity: .17;
                background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='140' height='140' viewBox='0 0 140 140'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.8' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='140' height='140' filter='url(%23n)' opacity='.55'/%3E%3C/svg%3E");
            }

            .display {
                font-family: 'Fraunces', serif;
                letter-spacing: -.055em;
            }

            .scroll-enter {
                opacity: 0;
                translate: 0 24px;
                transition: opacity .65s ease, translate .65s cubic-bezier(.2, .8, .2, 1);
                transition-delay: var(--enter-delay, 0ms);
            }

            .scroll-enter.is-visible,
            .scroll-enter:focus-within {
                opacity: 1;
                translate: 0 0;
            }

            a:focus-visible,
            summary:focus-visible {
                outline: 3px solid #19d66b;
                outline-offset: 5px;
            }

            @media (prefers-reduced-motion: reduce) {
                html { scroll-behavior: auto !important; }
                .scroll-enter,
                .reveal-lift,
                .motion-money,
                .motion-chat,
                .motion-piggy,
                .motion-panel {
                    opacity: 1 !important;
                    translate: none !important;
                    animation: none !important;
                    transition: none !important;
                }
                .ticker-track { animation: none; }
            }

            .paper-card {
                background: linear-gradient(145deg, rgba(255, 248, 232, .98), rgba(233, 224, 200, .93));
                color: #132015;
                box-shadow: 0 34px 100px rgba(0, 0, 0, .38);
            }

            .paper-section {
                position: relative;
                isolation: isolate;
                overflow: hidden;
                background:
                    radial-gradient(circle at 8% 15%, rgba(25, 214, 107, .12), transparent 24rem),
                    radial-gradient(circle at 92% 82%, rgba(244, 200, 86, .16), transparent 26rem),
                    linear-gradient(145deg, #fff8e8 0%, #eee5cf 100%);
                color: #073426;
            }

            .paper-section::before {
                content: '';
                position: absolute;
                inset: 0;
                z-index: -1;
                pointer-events: none;
                opacity: .35;
                background-image: radial-gradient(circle, rgba(8, 108, 59, .22) 1px, transparent 1px);
                background-size: 22px 22px;
                mask-image: linear-gradient(105deg, black, transparent 45%, black);
            }

            .paper-feature {
                background: rgba(255, 252, 242, .8);
                border: 1px solid rgba(7, 52, 38, .13);
                box-shadow: 0 18px 55px rgba(37, 47, 34, .09);
                backdrop-filter: blur(10px);
            }

            .paper-feature:nth-child(3n + 2) {
                background: rgba(214, 232, 203, .58);
            }

            .dark-card {
                background: linear-gradient(150deg, rgba(15, 33, 23, .84), rgba(4, 12, 10, .9));
                border: 1px solid var(--line);
                box-shadow: 0 24px 80px rgba(0, 0, 0, .34);
                backdrop-filter: blur(18px);
            }

            .cutout {
                filter: drop-shadow(0 34px 35px rgba(0, 0, 0, .42));
            }

            .dollar-rain {
                background-image: radial-gradient(circle, rgba(25, 214, 107, .24) 1.5px, transparent 1.5px);
                background-size: 18px 18px;
            }

            .ticker-track {
                display: flex;
                width: max-content;
                animation: ticker 18s linear infinite;
                will-change: transform;
            }

            .ticker-group {
                display: flex;
                flex-shrink: 0;
                align-items: center;
                gap: .5rem;
                padding-right: .5rem;
            }

            @media (max-width: 767px) {
                body::after,
                .noise {
                    display: none;
                }
            }

            @media (max-width: 359px) {
                .mobile-hide-brand {
                    display: none;
                }
            }

            .reveal-lift {
                opacity: 0;
                animation: revealLift .8s cubic-bezier(.2, .8, .2, 1) forwards;
            }

            .delay-1 { animation-delay: .08s; }
            .delay-2 { animation-delay: .18s; }
            .delay-3 { animation-delay: .3s; }
            .delay-4 { animation-delay: .42s; }

            .motion-money {
                opacity: 0;
                animation:
                    moneyEnter .75s cubic-bezier(.2, .8, .2, 1) .22s forwards,
                    moneyBreathe 7s ease-in-out 1.1s infinite;
            }

            .motion-chat {
                opacity: 0;
                animation:
                    chatEnter .8s cubic-bezier(.2, .8, .2, 1) .36s forwards,
                    chatBreathe 6.5s ease-in-out 1.25s infinite;
            }

            .motion-piggy {
                opacity: 0;
                animation:
                    piggyEnter .7s cubic-bezier(.2, .8, .2, 1) .62s forwards,
                    piggyBreathe 5.8s ease-in-out 1.4s infinite;
            }

            .motion-panel {
                opacity: 0;
                animation:
                    panelEnter .9s cubic-bezier(.2, .8, .2, 1) .52s forwards,
                    panelBreathe 7.4s ease-in-out 1.55s infinite;
            }

            @keyframes revealLift {
                from {
                    opacity: 0;
                    transform: translate3d(0, 18px, 0);
                }
                to {
                    opacity: 1;
                    transform: translate3d(0, 0, 0);
                }
            }

            @keyframes moneyEnter {
                from {
                    opacity: 0;
                    transform: translate3d(22px, 26px, 0) rotate(10deg) scale(.92);
                }
                to {
                    opacity: 1;
                    transform: translate3d(0, 0, 0) rotate(6deg) scale(1);
                }
            }

            @keyframes moneyBreathe {
                0%, 100% {
                    opacity: .9;
                    transform: translate3d(0, 0, 0) rotate(6deg) scale(.98);
                }
                50% {
                    opacity: 1;
                    transform: translate3d(-8px, -10px, 0) rotate(4deg) scale(1);
                }
            }

            @keyframes chatEnter {
                from {
                    opacity: 0;
                    transform: translate3d(-26px, 20px, 0) scale(.94);
                }
                to {
                    opacity: 1;
                    transform: translate3d(0, 0, 0) scale(1);
                }
            }

            @keyframes chatBreathe {
                0%, 100% {
                    opacity: .92;
                    transform: translate3d(0, 0, 0) scale(.99);
                }
                50% {
                    opacity: 1;
                    transform: translate3d(0, -8px, 0) scale(1);
                }
            }

            @keyframes piggyEnter {
                from {
                    opacity: 0;
                    transform: translate3d(18px, 18px, 0) rotate(8deg) scale(.86);
                }
                to {
                    opacity: 1;
                    transform: translate3d(0, 0, 0) rotate(3deg) scale(1);
                }
            }

            @keyframes piggyBreathe {
                0%, 100% {
                    opacity: .82;
                    transform: translate3d(0, 0, 0) rotate(3deg) scale(.96);
                }
                50% {
                    opacity: 1;
                    transform: translate3d(8px, -7px, 0) rotate(0deg) scale(1);
                }
            }

            @keyframes panelEnter {
                from {
                    opacity: 0;
                    transform: translate3d(10px, 34px, 0) rotate(-6deg) scale(.94);
                }
                to {
                    opacity: 1;
                    transform: translate3d(0, 0, 0) rotate(-2deg) scale(1);
                }
            }

            @keyframes panelBreathe {
                0%, 100% {
                    opacity: .94;
                    transform: translate3d(0, 0, 0) rotate(-2deg) scale(.985);
                }
                50% {
                    opacity: 1;
                    transform: translate3d(-7px, -9px, 0) rotate(-1deg) scale(1);
                }
            }

            @keyframes ticker {
                0% { transform: translate3d(0, 0, 0); }
                100% { transform: translate3d(-50%, 0, 0); }
            }
        </style>
    </head>

    <body class="antialiased">
        <div class="noise" aria-hidden="true"></div>

        <header class="relative z-20 mx-auto flex max-w-7xl items-center justify-between gap-3 px-4 py-5 sm:gap-4 sm:px-8 lg:px-10">
            <a href="{{ route('home') }}" class="group flex min-w-0 items-center gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl border border-emerald-300/25 bg-emerald-400/10 shadow-[0_0_32px_rgba(25,214,107,.28)] sm:h-11 sm:w-11">
                    <img src="{{ asset('logo.png') }}" alt="InovaFinance" class="h-6 w-6 object-contain sm:h-7 sm:w-7">
                </span>
                <span class="mobile-hide-brand truncate text-lg font-extrabold tracking-tight text-white sm:inline">InovaFinance</span>
            </a>

            <nav class="hidden items-center gap-8 text-sm font-semibold text-emerald-50/70 lg:flex" aria-label="Navegação principal">
                <a href="#produto" class="transition hover:text-white">Produto</a>
                <a href="#rotina" class="transition hover:text-white">Rotina</a>
                <a href="#planos" class="transition hover:text-white">Oferta</a>
                <a href="#faq" class="transition hover:text-white">Dúvidas</a>
                <a href="{{ route('support') }}" class="transition hover:text-white">Suporte</a>
            </nav>

            <div class="flex shrink-0 items-center gap-2">
                @auth
                    <a href="{{ $dashboardUrl }}" class="rounded-full bg-white px-3 py-2.5 text-xs font-extrabold text-emerald-950 transition hover:bg-emerald-100 sm:px-4 sm:text-sm">
                        <span class="hidden sm:inline">Abrir painel</span>
                        <span class="sm:hidden">Painel</span>
                    </a>
                @else
                    <a href="{{ $loginUrl }}" class="inline-flex rounded-full px-2 py-2.5 text-xs font-bold text-emerald-50/75 transition hover:text-white sm:px-4 sm:text-sm">
                        Entrar
                    </a>
                    <a href="{{ $trialCtaUrl }}" class="rounded-full bg-white px-3 py-2.5 text-xs font-extrabold text-emerald-950 transition hover:bg-emerald-100 sm:px-4 sm:text-sm">
                        <span class="hidden sm:inline">Começar grátis</span>
                        <span class="sm:hidden">Começar</span>
                    </a>
                @endauth
            </div>
        </header>

        <main>
            <section id="produto" class="relative mx-auto grid max-w-7xl overflow-hidden gap-10 px-5 pb-12 pt-10 sm:px-8 lg:grid-cols-[1.02fr_.98fr] lg:px-10 lg:pb-24 lg:pt-16">
                <div class="relative z-10 flex flex-col justify-center">
                    <div class="reveal-lift delay-1 mb-6 inline-flex w-fit items-center gap-3 rounded-full border border-emerald-300/20 bg-emerald-300/10 px-4 py-2 text-xs font-extrabold uppercase tracking-[.24em] text-emerald-100">
                        <span class="h-2 w-2 rounded-full bg-[var(--green)]"></span>
                        Finanças por conversa
                    </div>

                    <h1 class="reveal-lift delay-2 display max-w-4xl text-5xl font-black leading-[.94] text-white sm:text-7xl lg:text-8xl">
                        Seu dinheiro sob seu controle.
                    </h1>

                    <p class="reveal-lift delay-3 mt-7 max-w-2xl text-lg leading-8 text-emerald-50/70 sm:text-xl">
                        O InovaFinance transforma WhatsApp em controle financeiro: você fala o que aconteceu, ele registra, organiza e mostra o caminho no painel.
                    </p>

                    <div class="reveal-lift delay-4 mt-8 flex flex-col gap-3 sm:flex-row">
                        <a href="{{ $trialCtaUrl }}" class="inline-flex items-center justify-center rounded-2xl bg-[var(--green)] px-7 py-4 text-base font-extrabold text-emerald-950 shadow-[0_20px_70px_rgba(25,214,107,.28)] transition hover:brightness-110">
                            Testar por 7 dias
                        </a>
                        <a href="{{ $paidCtaUrl }}" class="inline-flex items-center justify-center rounded-2xl border border-white/15 bg-white/10 px-7 py-4 text-base font-extrabold text-white transition hover:bg-white/10">
                            Comprar acesso por R$ 19,97
                        </a>
                    </div>

                </div>

                <div class="relative min-h-[520px] lg:min-h-[590px]">
                    <div class="absolute -right-20 top-4 h-56 w-56 rounded-full bg-[var(--gold)]/20 blur-3xl" aria-hidden="true"></div>
                    <div class="absolute bottom-8 left-4 h-64 w-64 rounded-full bg-[var(--green)]/20 blur-3xl" aria-hidden="true"></div>

                    <div class="motion-money paper-card absolute right-0 top-0 hidden h-52 w-64 overflow-hidden rounded-[1.8rem] p-0 md:block">
                        <img src="{{ asset('landing/money-hand.png') }}" alt="Colagem financeira com dinheiro e blocos verdes" class="h-full w-full object-cover">
                        <div class="absolute inset-x-4 bottom-4 rounded-2xl bg-emerald-950/90 px-4 py-3 text-xs font-bold leading-5 text-emerald-50 shadow-2xl">
                            Dinheiro precisa de movimento, mas também de direção.
                        </div>
                    </div>

                    <div class="motion-chat dark-card absolute left-0 top-20 w-full max-w-[21rem] rounded-[1.8rem] p-4 lg:left-4">
                        <div class="mb-4 flex items-center justify-between gap-3 border-b border-white/10 pb-4">
                            <div class="flex items-center gap-3">
                                <span class="flex h-9 w-9 items-center justify-center rounded-2xl bg-[var(--green)]/15">
                                    <img src="{{ asset('logo.png') }}" alt="" class="h-5 w-5 object-contain">
                                </span>
                                <div>
                                    <div class="text-sm font-extrabold text-white">InovaFinance</div>
                                    <div class="text-xs font-semibold text-emerald-200/70">online no WhatsApp</div>
                                </div>
                            </div>
                            <span class="rounded-full bg-emerald-400/10 px-3 py-1 text-[11px] font-bold text-emerald-100">com dados</span>
                        </div>

                        <div class="space-y-3 text-xs leading-6">
                            <div class="ml-auto max-w-[85%] rounded-[1.2rem] rounded-tr-sm bg-[var(--green)] px-4 py-2.5 font-semibold text-emerald-950">
                                Gastei 79 no mercado ontem no cartão Nubank
                            </div>
                            <div class="max-w-[88%] rounded-[1.2rem] rounded-tl-sm bg-white/10 px-4 py-2.5 text-emerald-50">
                                Registrei R$ 79,00 em Mercado, no cartão Nubank, com data de ontem.
                            </div>
                            <div class="ml-auto max-w-[82%] rounded-[1.2rem] rounded-tr-sm bg-[var(--green)] px-4 py-2.5 font-semibold text-emerald-950">
                                Quais gastos sem categoria esse mês?
                            </div>
                            <div class="max-w-[92%] rounded-[1.2rem] rounded-tl-sm bg-white/10 px-4 py-2.5 text-emerald-50">
                                Encontrei 2 gastos sem categoria. Total: R$ 179,00. Quer categorizar agora?
                            </div>
                        </div>
                    </div>

                    <div class="motion-piggy paper-card absolute right-8 top-[16.5rem] hidden w-28 overflow-hidden rounded-[1.4rem] border-4 border-white/70 p-0 shadow-2xl lg:block">
                        <img src="{{ asset('landing/piggy-bank.png') }}" alt="Porquinho verde com moeda e padrão de cifrões" class="h-28 w-full object-cover">
                    </div>

                    <div class="motion-panel paper-card cutout absolute bottom-0 right-2 w-[80%] max-w-sm rounded-[2rem] p-5">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <div class="text-xs font-black uppercase tracking-[.24em] text-emerald-900/50">Painel web</div>
                                <h2 class="display mt-3 text-3xl font-black leading-none text-emerald-950">clareza depois da conversa</h2>
                            </div>
                            <div class="rounded-2xl bg-emerald-950 px-3 py-3 text-right text-white">
                                <div class="text-xs text-emerald-100/70">saldo</div>
                                <div class="text-sm font-black">R$ 8.420</div>
                            </div>
                        </div>

                        <div class="mt-6 grid gap-3">
                            <div class="flex items-center justify-between rounded-2xl bg-white/60 p-3">
                                <span class="font-extrabold text-emerald-950">Casa</span>
                                <span class="font-black text-emerald-900">37%</span>
                            </div>
                            <div class="flex items-center justify-between rounded-2xl bg-white/60 p-3">
                                <span class="font-extrabold text-emerald-950">Marketing</span>
                                <span class="font-black text-emerald-900">R$ 355</span>
                            </div>
                            <div class="flex items-center justify-between rounded-2xl bg-emerald-950 p-3 text-white">
                                <span class="font-extrabold">Meta viagem</span>
                                <span class="font-black text-[var(--lime)]">faltam R$ 300</span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="overflow-hidden border-y border-white/10 bg-white/[.04] py-3" aria-label="Recursos em destaque">
                <div class="ticker-track text-[10px] font-black uppercase tracking-[.18em] text-emerald-100/60 sm:text-xs">
                    @for ($i = 0; $i < 2; $i++)
                        <div class="ticker-group" aria-hidden="{{ $i === 1 ? 'true' : 'false' }}">
                            <span class="px-3">gastos</span>
                            <span class="text-[var(--green)] opacity-80">/</span>
                            <span class="px-3">receitas</span>
                            <span class="text-[var(--green)] opacity-80">/</span>
                            <span class="px-3">orçamentos</span>
                            <span class="text-[var(--green)] opacity-80">/</span>
                            <span class="px-3">metas</span>
                            <span class="text-[var(--green)] opacity-80">/</span>
                            <span class="px-3">cartões</span>
                            <span class="text-[var(--green)] opacity-80">/</span>
                            <span class="px-3">lembretes</span>
                            <span class="text-[var(--green)] opacity-80">/</span>
                            <span class="px-3">notas</span>
                            <span class="text-[var(--green)] opacity-80">/</span>
                            <span class="px-3">Drive inteligente</span>
                            <span class="text-[var(--green)] opacity-80">/</span>
                        </div>
                    @endfor
                </div>
            </section>

            <section id="rotina" class="paper-section border-y border-emerald-950/10">
                <div class="mx-auto max-w-7xl px-5 py-20 sm:px-8 lg:px-10">
                    <div class="grid gap-10 lg:grid-cols-[.82fr_1.18fr] lg:items-end">
                        <div>
                            <div class="text-xs font-black uppercase tracking-[.28em] text-emerald-800/70">Rotina real</div>
                            <h2 class="display mt-4 text-4xl font-black leading-tight text-emerald-950 sm:text-6xl">
                                Feito para quem lembra do gasto no meio do dia.
                            </h2>
                        </div>
                        <p class="max-w-2xl text-lg leading-8 text-emerald-950/65">
                            A proposta não é virar mais uma tela esquecida. É reduzir atrito: WhatsApp para capturar, painel para revisar, alertas para não deixar passar.
                        </p>
                    </div>

                    <div class="mt-12 grid gap-5 md:grid-cols-2 lg:grid-cols-3">
                        @php
                            $features = [
                                ['title' => 'Registrar sem parar a vida', 'text' => '“Gastei 42 no Uber” vira lançamento com data, valor e categoria sugerida.'],
                                ['title' => 'Relatório que não esconde bagunça', 'text' => 'Gastos com e sem categoria entram no resumo. Se você pedir só uma categoria, ele filtra.'],
                                ['title' => 'Metas com próximo passo', 'text' => 'Veja quanto falta, abra uma meta específica e calcule quanto guardar por mês.'],
                                ['title' => 'Lembretes úteis', 'text' => 'Contas, aniversários e tarefas financeiras aparecem na hora certa.'],
                                ['title' => 'Arquivos no Drive', 'text' => 'Envie PDF, foto ou áudio e salve em pastas para encontrar depois.'],
                                ['title' => 'Tudo à vista no painel', 'text' => 'Revise seus lançamentos, acompanhe o saldo e encontre o que precisa em um só lugar.'],
                            ];
                        @endphp

                        @foreach ($features as $index => $feature)
                            <article class="paper-feature rounded-[2rem] p-6 transition hover:-translate-y-1 hover:border-emerald-800/25 hover:shadow-[0_24px_65px_rgba(37,47,34,.14)]">
                                <div class="mb-7 flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-950 text-sm font-black text-[var(--lime)]">
                                    {{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}
                                </div>
                                <h3 class="text-xl font-black text-emerald-950">{{ $feature['title'] }}</h3>
                                <p class="mt-3 leading-7 text-emerald-950/65">{{ $feature['text'] }}</p>
                            </article>
                        @endforeach
                    </div>
                </div>
            </section>

            <section class="mx-auto max-w-7xl px-5 pb-20 pt-12 sm:px-8 sm:pt-20 lg:px-10">
                <div class="grid overflow-hidden rounded-[2.6rem] border border-white/10 bg-[var(--paper)] text-emerald-950 lg:grid-cols-[.95fr_1.05fr]">
                    <div class="relative min-h-[420px] overflow-hidden bg-[#e8dfc6] p-8 sm:p-10">
                        <div class="absolute inset-0 opacity-30 dollar-rain" aria-hidden="true"></div>
                        <div class="relative z-10">
                            <div class="text-xs font-black uppercase tracking-[.28em] text-emerald-900/50">Identidade</div>
                            <h2 class="display mt-5 max-w-[21rem] text-5xl font-black leading-none sm:text-6xl">
                                dinheiro com forma, rotina com sinal verde.
                            </h2>
                        </div>
                        <img src="{{ asset('landing/financial-column-cutout.png') }}" alt="Coluna clássica formada por moedas, com elementos financeiros ao fundo" class="absolute bottom-0 right-0 w-56 max-w-[52%] translate-x-4 drop-shadow-2xl sm:w-72 lg:w-80">
                    </div>

                    <div class="bg-emerald-950 p-8 text-white sm:p-10 lg:p-12">
                        <div class="grid gap-5">
                            <div class="rounded-3xl border border-white/10 bg-white/[.07] p-5">
                                <div class="font-black">Não depende de frase perfeita</div>
                                <p class="mt-2 text-sm leading-7 text-emerald-50/60">O assistente entende variações comuns e pede o dado que faltar antes de registrar.</p>
                            </div>
                            <div class="rounded-3xl border border-white/10 bg-white/[.07] p-5">
                                <div class="font-black">Não mistura tudo no mesmo contexto</div>
                                <p class="mt-2 text-sm leading-7 text-emerald-50/60">Nota, meta, Drive e relatório seguem trilhas separadas para reduzir confusão.</p>
                            </div>
                            <div class="rounded-3xl border border-white/10 bg-white/[.07] p-5">
                                <div class="font-black">Não trava se a IA falhar</div>
                                <p class="mt-2 text-sm leading-7 text-emerald-50/60">Arquivos continuam sendo salvos, pagamentos continuam verificáveis e dados críticos seguem fluxo previsível.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section id="planos" class="mx-auto max-w-7xl px-5 pb-20 sm:px-8 lg:px-10">
                <div class="relative overflow-hidden rounded-[3rem] border border-emerald-300/20 bg-gradient-to-br from-[#102719] via-[#07110b] to-[#07100d] p-7 shadow-[0_36px_120px_rgba(0,0,0,.35)] sm:p-10 lg:p-14">
                    <div class="absolute -right-20 -top-20 h-72 w-72 rounded-full bg-[var(--green)]/20 blur-3xl" aria-hidden="true"></div>
                    <div class="absolute -bottom-24 left-10 h-72 w-72 rounded-full bg-[var(--gold)]/15 blur-3xl" aria-hidden="true"></div>

                    <div class="relative grid gap-10 lg:grid-cols-[1.05fr_.95fr] lg:items-center">
                        <div>
                            <div class="inline-flex rounded-full bg-[var(--lime)] px-4 py-2 text-xs font-black uppercase tracking-[.24em] text-emerald-950">
                                Oferta única
                            </div>
                            <h2 class="display mt-6 max-w-2xl text-5xl font-black leading-none text-white sm:text-6xl">
                                Acesso completo por menos que uma assinatura esquecida.
                            </h2>
                            <p class="mt-6 max-w-xl text-lg leading-8 text-emerald-50/70">
                                Pro mensal com WhatsApp, painel, metas, orçamentos, relatórios, lembretes, notas e Drive inteligente.
                            </p>
                        </div>

                        <div class="paper-card relative overflow-hidden rounded-[2.5rem] p-7 sm:p-8">
                            <img src="{{ asset('landing/piggy-bank.png') }}" alt="" class="absolute -right-10 -top-10 h-40 w-40 rotate-6 rounded-[2rem] object-cover opacity-20" aria-hidden="true">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <div class="text-sm font-black uppercase tracking-[.22em] text-emerald-900/50">Plano Pro</div>
                                    <div class="mt-4 flex items-end gap-2">
                                        <span class="display text-6xl font-black leading-none text-emerald-950">R$ 19,97</span>
                                        <span class="pb-2 text-sm font-bold text-emerald-900/60">/mês</span>
                                    </div>
                                </div>
                                <span class="rounded-full bg-emerald-950 px-4 py-2 text-xs font-black text-[var(--lime)]">30% off</span>
                            </div>

                            <ul class="mt-7 space-y-3 text-sm font-semibold text-emerald-950/102">
                                <li class="flex gap-3"><span class="text-emerald-700">✓</span> 7 dias grátis para testar</li>
                                <li class="flex gap-3"><span class="text-emerald-700">✓</span> Pode comprar direto sem esperar o teste</li>
                                <li class="flex gap-3"><span class="text-emerald-700">✓</span> Pagamento recorrente no cartão</li>
                                <li class="flex gap-3"><span class="text-emerald-700">✓</span> Cancele quando quiser pelo painel</li>
                            </ul>

                            <div class="mt-8 grid gap-3 sm:grid-cols-2">
                                <a href="{{ $trialCtaUrl }}" class="inline-flex items-center justify-center rounded-2xl bg-emerald-950 px-5 py-4 font-black text-white transition hover:bg-emerald-900">
                                    Começar grátis
                                </a>
                                <a href="{{ $paidCtaUrl }}" class="inline-flex items-center justify-center rounded-2xl border border-emerald-950/15 bg-white/70 px-5 py-4 font-black text-emerald-950 transition hover:bg-white">
                                    Comprar agora
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="mx-auto max-w-7xl px-5 pb-20 sm:px-8 lg:px-10">
                <div class="grid gap-5 lg:grid-cols-3">
                    <article class="dark-card rounded-[2rem] p-7 lg:col-span-1">
                        <div class="text-xs font-black uppercase tracking-[.26em] text-[var(--gold)]">Como começa</div>
                        <h2 class="display mt-4 text-4xl font-black leading-tight text-white">3 passos, sem manual.</h2>
                    </article>
                    <article class="dark-card rounded-[2rem] p-7">
                        <div class="text-3xl font-black text-[var(--green)]">01</div>
                        <h3 class="mt-5 text-xl font-black text-white">Crie sua conta</h3>
                        <p class="mt-3 leading-7 text-emerald-50/60">Entre no app, escolha testar grátis ou assinar direto.</p>
                    </article>
                    <article class="dark-card rounded-[2rem] p-7">
                        <div class="text-3xl font-black text-[var(--green)]">02</div>
                        <h3 class="mt-5 text-xl font-black text-white">Ative seu WhatsApp</h3>
                        <p class="mt-3 leading-7 text-emerald-50/60">Valide seu número para o assistente saber que os dados são seus.</p>
                    </article>
                    <article class="dark-card rounded-[2rem] p-7 lg:col-start-2">
                        <div class="text-3xl font-black text-[var(--green)]">03</div>
                        <h3 class="mt-5 text-xl font-black text-white">Use frases naturais</h3>
                        <p class="mt-3 leading-7 text-emerald-50/60">“Recebi 1200”, “quais gastos sem categoria?”, “salva esse PDF no Drive”.</p>
                    </article>
                    <article class="paper-card rounded-[2rem] p-7">
                        <div class="text-xs font-black uppercase tracking-[.22em] text-emerald-900/50">Suporte</div>
                        <h3 class="display mt-4 text-3xl font-black leading-tight text-emerald-950">Tem humano por perto.</h3>
                        <p class="mt-3 leading-7 text-emerald-950/70">Se algo sair estranho, você fala com suporte e os casos reais viram melhoria no assistente.</p>
                        <div class="mt-6 flex flex-wrap gap-3">
                            <a href="{{ route('support') }}" class="rounded-2xl bg-emerald-950 px-5 py-3 text-sm font-black text-white">Abrir suporte</a>
                            @if ($supportWhatsappUrl)
                                <a href="{{ $supportWhatsappUrl }}" class="rounded-2xl border border-emerald-950/15 bg-white/70 px-5 py-3 text-sm font-black text-emerald-950">WhatsApp</a>
                            @endif
                        </div>
                    </article>
                </div>
            </section>

            <section id="faq" class="mx-auto max-w-4xl px-5 pb-24 sm:px-8 lg:px-10">
                <div class="text-center">
                    <div class="text-xs font-black uppercase tracking-[.28em] text-[var(--gold)]">Dúvidas comuns</div>
                    <h2 class="display mt-4 text-4xl font-black leading-tight text-white sm:text-5xl">Antes de entrar.</h2>
                </div>

                <div class="mt-10 space-y-4">
                    <details class="dark-card group rounded-3xl p-6">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 font-black text-white">
                            O InovaFinance substitui meu banco?
                            <span class="text-[var(--green)] transition group-open:rotate-45">+</span>
                        </summary>
                        <p class="mt-4 leading-7 text-emerald-50/60">Não. Ele organiza sua vida financeira e seus registros. Você continua usando banco, cartão e Drive normalmente.</p>
                    </details>
                    <details class="dark-card group rounded-3xl p-6">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 font-black text-white">
                            Preciso usar palavras exatas?
                            <span class="text-[var(--green)] transition group-open:rotate-45">+</span>
                        </summary>
                        <p class="mt-4 leading-7 text-emerald-50/60">Não. Você pode escrever como falaria no WhatsApp. Quando faltar algo importante, o assistente pergunta antes de concluir.</p>
                    </details>
                    <details class="dark-card group rounded-3xl p-6">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 font-black text-white">
                            Posso ver tudo pelo site?
                            <span class="text-[var(--green)] transition group-open:rotate-45">+</span>
                        </summary>
                        <p class="mt-4 leading-7 text-emerald-50/60">Sim. O WhatsApp agiliza o registro e o painel mostra dashboard, transações, metas, cartões, relatórios, notas, lembretes e arquivos.</p>
                    </details>
                    <details class="dark-card group rounded-3xl p-6">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 font-black text-white">
                            Posso cancelar quando quiser?
                            <span class="text-[var(--green)] transition group-open:rotate-45">+</span>
                        </summary>
                        <p class="mt-4 leading-7 text-emerald-50/60">Sim. A assinatura pode ser cancelada pelo painel, sem precisar pedir manualmente ao suporte.</p>
                    </details>
                </div>
            </section>
        </main>

        <footer class="border-t border-white/10 bg-black/20">
            <div class="mx-auto grid max-w-7xl gap-10 px-5 py-12 sm:px-8 lg:grid-cols-[1.2fr_.8fr_.8fr_1fr] lg:px-10">
                <div>
                    <div class="flex items-center gap-3">
                        <span class="flex h-11 w-11 items-center justify-center rounded-2xl border border-emerald-300/20 bg-emerald-400/10">
                            <img src="{{ asset('logo.png') }}" alt="InovaFinance" class="h-7 w-7 object-contain">
                        </span>
                        <div>
                            <div class="font-black text-white">InovaFinance</div>
                            <div class="text-sm text-emerald-50/50">Controle financeiro por conversa.</div>
                        </div>
                    </div>
                    <p class="mt-5 max-w-sm text-sm leading-7 text-emerald-50/50">
                        Um produto da InovaForce IT para transformar registros financeiros soltos em rotina simples, clara e acompanhável.
                    </p>
                </div>

                <div>
                    <div class="text-sm font-black uppercase tracking-[.22em] text-emerald-50/40">Produto</div>
                    <ul class="mt-4 space-y-3 text-sm font-semibold text-emerald-50/60">
                        <li><a href="#produto" class="hover:text-white">Início</a></li>
                        <li><a href="#rotina" class="hover:text-white">Rotina</a></li>
                        <li><a href="#planos" class="hover:text-white">Oferta</a></li>
                    </ul>
                </div>

                <div>
                    <div class="text-sm font-black uppercase tracking-[.22em] text-emerald-50/40">Conta</div>
                    <ul class="mt-4 space-y-3 text-sm font-semibold text-emerald-50/60">
                        <li><a href="{{ $loginUrl }}" class="hover:text-white">Entrar</a></li>
                        <li><a href="{{ $trialCtaUrl }}" class="hover:text-white">Criar conta</a></li>
                        <li><a href="{{ $paidCtaUrl }}" class="hover:text-white">Comprar acesso</a></li>
                    </ul>
                </div>

                <div>
                    <div class="text-sm font-black uppercase tracking-[.22em] text-emerald-50/40">Legal e suporte</div>
                    <ul class="mt-4 space-y-3 text-sm font-semibold text-emerald-50/60">
                        <li><a href="{{ route('support') }}" class="hover:text-white">Suporte</a></li>
                        <li><a href="mailto:{{ $supportEmail }}" class="hover:text-white">{{ $supportEmail }}</a></li>
                        <li><a href="{{ route('privacy-policy') }}" class="hover:text-white">Política de privacidade</a></li>
                        <li><a href="{{ route('terms-of-use') }}" class="hover:text-white">Termos de uso</a></li>
                    </ul>
                </div>
            </div>

            <div class="mx-auto flex max-w-7xl flex-col gap-3 border-t border-white/10 px-5 py-6 text-sm text-emerald-50/40 sm:flex-row sm:items-center sm:justify-between sm:px-8 lg:px-10">
                <span>&copy; 2026 InovaForce IT. Todos os direitos reservados.</span>
                <span>InovaFinance não é instituição financeira.</span>
            </div>
        </footer>
        <script>
            (() => {
                const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
                if (reducedMotion.matches || !('IntersectionObserver' in window)) return;

                const observer = new IntersectionObserver((entries) => {
                    entries.forEach(({ target, isIntersecting }) => {
                        if (!isIntersecting) return;
                        target.classList.add('is-visible');
                        observer.unobserve(target);
                    });
                }, { threshold: 0.08 });

                // Animate independent blocks without nesting hidden containers.
                const blocks = document.querySelectorAll(
                    'main section:not(#produto) article, main section:not(#produto) h2, '
                    + '#faq details, #planos .paper-card'
                );
                blocks.forEach((block, index) => {
                    if (block.closest('article')) {
                        if (block.tagName !== 'ARTICLE') return;
                    }
                    if (block.getBoundingClientRect().top < window.innerHeight) return;
                    block.style.setProperty('--enter-delay', `${(index % 3) * 70}ms`);
                    observer.observe(block);
                    block.classList.add('scroll-enter');
                });

                reducedMotion.addEventListener('change', ({ matches }) => {
                    if (!matches) return;
                    observer.disconnect();
                    blocks.forEach(block => block.classList.add('is-visible'));
                });
            })();
        </script>
    </body>
</html>

