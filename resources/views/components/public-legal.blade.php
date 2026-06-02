<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        @php
            $pageTitle = trim(($title ?? 'Documento legal').' | InovaFinance');
            $pageDescription = $description ?? 'Documento legal publico do InovaFinance.';
            $pageUrl = url()->current();
            $faviconVersion = file_exists(public_path('favicon.ico')) ? filemtime(public_path('favicon.ico')) : time();
        @endphp

        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $pageTitle }}</title>
        <meta name="description" content="{{ $pageDescription }}">
        <meta name="robots" content="index, follow">
        <meta name="theme-color" content="#070b14">
        <link rel="canonical" href="{{ $pageUrl }}">

        <meta property="og:type" content="article">
        <meta property="og:site_name" content="InovaFinance">
        <meta property="og:locale" content="pt_BR">
        <meta property="og:title" content="{{ $pageTitle }}">
        <meta property="og:description" content="{{ $pageDescription }}">
        <meta property="og:url" content="{{ $pageUrl }}">

        <link rel="icon" href="/favicon.ico?v={{ $faviconVersion }}" sizes="any">
        <link rel="shortcut icon" href="/favicon.ico?v={{ $faviconVersion }}">
        <link rel="icon" href="/favicon-512.png?v={{ $faviconVersion }}" type="image/png">

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

        <script src="https://cdn.tailwindcss.com"></script>
        <script>
            tailwind.config = {
                theme: {
                    extend: {
                        fontFamily: {
                            sans: ['Outfit', 'sans-serif'],
                        },
                        colors: {
                            space: {
                                950: '#070b14',
                                900: '#0f172a',
                                800: '#1e293b',
                            },
                            primary: '#22c55e',
                        }
                    }
                }
            }
        </script>

        <style>
            html, body {
                min-height: 100%;
                background:
                    radial-gradient(circle at top left, rgba(34, 197, 94, 0.16), transparent 28%),
                    radial-gradient(circle at bottom right, rgba(56, 189, 248, 0.14), transparent 32%),
                    #070b14;
                color: #f8fafc;
            }

            .glass {
                background: rgba(15, 23, 42, 0.78);
                backdrop-filter: blur(16px);
                border: 1px solid rgba(255, 255, 255, 0.08);
            }

            .legal-copy h2 {
                font-size: 1.25rem;
                line-height: 1.75rem;
                font-weight: 800;
                color: #ffffff;
                margin-top: 2rem;
                margin-bottom: 0.75rem;
            }

            .legal-copy p,
            .legal-copy li {
                color: rgb(203 213 225);
                line-height: 1.8;
            }

            .legal-copy ul {
                list-style: disc;
                padding-left: 1.4rem;
                margin-top: 0.75rem;
            }

            .legal-copy a {
                color: rgb(103 232 249);
                text-decoration: underline;
                text-decoration-color: rgba(103, 232, 249, 0.45);
            }
        </style>
    </head>
    <body class="font-sans antialiased">
        <div class="mx-auto max-w-5xl px-6 py-8 sm:py-10">
            <nav class="mb-8 flex items-center justify-between gap-4">
                <a href="{{ route('home') }}" class="group inline-flex items-center gap-3">
                    <span class="inline-flex h-11 w-11 items-center justify-center rounded-2xl border border-primary/20 bg-primary/10 p-2 transition group-hover:scale-105">
                        <img src="{{ asset('logo.png') }}" alt="InovaFinance" class="h-full w-full object-contain">
                    </span>
                    <span>
                        <span class="block text-lg font-black tracking-tight text-white">InovaFinance</span>
                        <span class="block text-xs uppercase tracking-[0.22em] text-slate-400">Documento legal</span>
                    </span>
                </a>

                <a href="{{ route('home') }}" class="inline-flex items-center gap-2 rounded-2xl border border-white/10 bg-white/5 px-4 py-2.5 text-sm font-semibold text-slate-200 transition hover:bg-white/10 hover:text-white">
                    <span aria-hidden="true">←</span>
                    Voltar ao site
                </a>
            </nav>

            <main class="glass overflow-hidden rounded-[32px] shadow-[0_32px_120px_rgba(2,6,23,0.52)]">
                <div class="border-b border-white/10 px-6 py-8 sm:px-10">
                    <div class="inline-flex items-center gap-2 rounded-full border border-primary/20 bg-primary/10 px-4 py-2 text-xs font-bold uppercase tracking-[0.24em] text-primary">
                        {{ $eyebrow ?? 'Informacao oficial' }}
                    </div>

                    <h1 class="mt-5 text-3xl font-black tracking-tight text-white sm:text-5xl">
                        {{ $heading ?? $title ?? 'Documento legal' }}
                    </h1>

                    <p class="mt-4 max-w-3xl text-base leading-8 text-slate-300 sm:text-lg">
                        {{ $lead ?? $description ?? '' }}
                    </p>

                    <div class="mt-6 flex flex-wrap gap-3 text-sm text-slate-400">
                        <span class="rounded-full border border-white/10 bg-white/5 px-4 py-2">Ultima atualizacao: {{ $updatedAt ?? now()->format('d/m/Y') }}</span>
                        <span class="rounded-full border border-white/10 bg-white/5 px-4 py-2">Dominio: finance.inovaforce.com.br</span>
                    </div>
                </div>

                <div class="px-6 py-8 sm:px-10 sm:py-10">
                    <article class="legal-copy prose prose-invert max-w-none">
                        {{ $slot }}
                    </article>
                </div>
            </main>
        </div>
    </body>
</html>
