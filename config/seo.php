<?php

return [
    'site_name' => env('SEO_SITE_NAME', 'InovaFinance'),
    'default_title' => env('SEO_DEFAULT_TITLE', 'InovaFinance | Gestão financeira via WhatsApp com IA'),
    'title_separator' => env('SEO_TITLE_SEPARATOR', ' | '),
    'default_description' => env('SEO_DEFAULT_DESCRIPTION', 'Controle receitas, despesas, metas e relatórios financeiros pelo WhatsApp com apoio de IA.'),
    'default_keywords' => [
        'gestão financeira',
        'controle financeiro',
        'finanças pessoais',
        'whatsapp',
        'inteligência artificial',
        'orçamento',
        'controle de gastos',
        'inovafinance',
    ],
    'default_image' => env('SEO_DEFAULT_IMAGE', 'social-card.png'),
    'theme_color' => env('SEO_THEME_COLOR', '#070b14'),
    'locale' => env('SEO_LOCALE', 'pt_BR'),
    'twitter_site' => env('SEO_TWITTER_SITE'),
];
