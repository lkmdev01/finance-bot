@php
    $supportEmail = config('support.email');
    $whatsappUrl = config('support.whatsapp_url');
    $supportNumber = preg_replace('/\D+/', '', (string) config('support.whatsapp_number'));

    if (! $whatsappUrl && $supportNumber) {
        $whatsappUrl = "https://wa.me/{$supportNumber}";
    }
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Suporte | InovaFinance</title>
    <meta name="description" content="Canais oficiais de suporte do InovaFinance para ajuda com conta, assinatura, WhatsApp e pagamentos.">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[#050b14] text-white antialiased">
    <main class="relative min-h-screen overflow-hidden">
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_top,_rgba(16,185,129,0.18),_transparent_35%),radial-gradient(circle_at_bottom_right,_rgba(34,211,238,0.14),_transparent_34%)]"></div>

        <section class="relative mx-auto flex min-h-screen max-w-5xl items-center px-4 py-12 sm:px-6 lg:px-8">
            <div class="w-full overflow-hidden rounded-[2rem] border border-white/10 bg-slate-950/75 p-6 shadow-[0_28px_100px_rgba(0,0,0,0.45)] backdrop-blur sm:p-10">
                <a href="{{ route('home') }}" class="inline-flex items-center gap-3 text-sm font-bold text-emerald-200">
                    <img src="{{ asset('brand/logo-inovafinance-icon.png') }}" class="h-10 w-10 rounded-2xl" alt="InovaFinance">
                    InovaFinance
                </a>

                <div class="mt-10 grid gap-8 lg:grid-cols-[1.05fr_0.95fr] lg:items-center">
                    <div>
                        <p class="text-sm font-bold uppercase tracking-[0.22em] text-cyan-200">Suporte oficial</p>
                        <h1 class="mt-3 text-4xl font-black tracking-tight sm:text-5xl">Precisa de ajuda?</h1>
                        <p class="mt-4 max-w-2xl text-base leading-8 text-slate-300">
                            Fale com a gente sobre acesso, assinatura, pagamento, WhatsApp, Drive ou duvidas gerais do InovaFinance.
                            {{ config('support.response_time') }}.
                        </p>

                        <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                            @if($whatsappUrl)
                                <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener" class="inline-flex items-center justify-center rounded-2xl bg-emerald-300 px-5 py-3 text-sm font-black text-slate-950 shadow-lg shadow-emerald-500/20 transition hover:bg-emerald-200">
                                    Chamar no WhatsApp
                                </a>
                            @endif
                            @if($supportEmail)
                                <a href="mailto:{{ $supportEmail }}" class="inline-flex items-center justify-center rounded-2xl border border-white/10 px-5 py-3 text-sm font-bold text-white transition hover:bg-white/10">
                                    Enviar e-mail
                                </a>
                            @endif
                        </div>

                        @auth
                            <a href="{{ route('dashboard') }}" class="mt-6 inline-flex text-sm font-bold text-cyan-200 underline underline-offset-4">Voltar para o dashboard</a>
                        @else
                            <a href="{{ route('login') }}" class="mt-6 inline-flex text-sm font-bold text-cyan-200 underline underline-offset-4">Entrar na minha conta</a>
                        @endauth
                    </div>

                    <div class="rounded-[1.75rem] border border-white/10 bg-white/[0.04] p-5">
                        <h2 class="text-xl font-black">O que enviar para agilizar</h2>
                        <div class="mt-5 space-y-4 text-sm leading-6 text-slate-300">
                            <div class="rounded-2xl border border-white/10 bg-slate-950/60 p-4">
                                <p class="font-bold text-white">Conta ou login</p>
                                <p class="mt-1">Envie seu e-mail de cadastro e descreva o erro.</p>
                            </div>
                            <div class="rounded-2xl border border-white/10 bg-slate-950/60 p-4">
                                <p class="font-bold text-white">Assinatura ou pagamento</p>
                                <p class="mt-1">Informe plano, data aproximada do pagamento e print se tiver.</p>
                            </div>
                            <div class="rounded-2xl border border-white/10 bg-slate-950/60 p-4">
                                <p class="font-bold text-white">WhatsApp ou IA</p>
                                <p class="mt-1">Mande a frase usada e a resposta recebida para conseguirmos reproduzir.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </main>
</body>
</html>
