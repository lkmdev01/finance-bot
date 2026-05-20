@php
    $seoSiteName = config('seo.site_name', config('app.name', 'InovaFinance'));
    $seoDefaultTitle = config('seo.default_title', $seoSiteName);
    $seoSeparator = config('seo.title_separator', ' | ');
    $seoTitle = isset($title) && filled($title)
        ? (str_contains($title, $seoSiteName) ? $title : $title . $seoSeparator . $seoSiteName)
        : $seoDefaultTitle;
    $seoDescription = $description ?? config('seo.default_description', '');
    $seoKeywords = implode(', ', $keywords ?? config('seo.default_keywords', []));
    $seoImagePath = $image ?? config('seo.default_image', 'mockup.png');
    $seoImage = str_starts_with($seoImagePath, 'http') ? $seoImagePath : asset($seoImagePath);
    $seoUrl = $canonical ?? url()->current();
    $seoRobots = $robots ?? 'noindex, nofollow';
    $seoTwitterSite = config('seo.twitter_site');
    $seoStructuredData = $structuredData ?? [];
    $faviconVersion = file_exists(public_path('favicon.ico')) ? filemtime(public_path('favicon.ico')) : time();
@endphp

<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>{{ $seoTitle }}</title>
<meta name="description" content="{{ $seoDescription }}" />
<meta name="keywords" content="{{ $seoKeywords }}" />
<meta name="robots" content="{{ $seoRobots }}" />
<meta name="googlebot" content="{{ $seoRobots }}" />
<meta name="author" content="{{ $seoSiteName }}" />
<meta name="theme-color" content="{{ config('seo.theme_color', '#070b14') }}" />
<link rel="canonical" href="{{ $seoUrl }}" />

<meta property="og:type" content="website" />
<meta property="og:site_name" content="{{ $seoSiteName }}" />
<meta property="og:locale" content="{{ config('seo.locale', 'pt_BR') }}" />
<meta property="og:title" content="{{ $seoTitle }}" />
<meta property="og:description" content="{{ $seoDescription }}" />
<meta property="og:image" content="{{ $seoImage }}" />
<meta property="og:image:alt" content="{{ $seoTitle }}" />
<meta property="og:url" content="{{ $seoUrl }}" />

<meta name="twitter:card" content="summary_large_image" />
<meta name="twitter:title" content="{{ $seoTitle }}" />
<meta name="twitter:description" content="{{ $seoDescription }}" />
<meta name="twitter:image" content="{{ $seoImage }}" />
<meta name="twitter:image:alt" content="{{ $seoTitle }}" />
@if(filled($seoTwitterSite))
<meta name="twitter:site" content="{{ $seoTwitterSite }}" />
@endif

<link rel="icon" href="/favicon.ico?v={{ $faviconVersion }}" sizes="any">
<link rel="shortcut icon" href="/favicon.ico?v={{ $faviconVersion }}">
<link rel="icon" href="/favicon-512.png?v={{ $faviconVersion }}" type="image/png">
<link rel="apple-touch-icon" href="/apple-touch-icon.png?v={{ $faviconVersion }}">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>
    html {
        background: #070b14;
        color-scheme: dark;
    }

    body {
        margin: 0;
        background: #070b14;
        color: #f1f5f9;
    }

    [data-sidebar-logo] {
        display: inline-flex;
        align-items: center;
        gap: 0.75rem;
        min-width: 0;
        color: #f8fafc;
        text-decoration: none;
    }

    [data-sidebar-logo] > :first-child {
        display: inline-flex;
        width: 2.5rem;
        height: 2.5rem;
        flex: 0 0 2.5rem;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        border-radius: 0.75rem;
        background: #6366f1;
        box-shadow: 0 0 15px rgba(99, 102, 241, 0.35);
    }

    [data-sidebar-logo] img {
        display: block;
        width: 1.25rem;
        height: 1.25rem;
        object-fit: contain;
    }

    [data-sidebar-logo] > :last-child {
        display: grid;
        overflow: hidden;
    }

    [data-sidebar-logo] > :last-child > * {
        margin: 0;
        color: #fff;
        font-family: "Outfit", ui-sans-serif, system-ui, sans-serif;
        font-size: 1.125rem;
        font-weight: 700;
        line-height: 1.1;
        text-decoration: none;
    }

    [data-flux-sidebar-header] {
        min-height: 5rem;
        padding: 1.5rem 1rem;
        box-sizing: border-box;
    }
</style>

@foreach($seoStructuredData as $schema)
<script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}</script>
@endforeach

@vite(['resources/css/app.css', 'resources/js/app.js'])
<script>
    if (localStorage.getItem('flux.appearance') !== 'dark') {
        localStorage.setItem('flux.appearance', 'dark');
    }
    document.documentElement.classList.add('dark');
    document.documentElement.setAttribute('data-appearance', 'dark');
</script>
@fluxAppearance

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
