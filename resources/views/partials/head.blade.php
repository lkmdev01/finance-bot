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

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon-512.png" type="image/png">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">

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
