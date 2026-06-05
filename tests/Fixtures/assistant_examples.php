<?php

return [
    [
        'message' => 'gastei 50 no mercado',
        'expected_intent' => 'create_expense',
        'expected_amount' => 50.0,
        'expected_type' => 'expense',
    ],
    [
        'message' => 'paguei 120 de internet',
        'expected_intent' => 'create_expense',
        'expected_amount' => 120.0,
        'expected_type' => 'expense',
    ],
    [
        'message' => 'recebi 500 do cliente Joao',
        'expected_intent' => 'create_income',
        'expected_amount' => 500.0,
        'expected_type' => 'income',
    ],
    [
        'message' => 'qual e meu saldo?',
        'expected_intent' => 'query_balance',
    ],
    [
        'message' => 'quanto tenho disponivel?',
        'expected_intent' => 'query_balance',
    ],
    [
        'message' => 'quanto gastei esse mes?',
        'expected_intent' => 'query_month_report',
    ],
    [
        'message' => 'faz meu resumo do mes',
        'expected_intent' => 'query_month_report',
    ],
];
