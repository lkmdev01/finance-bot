<?php

use App\Assistant\Reports\AssistantObservabilityService;
use App\Models\User;
use App\Models\WhatsAppConversationLog;

it('aggregates assistant observability by intent', function () {
    $user = User::factory()->create();

    WhatsAppConversationLog::query()->create([
        'user_id' => $user->id,
        'message' => 'qual e meu saldo?',
        'classification' => 'query_balance',
        'action' => 'query_balance',
        'used_ai' => false,
        'status' => 'handled',
        'reply' => 'Saldo',
        'metadata' => [
            'assistant_intent' => 'query_balance',
            'assistant_confidence' => 0.96,
            'assistant_missing_fields' => [],
        ],
    ]);

    WhatsAppConversationLog::query()->create([
        'user_id' => $user->id,
        'message' => 'anota',
        'classification' => 'note_needs_content',
        'action' => null,
        'used_ai' => false,
        'status' => 'handled_preflight',
        'reply' => 'O que voce quer que eu salve?',
        'metadata' => [
            'assistant_intent' => 'create_note',
            'assistant_confidence' => 0.9,
            'assistant_missing_fields' => ['content'],
        ],
    ]);

    WhatsAppConversationLog::query()->create([
        'user_id' => $user->id,
        'message' => 'mensagem esquisita',
        'classification' => 'default',
        'action' => null,
        'used_ai' => true,
        'status' => 'error',
        'reply' => 'erro',
        'error_message' => 'falhou',
        'metadata' => [
            'assistant_intent' => 'unknown',
            'assistant_confidence' => 0.2,
            'assistant_missing_fields' => [],
        ],
    ]);

    $summary = app(AssistantObservabilityService::class)->summary(14, 100);

    expect($summary['totals']['total'])->toBe(3)
        ->and($summary['totals']['unknowns'])->toBe(1)
        ->and(collect($summary['by_intent'])->pluck('intent')->all())->toContain('query_balance', 'create_note', 'unknown');

    $noteRow = collect($summary['by_intent'])->firstWhere('intent', 'create_note');

    expect($noteRow['top_missing_fields'])->toHaveKey('content');
});

it('renders the assistant observability page for authenticated users', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('assistant.observability'));

    $response->assertOk();
    $response->assertSee('Observabilidade do Assistente');
    $response->assertSee('Saude do assistente por intencao');
});
