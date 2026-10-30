<?php

/**
 * Fictional, non-personal WhatsApp examples used to check cross-domain routing.
 * Replies are deterministic contracts; this fixture does not call an LLM or APIs.
 */
return [
    [
        'name' => 'calendar_week_lookup',
        'message' => 'Quero ver minha agenda esta semana',
        'domain' => 'agenda',
        'reply' => 'Encontrei dois compromissos na sua agenda.',
    ],
    [
        'name' => 'calendar_daily_digest',
        'message' => 'Resumo da agenda de hoje',
        'domain' => 'agenda',
        'reply' => 'Resumo de hoje: nenhum compromisso encontrado.',
    ],
    [
        'name' => 'calendar_availability',
        'message' => 'Estou livre amanhã das 10 às 11?',
        'domain' => 'agenda',
        'reply' => 'Vou verificar esse intervalo na sua agenda.',
    ],
    [
        'name' => 'calendar_create_clarification',
        'message' => 'Marque uma consulta com a dentista',
        'domain' => 'agenda',
        'reply' => 'Qual dia e horário devo considerar?',
    ],
    [
        'name' => 'task_pending_list',
        'message' => 'Liste minhas tarefas pendentes',
        'domain' => 'tasks',
        'reply' => 'Suas tarefas pendentes: nenhuma tarefa pendente.',
    ],
    [
        'name' => 'task_create',
        'message' => 'Crie a tarefa revisar o contrato até 10/11/2026',
        'domain' => 'tasks',
        'reply' => 'Confirma registrar esta tarefa no Google Tasks?',
    ],
    [
        'name' => 'task_create_clarification',
        'message' => 'Crie uma tarefa',
        'domain' => 'tasks',
        'reply' => 'O que exatamente você quer registrar como tarefa?',
    ],
    [
        'name' => 'finance_expense',
        'message' => 'Gastei R$ 42,50 no mercado',
        'domain' => 'finance',
        'reply' => null,
    ],
    [
        'name' => 'finance_spending_query',
        'message' => 'Quanto gastei com mercado este mês?',
        'domain' => 'finance',
        'reply' => null,
    ],
    [
        'name' => 'finance_income',
        'message' => 'Recebi R$ 1.200 de um cliente',
        'domain' => 'finance',
        'reply' => null,
    ],
    [
        'name' => 'meeting_start',
        'message' => 'Reunião https://meet.google.com/abc-defg-hij participantes avisados e autorizo',
        'domain' => 'meetings',
        'reply' => 'O pedido de reunião foi recebido.',
    ],
    [
        'name' => 'meeting_status',
        'message' => 'Status da reunião',
        'domain' => 'meetings',
        'reply' => 'Sua reunião está em andamento.',
    ],
    [
        'name' => 'meeting_report',
        'message' => 'Resumo da reunião',
        'domain' => 'meetings',
        'reply' => 'O relatório da última reunião está pronto.',
    ],
    [
        'name' => 'meeting_incomplete_command',
        'message' => 'Reunião',
        'domain' => 'meetings',
        'reply' => 'Para iniciar, envie o link e confirme que os participantes foram avisados.',
    ],
    [
        'name' => 'public_research',
        'message' => 'Pesquise o preço médio de um teclado mecânico',
        'domain' => 'research',
        'reply' => 'Recebi sua pesquisa e vou consultar fontes públicas.',
    ],
    [
        'name' => 'research_capability',
        'message' => 'Você consegue pesquisar na internet?',
        'domain' => 'research',
        'reply' => 'Sim, posso pesquisar informações públicas e trazer as fontes.',
    ],
    [
        'name' => 'research_private_account_question',
        'message' => 'Pesquise meus gastos deste mês',
        'domain' => 'research',
        'reply' => 'Pesquisas usam apenas informações públicas; dados da conta não são enviados à busca.',
    ],
];
