<?php

return [
    'default_plan' => 'starter',
    'trial_days' => 7,
    'trial_expired_message' => 'Seu teste gratuito terminou. Para continuar registrando novas informações, ative um plano.',

    // Métodos exibidos no checkout avulso da AbacatePay.
    // Mantemos PIX + Cartão apenas para planos sem recorrência automática.
    'checkout_methods' => ['PIX', 'CARD'],

    // Métodos permitidos para checkout de assinatura (recorrência automática).
    // Para evitar o erro "PIX Automático is not available for this store", usamos apenas cartão.
    'subscription_methods' => ['CARD'],

    'premium_features' => [
        'reports',
        'financial_projections',
        'mascot',
    ],

    'plans' => [
        'starter' => [
            'code' => 'starter',
            'name' => 'Inicial',
            'description' => 'Base para consultar histórico, painel e continuar acompanhando suas finanças.',
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
            'description' => 'Brasil na Copa: 30% de desconto especial com acesso completo e renovação mensal no cartão.',
            'price_cents' => 1997,
            'frequency' => 'MONTHLY',
            'badge' => 'Brasil na Copa',
            'highlight' => true,
            'product_id' => env('ABACATEPAY_PLAN_PRO_MONTHLY_PRODUCT_ID'),
            'visible' => true,
            'sellable' => true,
            // checkout = pagamento avulso (sem renovação automática)
            // subscription = assinatura recorrente (renova automaticamente no cartão)
            'checkout_flow' => env('BILLING_PLAN_PRO_MONTHLY_FLOW', 'subscription'),
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
            'description' => 'Plano legado mantido apenas para compatibilidade de histórico.',
            'price_cents' => 19970,
            'frequency' => 'YEARLY',
            'badge' => 'Legado',
            'highlight' => false,
            'product_id' => env('ABACATEPAY_PLAN_PRO_YEARLY_PRODUCT_ID'),
            'visible' => false,
            'sellable' => false,
            'checkout_flow' => env('BILLING_PLAN_PRO_YEARLY_FLOW', 'subscription'),
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
