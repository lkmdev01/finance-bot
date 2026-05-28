<?php

return [
    'default_plan' => 'starter',
    'trial_days' => 7,
    'trial_expired_message' => 'Seu teste gratuito terminou. Para continuar registrando novas informações, ative um plano.',

    // Metodos exibidos no checkout da AbacatePay.
    // Para MVP, usamos checkout avulso (sem renovacao automatica), entao vale liberar PIX + Cartao.
    'checkout_methods' => ['PIX', 'CARD'],

    'premium_features' => [
        'reports',
        'financial_projections',
        'mascot',
    ],

    'plans' => [
        'starter' => [
            'code' => 'starter',
            'name' => 'Starter',
            'description' => 'Base para consultar histórico, dashboard e continuar acompanhando suas finanças.',
            'price_cents' => 0,
            'frequency' => 'NONE',
            'badge' => 'Teste + leitura',
            'highlight' => false,
            'product_id' => null,
            'features' => [
                'transactions',
                'categories',
                'budgets',
                'savings_goals',
                'bank_accounts',
                'credit_cards',
                'recurring_transactions',
                'subscriptions',
                'whatsapp_basic',
            ],
        ],
        'pro_monthly' => [
            'code' => 'pro_monthly',
            'name' => 'Pro Mensal',
            'description' => 'Relatórios, projeções, Orbita e acesso completo por 30 dias após o pagamento.',
            'price_cents' => 1997,
            'frequency' => 'MONTHLY',
            'badge' => 'Mais popular',
            'highlight' => true,
            'product_id' => env('ABACATEPAY_PLAN_PRO_MONTHLY_PRODUCT_ID'),
            'features' => [
                'transactions',
                'categories',
                'budgets',
                'savings_goals',
                'bank_accounts',
                'credit_cards',
                'recurring_transactions',
                'subscriptions',
                'whatsapp_basic',
                'reports',
                'financial_projections',
                'mascot',
            ],
        ],
        'pro_yearly' => [
            'code' => 'pro_yearly',
            'name' => 'Pro Anual',
            'description' => 'Tudo do Pro com acesso por 12 meses após o pagamento e melhor custo.',
            'price_cents' => 19970,
            'frequency' => 'YEARLY',
            'badge' => 'Economize 2 meses',
            'highlight' => false,
            'product_id' => env('ABACATEPAY_PLAN_PRO_YEARLY_PRODUCT_ID'),
            'features' => [
                'transactions',
                'categories',
                'budgets',
                'savings_goals',
                'bank_accounts',
                'credit_cards',
                'recurring_transactions',
                'subscriptions',
                'whatsapp_basic',
                'reports',
                'financial_projections',
                'mascot',
            ],
        ],
    ],
];

