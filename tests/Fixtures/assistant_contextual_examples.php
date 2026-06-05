<?php

return [
    [
        'message' => 'na verdade foi 70',
        'state' => [
            'mode' => 'idle',
            'last_action' => 'query_transactions',
            'last_entities' => [
                'topic' => 'transactions',
                'latest_transaction_id' => 10,
                'latest_transaction_description' => 'Uber',
                'transaction_type' => 'expense',
            ],
        ],
        'expected_intent' => 'update_transaction',
    ],
    [
        'message' => 'apaga o ultimo gasto',
        'state' => [
            'mode' => 'idle',
            'last_action' => 'query_transactions',
            'last_entities' => [
                'topic' => 'transactions',
                'latest_transaction_id' => 11,
                'latest_transaction_description' => 'Mercado',
                'transaction_type' => 'expense',
            ],
        ],
        'expected_intent' => 'delete_transaction',
    ],
    [
        'message' => 'foi no cartao',
        'state' => [
            'mode' => 'idle',
            'last_action' => 'query_transactions',
            'last_entities' => [
                'topic' => 'transactions',
                'latest_transaction_id' => 12,
                'latest_transaction_description' => 'Padaria',
                'transaction_type' => 'expense',
            ],
        ],
        'expected_intent' => 'update_transaction',
    ],
];
