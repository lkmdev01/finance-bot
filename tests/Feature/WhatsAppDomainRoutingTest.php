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

it('roteia consultas de drive para drive mesmo apos contexto de notas', function () {
    $state = [
        'last_action' => 'query_notes',
        'last_entities' => [
            'topic' => 'notes',
            'note_id' => 1,
            'recent_note_ids' => [1],
            'note_result_count' => 1,
        ],
    ];

    expect(app(DomainGate::class)->detect('quais arquivos eu tenho no drive?', $state))->toBe('drive');

    $classification = app(MessageClassifier::class)->classify('quais arquivos eu tenho no drive?', $state);

    expect($classification['kind'])->toBe('drive_query')
        ->and($classification['domain'])->toBe('drive');
});

it('roteia busca de foto para drive mesmo apos contexto de notas', function () {
    $state = [
        'last_action' => 'query_notes',
        'last_entities' => [
            'topic' => 'notes',
            'note_id' => 1,
            'recent_note_ids' => [1],
            'note_result_count' => 1,
        ],
    ];

    expect(app(DomainGate::class)->detect('procura a foto que eu mandei hoje', $state))->toBe('drive');

    $classification = app(MessageClassifier::class)->classify('procura a foto que eu mandei hoje', $state);

    expect($classification['kind'])->toBe('drive_query')
        ->and($classification['domain'])->toBe('drive');
});
