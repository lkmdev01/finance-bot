<?php

return [
    [
        'message' => 'criar meta viagem com valor de 5000',
        'expected_intent' => 'create_goal',
        'expected_domain' => 'planning',
    ],
    [
        'message' => 'quais metas eu tenho?',
        'expected_intent' => 'query_savings',
        'expected_domain' => 'planning',
    ],
    [
        'message' => 'criar assinatura Netflix mensal dia 10 19 reais',
        'expected_intent' => 'create_subscription',
        'expected_domain' => 'planning',
    ],
    [
        'message' => 'quais assinaturas eu tenho?',
        'expected_intent' => 'query_subscriptions',
        'expected_domain' => 'planning',
    ],
    [
        'message' => 'assino netflix por 39,90 todo mes',
        'expected_intent' => 'create_subscription',
        'expected_domain' => 'planning',
    ],
    [
        'message' => 'todo dia 5 pago academia 89',
        'expected_intent' => 'create_recurring_transaction',
        'expected_domain' => 'transaction',
    ],
    [
        'message' => 'quais recorrencias eu tenho?',
        'expected_intent' => 'query_recurring_transactions',
        'expected_domain' => 'transaction',
    ],
    [
        'message' => 'ajusta academia para 99',
        'expected_intent' => 'update_recurring_transaction',
        'expected_domain' => 'transaction',
        'state' => [
            'last_entities' => [
                'topic' => 'recurring_transactions',
                'recurring_description' => 'Academia',
            ],
        ],
    ],
];
