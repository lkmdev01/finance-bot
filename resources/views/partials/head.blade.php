<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

{{-- SEO --}}
<title>{{ $title ?? 'InovaFinance - Sua Gestão Financeira Decolando' }}</title>
<meta name="description" content="InovaFinance - Gestão financeira inteligente via WhatsApp e IA. Controle seus gastos de forma simples, direta e automatizada." />
<meta name="keywords" content="finanças, gestão financeira, whatsapp bot, inteligencia artificial, inovaforce, controle de gastos" />

{{-- SMO / Open Graph --}}
<meta property="og:type" content="website" />
<meta property="og:title" content="{{ $title ?? 'InovaFinance - Sua Gestão Financeira Decolando' }}" />
<meta property="og:description" content="InovaFinance - Gestão financeira inteligente via WhatsApp e IA. Controle seus gastos de forma simples." />
<meta property="og:image" content="{{ asset('mockup.png') }}" />
<meta property="og:url" content="{{ url()->current() }}" />

{{-- Twitter --}}
<meta name="twitter:card" content="summary_large_image" />
<meta name="twitter:title" content="{{ $title ?? 'InovaFinance - Sua Gestão Financeira Decolando' }}" />
<meta name="twitter:description" content="Controle suas finanças pelo WhatsApp com Inteligência Artificial." />
<meta name="twitter:image" content="{{ asset('mockup.png') }}" />

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
