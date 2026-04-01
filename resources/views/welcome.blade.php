<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        @php
            $seoTitle = 'InovaFinance | Gestão financeira via WhatsApp com IA';
            $seoDescription = 'Controle gastos, receitas, orçamentos, metas e relatórios financeiros pelo WhatsApp com apoio de IA.';
            $seoImage = asset('social-card.png');
            $seoUrl = route('home');
            $seoKeywords = 'gestão financeira, controle financeiro, finanças pessoais, whatsapp, inteligência artificial, orçamento, controle de gastos';
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
                            primary: '#6366f1',
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
                background: radial-gradient(circle at 50% 50%, rgba(99, 102, 241, 0.12) 0%, rgba(7, 11, 20, 0) 70%);
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
        {{-- Navbar --}}
        <nav class="relative z-50 px-6 py-6 max-w-7xl mx-auto flex items-center justify-between">
            <div class="flex items-center gap-2 group cursor-pointer">
                <div class="w-10 h-10 bg-primary rounded-xl flex items-center justify-center shadow-[0_0_20px_rgba(99,102,241,0.5)] transition-transform group-hover:scale-110 p-2">
                    <img src="{{ asset('logo.png') }}" alt="InovaFinance" class="h-full w-full object-contain" />
                </div>
                <span class="text-xl font-bold tracking-tight">InovaFinance</span>
            </div>

            <div class="hidden md:flex items-center gap-8 text-sm font-medium text-slate-400">
                <a href="#features" class="hover:text-white transition-colors">Funcionalidades</a>
                <a href="#how-it-works" class="hover:text-white transition-colors">Como Funciona</a>
                <a href="#" class="hover:text-white transition-colors">Sobre</a>
            </div>

            <div class="flex items-center gap-4">
                @if (Route::has('login'))
                    @auth
                        <a href="{{ url('/dashboard') }}" class="px-5 py-2.5 bg-primary hover:bg-indigo-500 text-white rounded-xl font-semibold transition-all shadow-lg hover:shadow-primary/30">
                            Dashboard
                        </a>
                    @else
                        <a href="{{ route('login') }}" class="px-5 py-2.5 text-sm font-medium hover:text-white transition-colors">Log in</a>
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="px-5 py-2.5 bg-white text-space-950 rounded-xl font-semibold hover:bg-slate-200 transition-all shadow-xl">
                                Começar Grátis
                            </a>
                        @endif
                    @endauth
                @endif
            </div>
        </nav>

        {{-- Hero Section --}}
        <section class="relative z-10 pt-10 pb-20 px-6 max-w-7xl mx-auto text-center lg:text-left grid lg:grid-cols-2 gap-12 items-center">
            {{-- Gradientes Locais para o Hero (não seguem o scroll) --}}
            <div class="absolute -top-[20%] -left-[20%] w-[80%] h-[80%] blur-gradient -z-10 opacity-60"></div>
            
            <div>
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-primary/10 border border-primary/20 text-primary text-xs font-bold uppercase tracking-widest mb-6">
                    <span class="relative flex h-2 w-2">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-primary opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-primary"></span>
                    </span>
                    Inteligência Artificial + WhatsApp
                </div>
                <h1 class="text-5xl lg:text-7xl font-bold leading-[1.1] mb-6">
                    Sua Gestão Financeira <br>
                    <span class="text-transparent bg-clip-text bg-gradient-to-r from-primary to-cyan-400">Decolando.</span>
                </h1>
                <p class="text-lg text-slate-400 mb-10 max-w-xl mx-auto lg:mx-0">
                    Esqueça planilhas complicadas. Registre seus gastos e ganhos enviando mensagens no WhatsApp. Nossa IA categoriza tudo para você instantaneamente.
                </p>
                <div class="flex flex-col sm:flex-row items-center gap-4 justify-center lg:justify-start">
                    <a href="{{ route('register') }}" class="w-full sm:w-auto px-8 py-4 bg-primary text-white text-lg rounded-2xl font-bold hover:bg-indigo-500 transition-all shadow-2xl hover:shadow-primary/40 flex items-center justify-center gap-2">
                        Criar Minha Conta 🚀
                    </a>
                    <a href="#how-it-works" class="w-full sm:w-auto px-8 py-4 glass text-white text-lg rounded-2xl font-bold hover:bg-slate-800/80 transition-all">
                        Como funciona?
                    </a>
                </div>
                <div class="mt-8 flex items-center gap-4 justify-center lg:justify-start text-sm text-slate-500">
                    <div class="flex -space-x-2">
                        <img class="w-8 h-8 rounded-full border-2 border-space-950" src="https://ui-avatars.com/api/?name=User+1&background=6366f1&color=fff" alt="">
                        <img class="w-8 h-8 rounded-full border-2 border-space-950" src="https://ui-avatars.com/api/?name=User+2&background=0284c7&color=fff" alt="">
                        <img class="w-8 h-8 rounded-full border-2 border-space-950" src="https://ui-avatars.com/api/?name=User+3&background=a855f7&color=fff" alt="">
                    </div>
                    <span>+2.000 usuários decolando</span>
                </div>
            </div>
            <div class="relative rocket-float">
                <img src="/hero.png" alt="InovaFinance Mockup" class="w-full max-w-[600px] mx-auto scale-110 lg:scale-125 translate-x-4 mix-screen">
                {{-- Decorative blobs --}}
                <div class="absolute -top-10 -right-10 w-40 h-40 bg-primary/10 rounded-full blur-3xl"></div>
                <div class="absolute -bottom-10 -left-10 w-40 h-40 bg-cyan-400/10 rounded-full blur-3xl"></div>
            </div>
        </section>

        {{-- Features Section --}}
        <section id="features" class="relative z-10 py-24 px-6 max-w-7xl mx-auto">
            <div class="text-center mb-16">
                <h2 class="text-3xl lg:text-4xl font-bold mb-4">Por que escolher o InovaFinance?</h2>
                <p class="text-slate-400 max-w-2xl mx-auto">Tecnologia de ponta para quem não tem tempo a perder com burocracia financeira.</p>
            </div>

            <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-6">
                {{-- Card 1 --}}
                <div class="glass p-8 rounded-3xl hover:border-primary/50 transition-all group">
                    <div class="w-12 h-12 bg-green-500/20 rounded-2xl flex items-center justify-center text-2xl mb-6 group-hover:scale-110 transition-transform">💬</div>
                    <h3 class="text-xl font-bold mb-3">WhatsApp Inteligente</h3>
                    <p class="text-slate-400 text-sm leading-relaxed">
                        Envie texto, áudio ou documentos como CSV e OFX. O sistema transforma isso em lançamentos sem burocracia.
                    </p>
                </div>
                {{-- Card 2 --}}
                <div class="glass p-8 rounded-3xl hover:border-primary/50 transition-all group border-primary/30">
                    <div class="w-12 h-12 bg-primary/20 rounded-2xl flex items-center justify-center text-2xl mb-6 group-hover:scale-110 transition-transform">🧠</div>
                    <h3 class="text-xl font-bold mb-3">IA Categorizadora</h3>
                    <p class="text-slate-400 text-sm leading-relaxed">
                        Nossa IA identifica o gasto, extrai o valor e organiza por categorias sozinho.
                    </p>
                </div>
                {{-- Card 3 --}}
                <div class="glass p-8 rounded-3xl hover:border-primary/50 transition-all group">
                    <div class="w-12 h-12 bg-violet-400/20 rounded-2xl flex items-center justify-center text-2xl mb-6 group-hover:scale-110 transition-transform">🏦</div>
                    <h3 class="text-xl font-bold mb-3">Contas e Cartões</h3>
                    <p class="text-slate-400 text-sm leading-relaxed">
                        Organize contas bancárias e cartões de crédito em um só lugar, com saldo e uso acompanhados no painel.
                    </p>
                </div>
                {{-- Card 4 --}}
                <div class="glass p-8 rounded-3xl hover:border-primary/50 transition-all group">
                    <div class="w-12 h-12 bg-amber-500/20 rounded-2xl flex items-center justify-center text-2xl mb-6 group-hover:scale-110 transition-transform">🔁</div>
                    <h3 class="text-xl font-bold mb-3">Recorrências e Assinaturas</h3>
                    <p class="text-slate-400 text-sm leading-relaxed">
                        Controle despesas recorrentes, vencimentos e assinaturas com registro automático e visão clara do que volta todo mês.
                    </p>
                </div>
                {{-- Card 5 --}}
                <div class="glass p-8 rounded-3xl hover:border-primary/50 transition-all group">
                    <div class="w-12 h-12 bg-cyan-400/20 rounded-2xl flex items-center justify-center text-2xl mb-6 group-hover:scale-110 transition-transform">📊</div>
                    <h3 class="text-xl font-bold mb-3">Relatórios e Projeções</h3>
                    <p class="text-slate-400 text-sm leading-relaxed">
                        Visualize gráficos, tendências e projeções financeiras para entender para onde vai seu dinheiro e o que vem pela frente.
                    </p>
                </div>
                {{-- Card 6 --}}
                <div class="glass p-8 rounded-3xl hover:border-primary/50 transition-all group">
                    <div class="w-12 h-12 bg-amber-500/20 rounded-2xl flex items-center justify-center text-2xl mb-6 group-hover:scale-110 transition-transform">🎯</div>
                    <h3 class="text-xl font-bold mb-3">Metas de Economia</h3>
                    <p class="text-slate-400 text-sm leading-relaxed">
                        Planeje seu próximo foguete. Defina metas e acompanhe sua evolução mensal.
                    </p>
                </div>
                {{-- Card 7 --}}
                <div class="glass p-8 rounded-3xl hover:border-primary/50 transition-all group">
                    <div class="w-12 h-12 bg-rose-400/20 rounded-2xl flex items-center justify-center text-2xl mb-6 group-hover:scale-110 transition-transform">🚨</div>
                    <h3 class="text-xl font-bold mb-3">Orçamentos e Alertas</h3>
                    <p class="text-slate-400 text-sm leading-relaxed">
                        Defina limites por categoria, receba avisos no painel e acompanhe antes que o orçamento estoure.
                    </p>
                </div>
                {{-- Card 8 --}}
                <div class="glass p-8 rounded-3xl hover:border-primary/50 transition-all group">
                    <div class="w-12 h-12 bg-indigo-400/20 rounded-2xl flex items-center justify-center text-2xl mb-6 group-hover:scale-110 transition-transform">🚀</div>
                    <h3 class="text-xl font-bold mb-3">Orbita</h3>
                    <p class="text-slate-400 text-sm leading-relaxed">
                        Acompanhe sua evolução com pontuação, foco sugerido e recompensas visuais para manter constância financeira.
                    </p>
                </div>
            </div>
        </section>

        {{-- Steps Section --}}
        <section id="how-it-works" class="relative z-10 py-24 bg-slate-900/30">
            <div class="max-w-7xl mx-auto px-6">
                <div class="grid lg:grid-cols-2 gap-16 items-center">
                    <div>
                        <h2 class="text-4xl font-bold mb-8">O caminho mais rápido <br> para sua liberdade.</h2>
                        <div class="space-y-8">
                            <div class="flex gap-6">
                                <div class="w-10 h-10 rounded-full bg-primary flex items-center justify-center font-bold shrink-0">1</div>
                                <div>
                                    <h4 class="text-xl font-bold mb-2">Crie sua conta e conecte o WhatsApp</h4>
                                    <p class="text-slate-400">Em poucos minutos você já acessa o painel e pode começar a registrar sua vida financeira pelo chat.</p>
                                </div>
                            </div>
                            <div class="flex gap-6">
                                <div class="w-10 h-10 rounded-full bg-primary flex items-center justify-center font-bold shrink-0">2</div>
                                <div>
                                    <h4 class="text-xl font-bold mb-2">Envie texto, áudio ou documento</h4>
                                    <p class="text-slate-400">Mande uma mensagem como “Gastei 50 no posto”, grave um áudio ou envie CSV e OFX para importar movimentações.</p>
                                </div>
                            </div>
                            <div class="flex gap-6">
                                <div class="w-10 h-10 rounded-full bg-primary flex items-center justify-center font-bold shrink-0">3</div>
                                <div>
                                    <h4 class="text-xl font-bold mb-2">Acompanhe, planeje e evolua</h4>
                                    <p class="text-slate-400">Veja relatórios, projeções, metas, recorrências, alertas e os avanços do Orbita em um só lugar.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="glass p-4 rounded-[40px] shadow-2xl skew-x-1 lg:-rotate-3 hover:rotate-0 transition-transform duration-700 overflow-hidden">
                        <div class="bg-slate-800 p-6 rounded-[30px] min-h-[400px]">
                            <div class="flex items-center gap-3 mb-8">
                                <div class="w-3 h-3 rounded-full bg-red-400"></div>
                                <div class="w-3 h-3 rounded-full bg-amber-400"></div>
                                <div class="w-3 h-3 rounded-full bg-green-400"></div>
                            </div>
                            <div class="space-y-4">
                                <div class="flex justify-end">
                                    <div class="bg-primary/20 border border-primary/30 p-3 rounded-2xl rounded-tr-none max-w-[80%] text-sm">
                                        Enviei um OFX do banco e também gravei um áudio com meu gasto no posto ⛽
                                    </div>
                                </div>
                                <div class="flex justify-start">
                                    <div class="glass p-3 rounded-2xl rounded-tl-none max-w-[80%] text-sm flex gap-3 italic">
                                        <span>🚀</span>
                                        Importação concluída! <br> Movimentações organizadas, gasto categorizado e painel atualizado.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        {{-- Footer --}}
        <footer class="relative z-10 py-12 px-6 border-t border-slate-800">
            <div class="max-w-7xl mx-auto flex flex-col md:flex-row justify-between items-center gap-8">
                <div class="flex items-center gap-2">
                    <img src="{{ asset('logo.png') }}" alt="InovaFinance" class="h-8 w-8 object-contain" />
                    <span class="text-xl font-bold">InovaFinance</span>
                </div>
                <div class="flex gap-8 text-sm text-slate-500">
                    <a href="#" class="hover:text-white">Privacidade</a>
                    <a href="#" class="hover:text-white">Termos</a>
                    <a href="#" class="hover:text-white">API</a>
                </div>
                <div class="text-sm text-slate-500">
                    &copy; 2026 InovaForce IT. Todos os direitos reservados.
                </div>
            </div>
        </footer>
    </body>
</html>

