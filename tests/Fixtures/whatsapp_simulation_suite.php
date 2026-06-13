<?php

return [
    [
        'key' => 'finance_core',
        'seed' => 'finance_core',
        'entries' => [
            [
                'message' => 'gastei 50 no mercado',
                'expected_intent' => 'create_expense',
            ],
            [
                'message' => 'recebi 500 do cliente Joao',
                'expected_intent' => 'create_income',
            ],
            [
                'message' => 'qual e meu saldo?',
                'expected_intent' => 'query_balance',
                'expected_state_topic' => 'transactions',
            ],
            [
                'message' => 'quanto gastei esse mes?',
                'expected_intent' => 'query_month_report',
            ],
        ],
    ],
    [
        'key' => 'notes_and_reminders_create',
        'seed' => 'notes_and_reminders_create',
        'entries' => [
            [
                'message' => 'salvar nota: revisar contrato com fornecedor',
                'expected_intent' => 'create_note',
            ],
            [
                'message' => 'me lembra de pagar a internet amanha as 9',
                'expected_intent' => 'create_reminder',
            ],
        ],
    ],
    [
        'key' => 'notes_and_reminders_queries',
        'seed' => 'notes_and_reminders_queries',
        'entries' => [
            [
                'message' => 'minhas notas',
                'expected_intent' => 'query_notes',
                'expected_reply_contains' => ['Projeto Alpha'],
                'expected_state_topic' => 'notes',
            ],
            [
                'message' => 'me mostra essa nota',
                'expected_reply_contains' => ['Aqui esta a nota'],
                'expected_state_topic' => 'notes',
            ],
            [
                'message' => 'tem mais notas?',
                'expected_reply_contains' => ['2 notas'],
            ],
            [
                'message' => 'quais lembretes eu tenho?',
                'expected_intent' => 'query_reminders',
                'expected_reply_contains' => ['Pagar Academia'],
                'expected_state_topic' => 'reminders',
            ],
            [
                'message' => 'me mostra esse lembrete',
                'expected_reply_contains' => ['Aqui esta o lembrete', 'Proximo disparo'],
            ],
            [
                'message' => 'tem mais lembretes?',
                'expected_reply_contains' => ['2 lembretes'],
            ],
        ],
    ],
    [
        'key' => 'notes_context_reset',
        'seed' => 'notes_and_reminders_queries',
        'entries' => [
            [
                'message' => 'quais sao minhas notas ativas?',
                'expected_intent' => 'query_notes',
                'expected_reply_contains' => ['Projeto Alpha'],
                'expected_reply_not_contains' => ['Nota salva'],
                'expected_state_topic' => 'notes',
            ],
            [
                'message' => 'me mostra essa nota',
                'expected_intent' => 'query_notes',
                'expected_reply_contains' => ['Aqui esta a nota'],
                'expected_state_topic' => 'notes',
            ],
            [
                'message' => 'Bom dia',
                'expected_classification' => 'greeting',
                'expected_reply_contains' => ['Ola'],
                'expected_reply_not_contains' => ['Aqui esta a nota', 'Nota salva'],
                'expected_state_topic' => 'general',
            ],
            [
                'message' => 'Como voce pode me ajudar',
                'expected_classification' => 'help',
                'expected_reply_contains' => ['Posso te ajudar de varios jeitos'],
                'expected_reply_not_contains' => ['Projeto Alpha', 'Nota salva'],
                'expected_state_topic' => 'general',
            ],
        ],
    ],
    [
        'key' => 'reminders_context_reset',
        'seed' => 'notes_and_reminders_queries',
        'entries' => [
            [
                'message' => 'quais lembretes eu tenho?',
                'expected_intent' => 'query_reminders',
                'expected_reply_contains' => ['Pagar Academia'],
                'expected_state_topic' => 'reminders',
            ],
            [
                'message' => 'me mostra esse lembrete',
                'expected_intent' => 'query_reminders',
                'expected_reply_contains' => ['Aqui esta o lembrete', 'Proximo disparo'],
                'expected_state_topic' => 'reminders',
            ],
            [
                'message' => 'oi',
                'expected_classification' => 'greeting',
                'expected_reply_contains' => ['Ola'],
                'expected_reply_not_contains' => ['Aqui esta o lembrete', 'Pagar Academia'],
                'expected_state_topic' => 'general',
            ],
            [
                'message' => 'como voce esta?',
                'expected_classification' => 'small_talk',
                'expected_reply_contains' => ['Estou bem'],
                'expected_reply_not_contains' => ['Pagar Academia'],
                'expected_state_topic' => 'general',
            ],
        ],
    ],
    [
        'key' => 'planning_queries',
        'seed' => 'planning_queries',
        'entries' => [
            [
                'message' => 'quais metas eu tenho?',
                'expected_intent' => 'query_savings',
                'expected_reply_contains' => ['Viagem'],
                'expected_state_topic' => 'savings',
            ],
            [
                'message' => 'me mostra essa meta',
                'expected_reply_contains' => ['Viagem', 'R$ 5.000,00'],
            ],
            [
                'message' => 'quais assinaturas eu tenho?',
                'expected_intent' => 'query_subscriptions',
                'expected_reply_contains' => ['Netflix'],
                'expected_state_topic' => 'subscriptions',
            ],
            [
                'message' => 'me mostra essa assinatura',
                'expected_reply_contains' => ['Netflix', 'R$ 39,90'],
            ],
            [
                'message' => 'quais recorrencias eu tenho?',
                'state' => [],
                'replace_state' => true,
                'expected_intent' => 'query_recurring_transactions',
                'expected_reply_contains' => ['Aluguel'],
                'expected_state_topic' => 'recurring_transactions',
            ],
            [
                'message' => 'tem mais recorrencias?',
                'expected_reply_contains' => ['2 recorrencias'],
            ],
        ],
    ],
    [
        'key' => 'savings_context_reset',
        'seed' => 'planning_queries',
        'entries' => [
            [
                'message' => 'quais metas eu tenho?',
                'expected_intent' => 'query_savings',
                'expected_reply_contains' => ['Viagem'],
                'expected_state_topic' => 'savings',
            ],
            [
                'message' => 'me mostra essa meta',
                'expected_intent' => 'query_savings',
                'expected_reply_contains' => ['Viagem', 'R$ 5.000,00'],
                'expected_state_topic' => 'savings',
            ],
            [
                'message' => 'Bom dia',
                'expected_classification' => 'greeting',
                'expected_reply_contains' => ['Ola'],
                'expected_reply_not_contains' => ['R$ 5.000,00', 'Viagem'],
                'expected_state_topic' => 'general',
            ],
            [
                'message' => 'Como voce pode me ajudar',
                'expected_classification' => 'help',
                'expected_reply_contains' => ['Posso te ajudar de varios jeitos'],
                'expected_reply_not_contains' => ['R$ 5.000,00'],
                'expected_state_topic' => 'general',
            ],
        ],
    ],
    [
        'key' => 'planning_creations',
        'seed' => 'planning_creations',
        'entries' => [
            [
                'message' => 'criar meta viagem',
                'expected_pending_intent' => 'create_savings_goal_details',
            ],
            [
                'message' => '5000 ate dezembro de 2026',
            ],
            [
                'message' => 'criar assinatura Netflix mensal',
                'expected_pending_intent' => 'create_subscription_details',
            ],
            [
                'message' => '39,90 dia 10',
            ],
        ],
    ],
    [
        'key' => 'transaction_and_budget_context',
        'seed' => 'transaction_and_budget_context',
        'entries' => [
            [
                'message' => 'ajusta para 28',
                'state' => [
                    'mode' => 'idle',
                    'last_action' => 'query_transactions',
                    'last_entities' => [
                        'topic' => 'transactions',
                        'transaction_id' => '__transaction_uber__',
                        'latest_transaction_id' => '__transaction_uber__',
                        'latest_transaction_description' => 'Uber',
                        'transaction_type' => 'expense',
                        'category_name' => 'Compras',
                    ],
                ],
                'expected_intent' => 'update_transaction',
            ],
            [
                'message' => 'ajusta esse no cartao Nubank',
                'state' => [
                    'mode' => 'idle',
                    'last_action' => 'query_transactions',
                    'last_entities' => [
                        'topic' => 'transactions',
                        'transaction_id' => '__transaction_uber__',
                        'latest_transaction_id' => '__transaction_uber__',
                        'latest_transaction_description' => 'Uber',
                        'transaction_type' => 'expense',
                    ],
                ],
                'expected_intent' => 'update_transaction',
            ],
            [
                'message' => 'cancela esse orcamento',
                'state' => [
                    'mode' => 'idle',
                    'last_action' => 'query_budgets',
                    'last_entities' => [
                        'topic' => 'budget',
                        'budget_id' => '__budget_compras__',
                        'category_name' => 'Compras',
                        'year' => '__current_year__',
                        'month' => '__current_month__',
                    ],
                ],
                'expected_pending_intent' => 'delete_budget',
            ],
            [
                'message' => 'sim',
                'expected_action' => 'delete_budget',
            ],
        ],
    ],
    [
        'key' => 'drive_queries',
        'seed' => 'drive_queries',
        'entries' => [
            [
                'message' => 'quais arquivos eu tenho no drive?',
                'expected_intent' => 'query_drive_files',
                'expected_reply_contains' => ['comprovante_mecanico', 'contrato_aluguel'],
                'expected_state_topic' => 'drive',
            ],
            [
                'message' => 'em qual pasta ficou?',
                'expected_intent' => 'query_drive_files',
                'expected_reply_contains' => ['ficou na pasta', 'Abrir no Drive'],
            ],
        ],
    ],
    [
        'key' => 'drive_contextual_filters',
        'seed' => 'drive_queries',
        'entries' => [
            [
                'message' => 'quais arquivos eu salvei hoje?',
                'expected_intent' => 'query_drive_files',
                'expected_reply_contains' => ['Arquivos salvos hoje:', 'foto_neve', 'audio_projeto'],
                'expected_state_topic' => 'drive',
            ],
            [
                'message' => 'procura a foto que eu mandei hoje',
                'expected_intent' => 'query_drive_files',
                'expected_reply_contains' => ['Arquivos salvos hoje:', 'foto_neve', 'recibo_oficina'],
                'expected_reply_not_contains' => ['audio_projeto', 'comprovante_mecanico'],
                'expected_state_topic' => 'drive',
            ],
            [
                'message' => 'so essa?',
                'expected_intent' => 'query_drive_files',
                'expected_reply_contains' => ['Nao. Encontrei 2 arquivos nesse filtro.', 'So encontrei esta foto salva hoje.'],
                'expected_state_topic' => 'drive',
            ],
            [
                'message' => 'tem mais fotos?',
                'expected_intent' => 'query_drive_files',
                'expected_reply_contains' => ['Seus arquivos recentes:', 'foto_neve', 'recibo_oficina'],
                'expected_reply_not_contains' => ['audio_projeto'],
                'expected_state_topic' => 'drive',
            ],
            [
                'message' => 'procura o comprovante do mecanico',
                'expected_intent' => 'query_drive_files',
                'expected_reply_contains' => ['comprovante_mecanico'],
                'expected_state_topic' => 'drive',
            ],
            [
                'message' => 'encontra o audio sobre o projeto',
                'expected_intent' => 'query_drive_files',
                'expected_reply_contains' => ['audio_projeto'],
                'expected_state_topic' => 'drive',
            ],
        ],
    ],
    [
        'key' => 'drive_context_reset',
        'seed' => 'drive_queries',
        'entries' => [
            [
                'message' => 'quais arquivos eu tenho no drive?',
                'expected_intent' => 'query_drive_files',
                'expected_reply_contains' => ['comprovante_mecanico', 'contrato_aluguel'],
                'expected_state_topic' => 'drive',
            ],
            [
                'message' => 'Bom dia',
                'expected_classification' => 'greeting',
                'expected_reply_contains' => ['Ola'],
                'expected_reply_not_contains' => ['Abrir o primeiro', 'Nao encontrei arquivos'],
                'expected_state_topic' => 'general',
            ],
            [
                'message' => 'Como voce pode me ajudar',
                'expected_classification' => 'help',
                'expected_reply_contains' => ['Posso te ajudar de varios jeitos'],
                'expected_reply_not_contains' => ['Abrir o primeiro', 'Nao encontrei arquivos'],
                'expected_state_topic' => 'general',
            ],
            [
                'message' => 'como voce esta?',
                'expected_classification' => 'small_talk',
                'expected_reply_contains' => ['Estou bem'],
                'expected_reply_not_contains' => ['Abrir o primeiro', 'Nao encontrei arquivos'],
                'expected_state_topic' => 'general',
            ],
        ],
    ],
    [
        'key' => 'recurring_cancel_flow',
        'seed' => 'recurring_cancel_flow',
        'entries' => [
            [
                'message' => 'cancela a recorrencia',
                'expected_pending_intent' => 'cancel_recurring_transaction_target',
            ],
            [
                'message' => 'de aluguel',
                'expected_action' => 'cancel_recurring_transaction',
            ],
        ],
    ],
];
