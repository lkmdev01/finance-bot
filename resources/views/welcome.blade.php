<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        @php
            $seoTitle = 'InovaFinance | Gestao financeira via WhatsApp com IA';
            $seoDescription = 'Controle gastos, receitas, orcamentos, metas e relatorios financeiros pelo WhatsApp com apoio de IA.';
            $seoImage = asset('social-card.png');
            $seoUrl = route('home');
            $seoKeywords = 'gestao financeira, controle financeiro, financas pessoais, whatsapp, inteligencia artificial, orcamento, controle de gastos';
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
                ],
            ];

            $primaryCtaUrl = Route::has('register') ? route('register') : (Route::has('login') ? route('login') : '#');
        @endphp

        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $seoTitle }}</title>
        <meta name="description" content="{{ $seoDescription }}">
        <meta name="keywords" content="{{ $seoKeywords }}">
        <meta name="robots" content="index, follow">
        <meta name="googlebot" content="index, follow">
        <meta name="theme-color" content="#070b14">
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
        <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">

        <script src="https://cdn.tailwindcss.com"></script>
        <script>
            tailwind.config = {
                darkMode: 'class',
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
                background-color: #070b14;
                color: #f8fafc;
            }
            .blur-gradient {
                background: radial-gradient(circle at 50% 50%, rgba(34, 197, 94, 0.14) 0%, rgba(7, 11, 20, 0) 70%);
            }
            .glass {
                background: rgba(30, 41, 59, 0.5);
                backdrop-filter: blur(12px);
                border: 1px solid rgba(255, 255, 255, 0.1);
            }
            .rocket-float {
                animation: float 5s ease-in-out infinite;
            }
            @keyframes float {
                0%, 100% { transform: translateY(0); }
                50% { transform: translateY(-15px); }
            }
            .mix-screen {
                mix-blend-mode: screen;
            }
        </style>
    </head>
    <body class="antialiased overflow-x-hidden pt-10">
        <nav class="relative z-50 px-6 py-6 max-w-7xl mx-auto flex items-center justify-between gap-4">
            <a href="{{ route('home') }}" class="flex items-center gap-3 group min-w-0">
                <div class="w-10 h-10 bg-primary/15 border border-primary/25 rounded-xl flex items-center justify-center shadow-[0_0_18px_rgba(34,197,94,0.35)] transition-transform group-hover:scale-105 p-2 shrink-0">
                    <img src="{{ asset('logo.png') }}" alt="InovaFinance" class="h-full w-full object-contain" />
                </div>
                <span class="text-xl font-bold tracking-tight truncate">InovaFinance</span>
            </a>

            <div class="hidden md:flex items-center gap-8 text-sm font-medium text-slate-300">
                <a href="#produto" class="hover:text-white transition-colors">Produto</a>
                <a href="#recursos" class="hover:text-white transition-colors">Recursos</a>
                <a href="#planos" class="hover:text-white transition-colors">Planos</a>
                <a href="#depoimentos" class="hover:text-white transition-colors">Historias</a>
                <a href="#faq" class="hover:text-white transition-colors">FAQ</a>
            </div>

            <div class="flex items-center gap-3 shrink-0">
                @if (Route::has('login'))
                    @auth
                        <a href="{{ url('/dashboard') }}" class="px-4 py-2.5 bg-primary text-space-950 rounded-xl font-semibold transition-all shadow-lg hover:shadow-primary/25 hover:brightness-110">
                            Ir para o dashboard
                        </a>
                    @else
                        <a href="{{ route('login') }}" class="px-3 py-2.5 text-sm font-semibold text-slate-200 hover:text-white transition-colors">
                            Entrar
                        </a>
                        <a href="{{ $primaryCtaUrl }}" class="px-4 py-2.5 bg-white text-space-950 rounded-xl font-bold hover:bg-slate-200 transition-all shadow-xl">
                            Comecar agora
                        </a>
                    @endauth
                @endif
            </div>
        </nav>

        <section id="produto" class="relative z-10 pt-10 pb-14 px-6 max-w-7xl mx-auto grid lg:grid-cols-2 gap-10 items-center">
            <div class="absolute inset-0 blur-gradient opacity-80 -z-10"></div>
            <div class="absolute top-0 left-0 w-64 h-64 bg-primary/10 rounded-full blur-3xl -z-10"></div>
            <div class="absolute bottom-0 right-0 w-72 h-72 bg-cyan-400/10 rounded-full blur-3xl -z-10"></div>

            <div class="space-y-7 text-center lg:text-left">
                <div class="flex flex-wrap justify-center lg:justify-start gap-2">
                    <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full glass text-xs text-slate-200 border-primary/20">
                        <span class="w-2 h-2 rounded-full bg-primary"></span>
                        WhatsApp + IA + Dashboard
                    </span>
                    <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full glass text-xs text-slate-200">
                        Orcamentos, metas, recorrencias e lembretes
                    </span>
                </div>

                <h1 class="text-4xl lg:text-6xl font-bold leading-tight tracking-tight">
                    Seu financeiro em dia,
                    <span class="text-transparent bg-clip-text bg-gradient-to-r from-primary to-cyan-400">pelo WhatsApp</span>.
                </h1>
                <p class="text-lg lg:text-xl text-slate-300 max-w-xl mx-auto lg:mx-0 leading-relaxed">
                    Registre gastos e ganhos com frases simples (ou audio), e acompanhe tudo no painel: categorias, cartoes, orcamentos, projecoes e alertas.
                </p>

                <div class="flex flex-col sm:flex-row gap-3 justify-center lg:justify-start">
                    <a href="{{ $primaryCtaUrl }}" class="px-7 py-4 bg-white text-space-950 rounded-2xl font-extrabold hover:bg-slate-200 transition-all shadow-2xl hover:scale-[1.01]">
                        Comecar agora
                    </a>
                    <a href="#como-funciona" class="px-7 py-4 glass rounded-2xl font-bold hover:border-primary/40 transition-all">
                        Ver como funciona
                    </a>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-left">
                    <div class="glass rounded-2xl p-4">
                        <div class="text-xs text-slate-400">Exemplo</div>
                        <div class="font-semibold">"Gastei 20 no uber"</div>
                    </div>
                    <div class="glass rounded-2xl p-4">
                        <div class="text-xs text-slate-400">Exemplo</div>
                        <div class="font-semibold">"Me lembra dia 5 pagar o design"</div>
                    </div>
                    <div class="glass rounded-2xl p-4">
                        <div class="text-xs text-slate-400">Exemplo</div>
                        <div class="font-semibold">"Criar orcamento 500 compras"</div>
                    </div>
                </div>

                <div class="flex flex-wrap justify-center lg:justify-start gap-5 text-sm text-slate-400">
                    <div class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-primary/80"></span> Sem planilhas</div>
                    <div class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-primary/80"></span> Sem "app pesado"</div>
                    <div class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-primary/80"></span> Respostas com base nos seus dados</div>
                </div>
            </div>

            <div class="relative rocket-float">
                <div class="glass rounded-[36px] p-5 shadow-2xl overflow-hidden">
                    <img src="/hero.png" alt="InovaFinance" class="w-full max-w-[620px] mx-auto mix-screen">
                </div>
                <div class="absolute -top-10 -right-10 w-40 h-40 bg-primary/10 rounded-full blur-3xl"></div>
                <div class="absolute -bottom-10 -left-10 w-40 h-40 bg-cyan-400/10 rounded-full blur-3xl"></div>
            </div>
        </section>

        <section class="relative z-10 py-10 px-6 max-w-7xl mx-auto">
            <div class="glass rounded-3xl p-6 lg:p-8">
                <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-6">
                    <div>
                        <div class="text-xs uppercase tracking-wider text-slate-400">Foco</div>
                        <div class="text-lg font-bold">Velocidade no registro</div>
                        <div class="text-sm text-slate-400">Anote no momento que acontecer.</div>
                    </div>
                    <div>
                        <div class="text-xs uppercase tracking-wider text-slate-400">Organizacao</div>
                        <div class="text-lg font-bold">Categorias e alertas</div>
                        <div class="text-sm text-slate-400">Menos surpresa no fim do mes.</div>
                    </div>
                    <div>
                        <div class="text-xs uppercase tracking-wider text-slate-400">Fontes</div>
                        <div class="text-lg font-bold">Cartoes e contas</div>
                        <div class="text-sm text-slate-400">Credito, debito e saldo.</div>
                    </div>
                    <div>
                        <div class="text-xs uppercase tracking-wider text-slate-400">Assistente</div>
                        <div class="text-lg font-bold">Lembretes e recorrencias</div>
                        <div class="text-sm text-slate-400">Automatize o que sempre volta.</div>
                    </div>
                </div>
            </div>
        </section>

        <section id="recursos" class="relative z-10 py-16 px-6 max-w-7xl mx-auto">
            <div class="text-center mb-12">
                <h2 class="text-3xl lg:text-4xl font-bold mb-3">Recursos que deixam o dia a dia leve</h2>
                <p class="text-slate-400 max-w-2xl mx-auto">Uma base confiavel de dados + conversa natural. O sistema cuida do resto.</p>
            </div>

            <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-6">
                <div class="glass p-7 rounded-3xl hover:border-primary/50 transition-all group">
                    <div class="w-12 h-12 bg-primary/18 rounded-2xl flex items-center justify-center text-xl mb-5 group-hover:scale-110 transition-transform">💬</div>
                    <h3 class="text-lg font-bold mb-2">WhatsApp de verdade</h3>
                    <p class="text-slate-400 text-sm leading-relaxed">Crie, edite e apague lancamentos com follow-up (esse, o ultimo, ontem...).</p>
                </div>
                <div class="glass p-7 rounded-3xl hover:border-primary/50 transition-all group">
                    <div class="w-12 h-12 bg-cyan-400/18 rounded-2xl flex items-center justify-center text-xl mb-5 group-hover:scale-110 transition-transform">🧠</div>
                    <h3 class="text-lg font-bold mb-2">IA com limites</h3>
                    <p class="text-slate-400 text-sm leading-relaxed">IA para ambiguidades. Fluxo principal deterministico para nao quebrar dados.</p>
                </div>
                <div class="glass p-7 rounded-3xl hover:border-primary/50 transition-all group">
                    <div class="w-12 h-12 bg-amber-500/18 rounded-2xl flex items-center justify-center text-xl mb-5 group-hover:scale-110 transition-transform">🚨</div>
                    <h3 class="text-lg font-bold mb-2">Orcamentos e alertas</h3>
                    <p class="text-slate-400 text-sm leading-relaxed">Limites por categoria com avisos naturais quando o consumo apertar.</p>
                </div>
                <div class="glass p-7 rounded-3xl hover:border-primary/50 transition-all group">
                    <div class="w-12 h-12 bg-violet-400/18 rounded-2xl flex items-center justify-center text-xl mb-5 group-hover:scale-110 transition-transform">🏦</div>
                    <h3 class="text-lg font-bold mb-2">Contas e cartoes</h3>
                    <p class="text-slate-400 text-sm leading-relaxed">Se falou "no cartao Nubank", vai pro cartao. Se nao falou, vai pro saldo.</p>
                </div>
            </div>
        </section>

        <section id="como-funciona" class="relative z-10 py-20 bg-slate-900/30">
            <div class="max-w-7xl mx-auto px-6 grid lg:grid-cols-2 gap-12 items-center">
                <div>
                    <h2 class="text-3xl lg:text-4xl font-bold mb-5">Como funciona na pratica</h2>
                    <p class="text-slate-300 mb-8">Voce conversa. O InovaFinance organiza e calcula. No final, voce tem previsibilidade.</p>

                    <div class="space-y-4">
                        <div class="glass rounded-2xl p-5 flex gap-4">
                            <div class="w-9 h-9 rounded-xl bg-primary/20 border border-primary/20 flex items-center justify-center font-bold shrink-0">1</div>
                            <div>
                                <div class="font-bold">Conecte o WhatsApp</div>
                                <div class="text-sm text-slate-400">Ativacao rapida e pronta para registrar no dia a dia.</div>
                            </div>
                        </div>
                        <div class="glass rounded-2xl p-5 flex gap-4">
                            <div class="w-9 h-9 rounded-xl bg-primary/20 border border-primary/20 flex items-center justify-center font-bold shrink-0">2</div>
                            <div>
                                <div class="font-bold">Registre e ajuste</div>
                                <div class="text-sm text-slate-400">"gastei 20 no uber", "ajusta para 15", "apaga o ultimo".</div>
                            </div>
                        </div>
                        <div class="glass rounded-2xl p-5 flex gap-4">
                            <div class="w-9 h-9 rounded-xl bg-primary/20 border border-primary/20 flex items-center justify-center font-bold shrink-0">3</div>
                            <div>
                                <div class="font-bold">Acompanhe no painel</div>
                                <div class="text-sm text-slate-400">Relatorios, projecoes, metas, recorrencias, cartoes e alertas.</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="glass p-4 rounded-[34px] shadow-2xl overflow-hidden">
                    <div class="bg-slate-800/60 p-6 rounded-[26px]">
                        <div class="flex items-center gap-3 mb-6">
                            <div class="w-3 h-3 rounded-full bg-red-400"></div>
                            <div class="w-3 h-3 rounded-full bg-amber-400"></div>
                            <div class="w-3 h-3 rounded-full bg-green-400"></div>
                        </div>
                        <div class="space-y-4 text-sm">
                            <div class="flex justify-end">
                                <div class="bg-primary/15 border border-primary/25 p-3 rounded-2xl rounded-tr-none max-w-[85%]">
                                    Gastei 32 no mercado no cartao Nubank
                                </div>
                            </div>
                            <div class="flex justify-start">
                                <div class="glass p-3 rounded-2xl rounded-tl-none max-w-[85%]">
                                    Registro feito no cartao Nubank. Quer debito (saldo) ou credito (limite)?
                                </div>
                            </div>
                            <div class="flex justify-end">
                                <div class="bg-primary/15 border border-primary/25 p-3 rounded-2xl rounded-tr-none max-w-[85%]">
                                    Credito
                                </div>
                            </div>
                            <div class="flex justify-start">
                                <div class="glass p-3 rounded-2xl rounded-tl-none max-w-[85%]">
                                    Pronto. Atualizei o limite e o painel. Se quiser, eu comparo com o mes passado.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="planos" class="relative z-10 py-20 px-6 max-w-7xl mx-auto">
            <div class="text-center mb-10">
                <h2 class="text-3xl lg:text-4xl font-bold mb-3">Planos simples</h2>
                <p class="text-slate-400 max-w-2xl mx-auto">Assine quando quiser. Cancelamento facil no painel.</p>
            </div>

            <div class="grid lg:grid-cols-2 gap-6 items-stretch">
                <div class="glass rounded-3xl p-8 border border-white/10">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <div class="text-sm text-slate-400">Mensal</div>
                            <div class="text-2xl font-extrabold">Pro</div>
                        </div>
                        <div class="text-right">
                            <div class="text-slate-300 font-bold text-3xl">R$ 19</div>
                            <div class="text-slate-500 text-sm">por mes</div>
                        </div>
                    </div>
                    <div class="mt-6 space-y-2 text-sm text-slate-300">
                        <div>• WhatsApp + painel completo</div>
                        <div>• Orcamentos, metas, recorrencias e lembretes</div>
                        <div>• Cartoes e contas com reconciliacao basica</div>
                        <div>• Relatorios e projecoes</div>
                    </div>
                    <a href="{{ $primaryCtaUrl }}" class="mt-7 inline-flex w-full justify-center px-5 py-3 rounded-2xl bg-white text-space-950 font-extrabold hover:bg-slate-200 transition-all">
                        Comecar no Pro
                    </a>
                    <div class="mt-3 text-xs text-slate-500 text-center">Voce escolhe o plano depois do cadastro.</div>
                </div>

                <div class="glass rounded-3xl p-8 border border-primary/25 shadow-[0_0_35px_rgba(34,197,94,0.15)]">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <div class="text-sm text-primary font-semibold">Mais vantajoso</div>
                            <div class="text-2xl font-extrabold">Pro Anual</div>
                        </div>
                        <div class="text-right">
                            <div class="text-slate-300 font-bold text-3xl">R$ 190</div>
                            <div class="text-slate-500 text-sm">por ano</div>
                        </div>
                    </div>
                    <div class="mt-6 space-y-2 text-sm text-slate-300">
                        <div>• Tudo do Pro</div>
                        <div>• Melhor custo-beneficio</div>
                        <div>• Ideal para manter constancia</div>
                    </div>
                    <a href="{{ $primaryCtaUrl }}" class="mt-7 inline-flex w-full justify-center px-5 py-3 rounded-2xl bg-primary text-space-950 font-extrabold hover:brightness-110 transition-all">
                        Assinar anual
                    </a>
                    <div class="mt-3 text-xs text-slate-500 text-center">Cancelamento quando quiser.</div>
                </div>
            </div>
        </section>

        <section id="faq" class="relative z-10 py-20 bg-slate-900/30">
            <div class="max-w-5xl mx-auto px-6">
                <div class="text-center mb-10">
                    <h2 class="text-3xl lg:text-4xl font-bold mb-3">Perguntas frequentes</h2>
                    <p class="text-slate-400">Respostas curtas e diretas.</p>
                </div>

                <div class="space-y-4">
                    <details class="glass rounded-2xl p-5 group">
                        <summary class="cursor-pointer font-bold list-none flex items-center justify-between">
                            Posso usar so pelo WhatsApp?
                            <span class="text-slate-500 group-open:rotate-45 transition-transform">+</span>
                        </summary>
                        <div class="pt-3 text-slate-300 text-sm">Sim. O painel existe para visao e ajustes finos, mas o fluxo principal funciona pelo chat.</div>
                    </details>
                    <details class="glass rounded-2xl p-5 group">
                        <summary class="cursor-pointer font-bold list-none flex items-center justify-between">
                            O que acontece se eu nao falar a conta/cartao?
                            <span class="text-slate-500 group-open:rotate-45 transition-transform">+</span>
                        </summary>
                        <div class="pt-3 text-slate-300 text-sm">Por padrao, o gasto vai para o saldo geral. Se voce citar um cartao/conta, o sistema registra na fonte correta.</div>
                    </details>
                    <details class="glass rounded-2xl p-5 group">
                        <summary class="cursor-pointer font-bold list-none flex items-center justify-between">
                            Tem lembrete recorrente e lembrete unico?
                            <span class="text-slate-500 group-open:rotate-45 transition-transform">+</span>
                        </summary>
                        <div class="pt-3 text-slate-300 text-sm">Tem. Voce pode criar lembretes diarios, semanais, mensais, anuais, ou para uma data especifica.</div>
                    </details>
                    <details class="glass rounded-2xl p-5 group">
                        <summary class="cursor-pointer font-bold list-none flex items-center justify-between">
                            Posso cancelar a assinatura?
                            <span class="text-slate-500 group-open:rotate-45 transition-transform">+</span>
                        </summary>
                        <div class="pt-3 text-slate-300 text-sm">Sim. Voce consegue cancelar pelo painel quando quiser.</div>
                    </details>
                </div>

                <div class="mt-10 text-center">
                    <a href="{{ $primaryCtaUrl }}" class="inline-flex px-7 py-4 rounded-2xl bg-white text-space-950 font-extrabold hover:bg-slate-200 transition-all shadow-xl">
                        Comecar agora
                    </a>
                </div>
            </div>
        </section>

        @php
            $sliderItems = [
                [
                    'title' => 'Registro no momento',
                    'subtitle' => 'WhatsApp',
                    'quote' => 'Eu mando uma frase simples e ja fica tudo organizado no painel. Sem abrir planilha, sem perder tempo.',
                    'badge' => 'Gasto/Receita',
                    'accent' => 'emerald',
                ],
                [
                    'title' => 'Orcamentos com alerta',
                    'subtitle' => 'Controle',
                    'quote' => 'Quando uma categoria aperta, eu recebo um aviso natural. Ajuda muito a manter consistencia durante o mes.',
                    'badge' => 'Orcamento',
                    'accent' => 'amber',
                ],
                [
                    'title' => 'Cartoes e fontes',
                    'subtitle' => 'Saldo x limite',
                    'quote' => 'Se eu digo que foi no cartao, entra no cartao. Se nao digo, vai pro saldo. Fica previsivel e sem conflito.',
                    'badge' => 'Cartoes',
                    'accent' => 'sky',
                ],
                [
                    'title' => 'Lembretes que ajudam',
                    'subtitle' => 'Rotina',
                    'quote' => 'Eu crio lembretes para pagar algo ou dar parabens. E chega no horario certo, sem ser invasivo.',
                    'badge' => 'Lembretes',
                    'accent' => 'violet',
                ],
            ];

            $tutorialContactNumber = config('whatsapp.tutorial.contact_number');
            $tutorialContactDigits = preg_replace('/\\D+/', '', (string) $tutorialContactNumber);
            $tutorialPrefilledMessage = (string) config('whatsapp.tutorial.prefilled_message', 'Oi! Quero falar com o suporte do InovaFinance.');
            $supportWhatsappUrl = $tutorialContactDigits
                ? 'https://wa.me/'.$tutorialContactDigits.'?text='.urlencode($tutorialPrefilledMessage)
                : null;
            $supportWhatsappLabel = (string) config('whatsapp.tutorial.contact_label', 'WhatsApp oficial do InovaFinance');
            $supportEmail = (string) (config('mail.from.address') ?: 'contato@inovaforce.com.br');
        @endphp

        <section id="depoimentos" class="relative z-10 py-24 px-6">
            <div class="mx-auto max-w-7xl">
                <div class="text-center">
                    <span class="inline-flex items-center gap-2 rounded-full border border-primary/20 bg-primary/10 px-4 py-2 text-xs font-semibold uppercase tracking-[0.24em] text-emerald-200">
                        Em minutos
                    </span>
                    <h2 class="mt-6 text-3xl font-black tracking-tight text-white sm:text-4xl">
                        Um assistente financeiro que
                        <span class="text-transparent bg-clip-text bg-gradient-to-r from-primary to-cyan-400">fala a sua lingua</span>
                    </h2>
                    <p class="mt-4 text-slate-300">Um carrossel com cenarios comuns de uso. Curto, direto e sem promessas vazias.</p>
                </div>

                <div id="if-slider" class="relative mt-14 overflow-hidden rounded-[2.5rem] border border-white/10 bg-slate-950/60 shadow-[0_32px_120px_rgba(2,6,23,0.70)] backdrop-blur">
                    <div class="absolute inset-0 bg-[radial-gradient(circle_at_top,_rgba(16,185,129,0.16),_transparent_38%),radial-gradient(circle_at_bottom_right,_rgba(56,189,248,0.14),_transparent_42%)]"></div>

                    <div class="relative p-6 sm:p-10">
                        <div class="flex flex-wrap items-center justify-center gap-3">
                            @foreach($sliderItems as $index => $item)
                                <button
                                    type="button"
                                    class="if-avatar group inline-flex h-12 w-12 items-center justify-center rounded-full border border-white/10 bg-white/5 text-sm font-extrabold text-white shadow-[0_18px_50px_rgba(2,6,23,0.55)] transition hover:bg-white/10 focus:outline-none focus:ring-2 focus:ring-primary/60"
                                    data-index="{{ $index }}"
                                    aria-label="Abrir item {{ $index + 1 }}"
                                >
                                    <span class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-slate-900/70">
                                        {{ mb_substr($item['badge'], 0, 1) }}
                                    </span>
                                </button>
                            @endforeach
                        </div>

                        <div class="mt-10">
                            @foreach($sliderItems as $index => $item)
                                <article class="if-slide {{ $index === 0 ? '' : 'hidden' }} text-center" data-index="{{ $index }}">
                                    <div class="mx-auto max-w-3xl">
                                        <div class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-4 py-2 text-xs font-semibold uppercase tracking-[0.24em] text-slate-200">
                                            <span class="h-2 w-2 rounded-full bg-primary"></span>
                                            {{ $item['badge'] }}
                                        </div>

                                        <h3 class="mt-7 text-3xl font-black tracking-tight text-white sm:text-4xl">
                                            {{ $item['title'] }}
                                        </h3>
                                        <p class="mt-2 text-sm font-semibold uppercase tracking-[0.24em] text-slate-400">{{ $item['subtitle'] }}</p>

                                        <p class="mt-7 text-lg leading-9 text-slate-200 sm:text-xl">
                                            "{{ $item['quote'] }}"
                                        </p>

                                        <div class="mt-8 flex items-center justify-center gap-2" aria-label="Avaliacao 5 de 5">
                                            @for($s = 0; $s < 5; $s++)
                                                <svg class="h-6 w-6 text-amber-300" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.955a1 1 0 00.95.69h4.157c.969 0 1.371 1.24.588 1.81l-3.363 2.445a1 1 0 00-.364 1.118l1.287 3.955c.3.921-.755 1.688-1.54 1.118l-3.363-2.445a1 1 0 00-1.175 0l-3.363 2.445c-.784.57-1.838-.197-1.539-1.118l1.286-3.955a1 1 0 00-.364-1.118L2.07 9.382c-.783-.57-.38-1.81.588-1.81h4.157a1 1 0 00.95-.69l1.286-3.955z" />
                                                </svg>
                                            @endfor
                                        </div>
                                    </div>
                                </article>
                            @endforeach
                        </div>

                        <div class="mt-10 flex items-center justify-center gap-4">
                            <button type="button" class="if-prev inline-flex h-11 w-11 items-center justify-center rounded-full border border-white/10 bg-white/5 text-slate-200 transition hover:bg-white/10 focus:outline-none focus:ring-2 focus:ring-primary/60" aria-label="Anterior">
                                <span aria-hidden="true">‹</span>
                            </button>
                            <div class="if-dots flex items-center gap-2" aria-label="Paginacao do carrossel">
                                @foreach($sliderItems as $index => $item)
                                    <button type="button" class="if-dot h-2.5 w-2.5 rounded-full bg-white/15 transition focus:outline-none focus:ring-2 focus:ring-primary/60" data-index="{{ $index }}" aria-label="Ir para {{ $index + 1 }}"></button>
                                @endforeach
                            </div>
                            <button type="button" class="if-next inline-flex h-11 w-11 items-center justify-center rounded-full border border-white/10 bg-white/5 text-slate-200 transition hover:bg-white/10 focus:outline-none focus:ring-2 focus:ring-primary/60" aria-label="Proximo">
                                <span aria-hidden="true">›</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <footer class="relative z-10 border-t border-white/10 bg-slate-950/70">
            <div class="absolute inset-0 bg-[radial-gradient(circle_at_top_left,_rgba(56,189,248,0.12),_transparent_30%),radial-gradient(circle_at_bottom_right,_rgba(16,185,129,0.14),_transparent_36%)]"></div>

            <div class="relative mx-auto max-w-7xl px-6 py-16">
                <div class="grid gap-12 lg:grid-cols-[1.15fr_1fr_1fr_1.1fr]">
                    <div class="space-y-5">
                        <div class="flex items-center gap-3">
                            <div class="inline-flex h-11 w-11 items-center justify-center rounded-2xl border border-primary/20 bg-primary/10">
                                <img src="{{ asset('logo.png') }}" alt="InovaFinance" class="h-6 w-6 object-contain" />
                            </div>
                            <div>
                                <div class="text-lg font-black text-white">InovaFinance</div>
                                <div class="text-sm text-slate-400">Seu amigo financeiro no WhatsApp.</div>
                            </div>
                        </div>

                        <p class="max-w-sm text-sm leading-7 text-slate-400">
                            Registre gastos, receitas e lembretes por conversa. A IA ajuda quando precisa, e o resto fica deterministico e confiavel.
                        </p>

                        <div class="flex flex-wrap gap-3 text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">
                            <span class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-4 py-2">
                                <span class="h-2 w-2 rounded-full bg-primary"></span>
                                IA no WhatsApp
                            </span>
                            <span class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-4 py-2">
                                <span class="h-2 w-2 rounded-full bg-sky-300"></span>
                                Cartoes e contas
                            </span>
                            <span class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-4 py-2">
                                <span class="h-2 w-2 rounded-full bg-amber-300"></span>
                                Alertas
                            </span>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <div class="text-sm font-bold uppercase tracking-[0.22em] text-slate-300">Produto</div>
                        <ul class="space-y-3 text-sm text-slate-400">
                            <li><a class="hover:text-white" href="#produto">WhatsApp + Dashboard</a></li>
                            <li><a class="hover:text-white" href="#recursos">Orcamentos, metas e alertas</a></li>
                            <li><a class="hover:text-white" href="#como-funciona">Como funciona</a></li>
                            <li><a class="hover:text-white" href="#planos">Planos</a></li>
                        </ul>
                    </div>

                    <div class="space-y-4">
                        <div class="text-sm font-bold uppercase tracking-[0.22em] text-slate-300">Conta</div>
                        <ul class="space-y-3 text-sm text-slate-400">
                            <li><a class="hover:text-white" href="{{ Route::has('login') ? route('login') : '#' }}">Login</a></li>
                            <li><a class="hover:text-white" href="{{ Route::has('register') ? route('register') : '#' }}">Criar conta</a></li>
                            <li><a class="hover:text-white" href="{{ Route::has('billing.plans') ? route('billing.plans') : '#' }}">Assinar plano</a></li>
                            <li><a class="hover:text-white" href="{{ route('terms-of-use') }}">Termos de uso</a></li>
                            <li><a class="hover:text-white" href="{{ route('privacy-policy') }}">Politica de privacidade</a></li>
                        </ul>
                    </div>

                    <div class="rounded-3xl border border-white/10 bg-white/5 p-7 shadow-[0_24px_80px_rgba(2,6,23,0.55)]">
                        <div class="text-xs font-semibold uppercase tracking-[0.24em] text-primary">Suporte humano</div>
                        <div class="mt-3 text-xl font-black text-white">Fale com a nossa equipe</div>
                        <p class="mt-3 text-sm leading-7 text-slate-300">
                            Precisa de ajuda para escolher um plano ou configurar o WhatsApp? A gente te atende por mensagem.
                        </p>

                        @if($supportWhatsappUrl)
                            <a href="{{ $supportWhatsappUrl }}" class="mt-6 inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-primary px-5 py-3 font-extrabold text-space-950 transition hover:brightness-110">
                                <span aria-hidden="true">WhatsApp</span>
                                <span class="text-sm font-black">Chamar no WhatsApp</span>
                            </a>
                            <div class="mt-3 text-xs text-slate-400">{{ $supportWhatsappLabel }}</div>
                        @endif

                        <div class="mt-4 text-sm text-slate-300">
                            <span class="text-slate-500">Email:</span> <a class="font-semibold hover:text-white" href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>
                        </div>
                    </div>
                </div>

                <div class="mt-14 flex flex-col gap-4 border-t border-white/10 pt-8 sm:flex-row sm:items-center sm:justify-between">
                    <div class="text-sm text-slate-500">
                        &copy; 2026 InovaForce IT. Todos os direitos reservados.
                    </div>
                    <div class="flex items-center gap-3">
                        <a href="#" class="inline-flex h-11 w-11 items-center justify-center rounded-2xl border border-white/10 bg-white/5 text-slate-300 transition hover:bg-white/10 hover:text-white" aria-label="Instagram">
                            <span aria-hidden="true">IG</span>
                        </a>
                        <a href="#" class="inline-flex h-11 w-11 items-center justify-center rounded-2xl border border-white/10 bg-white/5 text-slate-300 transition hover:bg-white/10 hover:text-white" aria-label="Facebook">
                            <span aria-hidden="true">FB</span>
                        </a>
                    </div>
                </div>
            </div>
        </footer>

        <script>
            (function () {
                const root = document.getElementById('if-slider');
                if (!root) return;

                const slides = Array.from(root.querySelectorAll('.if-slide'));
                const avatars = Array.from(root.querySelectorAll('.if-avatar'));
                const dots = Array.from(root.querySelectorAll('.if-dot'));
                const prev = root.querySelector('.if-prev');
                const next = root.querySelector('.if-next');
                const max = slides.length;

                let index = 0;
                let timer = null;

                function setActive(nextIndex) {
                    index = (nextIndex + max) % max;

                    slides.forEach((el) => {
                        const isActive = Number(el.dataset.index) === index;
                        el.classList.toggle('hidden', !isActive);
                    });

                    avatars.forEach((el) => {
                        const isActive = Number(el.dataset.index) === index;
                        el.classList.toggle('ring-2', isActive);
                        el.classList.toggle('ring-primary/70', isActive);
                    });

                    dots.forEach((el) => {
                        const isActive = Number(el.dataset.index) === index;
                        el.classList.toggle('bg-white/15', !isActive);
                        el.classList.toggle('bg-primary', isActive);
                        el.classList.toggle('w-2.5', !isActive);
                        el.classList.toggle('w-7', isActive);
                    });
                }

                function stop() {
                    if (timer) {
                        window.clearInterval(timer);
                        timer = null;
                    }
                }

                function start() {
                    stop();
                    timer = window.setInterval(() => setActive(index + 1), 6500);
                }

                avatars.forEach((btn) => btn.addEventListener('click', () => setActive(Number(btn.dataset.index))));
                dots.forEach((btn) => btn.addEventListener('click', () => setActive(Number(btn.dataset.index))));
                if (prev) prev.addEventListener('click', () => setActive(index - 1));
                if (next) next.addEventListener('click', () => setActive(index + 1));

                root.addEventListener('mouseenter', stop);
                root.addEventListener('mouseleave', start);
                root.addEventListener('focusin', stop);
                root.addEventListener('focusout', start);

                setActive(0);
                start();
            })();
        </script>
    </body>
</html>
