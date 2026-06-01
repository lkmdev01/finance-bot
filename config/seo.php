<?php

return [
    'site_name' => env('SEO_SITE_NAME', 'InovaFinance'),
    'default_title' => env('SEO_DEFAULT_TITLE', 'InovaFinance | Gestao financeira via WhatsApp com IA'),
    'title_separator' => env('SEO_TITLE_SEPARATOR', ' | '),
    'default_description' => env('SEO_DEFAULT_DESCRIPTION', 'Controle receitas, despesas, metas e relatorios financeiros pelo WhatsApp com apoio de IA.'),
    'default_keywords' => [
        'gestao financeira',
        'controle financeiro',
        'financas pessoais',
        'whatsapp',
        'inteligencia artificial',
        'orcamento',
        'controle de gastos',
        'inovafinance',
    ],
    'default_image' => env('SEO_DEFAULT_IMAGE', 'social-card.png'),
    'default_image_width' => (int) env('SEO_DEFAULT_IMAGE_WIDTH', 1200),
    'default_image_height' => (int) env('SEO_DEFAULT_IMAGE_HEIGHT', 630),
    'theme_color' => env('SEO_THEME_COLOR', '#070b14'),
    'locale' => env('SEO_LOCALE', 'pt_BR'),
    'twitter_site' => env('SEO_TWITTER_SITE'),

    // Default robots for pages that don't override it explicitly.
    // In non-production environments, default to noindex.
    'robots' => env('SEO_ROBOTS'),
];

