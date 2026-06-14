<?php

use App\Services\WhatsApp\DomainGate;
use App\Services\WhatsApp\MessageClassifier;

it('roteia consulta de notas para notes mesmo apos contexto de metas', function () {
    $state = [
        'last_action' => 'query_savings',
        'last_entities' => [
            'topic' => 'savings',
            'goal_name' => 'Viagem',
        ],
    ];

    expect(app(DomainGate::class)->detect('quais sao minhas notas ativas?', $state))->toBe('notes');

    $classification = app(MessageClassifier::class)->classify('quais sao minhas notas ativas?', $state);

    expect($classification['kind'])->toBe('note_query')
        ->and($classification['domain'])->toBe('notes');
});

it('roteia abrir nota por numero para notes mesmo apos contexto de metas', function () {
    $state = [
        'last_action' => 'query_savings',
        'last_entities' => [
            'topic' => 'savings',
            'goal_name' => 'Viagem',
            'goal_count' => 5,
            'recent_goal_ids' => [1, 2, 3, 4, 5],
        ],
    ];

    expect(app(DomainGate::class)->detect('abrir nota 2', $state))->toBe('notes');

    $classification = app(MessageClassifier::class)->classify('abrir nota 2', $state);

    expect($classification['kind'])->toBe('note_query')
        ->and($classification['domain'])->toBe('notes');
});
