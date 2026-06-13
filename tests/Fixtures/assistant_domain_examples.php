<?php

return [
    [
        'message' => 'criar orcamento de 500 para compras',
        'expected_intent' => 'create_budget',
        'expected_domain' => 'budget',
        'expected_amount' => 500.0,
    ],
    [
        'message' => 'anota: ligar para o contador',
        'expected_intent' => 'create_note',
        'expected_domain' => 'notes',
    ],
    [
        'message' => 'minhas notas',
        'expected_intent' => 'query_notes',
        'expected_domain' => 'notes',
    ],
    [
        'message' => 'salvar nota: revisar contrato com fornecedor',
        'expected_intent' => 'create_note',
        'expected_domain' => 'notes',
    ],
    [
        'message' => 'me lembra de pagar a academia todo dia 5',
        'expected_intent' => 'create_reminder',
        'expected_domain' => 'reminders',
    ],
    [
        'message' => 'me lembra de pagar a internet amanha as 9',
        'expected_intent' => 'create_reminder',
        'expected_domain' => 'reminders',
    ],
    [
        'message' => 'quais lembretes eu tenho?',
        'expected_intent' => 'query_reminders',
        'expected_domain' => 'reminders',
    ],
    [
        'message' => 'procura meus arquivos',
        'expected_intent' => 'query_drive_files',
        'expected_domain' => 'drive',
    ],
];
