<?php

use App\Assistant\Reports\AssistantObservabilityService;
use App\Models\User;
use App\Models\WhatsAppConversationLog;
use Illuminate\Support\Facades\File;

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
    $backlog = collect($summary['regression_backlog']);
    $byDomain = $summary['regression_backlog_by_domain'];

    expect($noteRow['top_missing_fields'])->toHaveKey('content');
    expect($backlog->pluck('intent')->all())->toContain('unknown', 'create_note')
        ->and($byDomain)->toHaveKey('notes');
});

it('renders the assistant observability page for authenticated users', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('assistant.observability'));

    $response->assertOk();
    $response->assertSee('Observabilidade do Assistente');
    $response->assertSee('Saude do assistente por intencao');
    $response->assertSee('Fila priorizada de regressao');
});

it('exports regression backlog as fixture candidates', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    WhatsAppConversationLog::query()->create([
        'user_id' => $user->id,
        'message' => 'cancelar assinatura',
        'classification' => 'subscription_cancel_needs_target',
        'action' => null,
        'used_ai' => false,
        'status' => 'handled_preflight',
        'reply' => 'Qual assinatura voce quer cancelar?',
        'metadata' => [
            'assistant_intent' => 'cancel_subscription',
            'assistant_confidence' => 0.83,
            'assistant_missing_fields' => ['name'],
        ],
    ]);

    $response = $this->get(route('assistant.observability.export-fixtures', ['focus' => 'missing', 'domain' => 'planning']));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    $response->assertSee('cancelar assinatura');
    $response->assertSee('expected_missing_field');
});

it('syncs regression backlog into generated fixture files by domain', function () {
    $user = User::factory()->create();

    WhatsAppConversationLog::query()->create([
        'user_id' => $user->id,
        'message' => 'apaga a nota',
        'classification' => 'note_delete_needs_target',
        'action' => null,
        'used_ai' => false,
        'status' => 'handled_preflight',
        'reply' => 'Qual nota voce quer apagar?',
        'metadata' => [
            'assistant_intent' => 'delete_note',
            'assistant_domain' => 'notes',
            'assistant_confidence' => 0.84,
            'assistant_missing_fields' => ['target'],
        ],
    ]);

    $directory = base_path('tests/Fixtures/generated-observability-test');
    File::deleteDirectory($directory);

    $written = app(AssistantObservabilityService::class)->syncFixtureFiles(
        days: 14,
        sampleSize: 100,
        focus: 'missing',
        outputDirectory: $directory,
    );

    expect($written)->toHaveKey('notes');
    expect(File::exists($written['notes']))->toBeTrue();
    expect(File::get($written['notes']))->toContain('apaga a nota');
    expect($written['notes'])->toContain('notes');

    File::deleteDirectory($directory);
});

it('syncs fixtures from the observability page for a specific domain', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    WhatsAppConversationLog::query()->create([
        'user_id' => $user->id,
        'message' => 'tem mais notas?',
        'classification' => 'default',
        'action' => null,
        'used_ai' => false,
        'status' => 'handled',
        'reply' => 'Sim',
        'metadata' => [
            'assistant_intent' => 'unknown',
            'assistant_domain' => 'notes',
            'assistant_confidence' => 0.3,
            'assistant_missing_fields' => [],
        ],
    ]);

    File::deleteDirectory(base_path('tests/Fixtures/generated/notes'));

    $response = $this->post(route('assistant.observability.sync-fixtures'), [
        'days' => 14,
        'focus' => 'all',
        'domain' => 'notes',
    ]);

    $response->assertRedirect(route('assistant.observability', ['days' => 14, 'focus' => 'all']));
    $response->assertSessionHas('message');
    expect(File::exists(base_path('tests/Fixtures/generated/notes/assistant_observability_notes_examples.php')))->toBeTrue();

    File::deleteDirectory(base_path('tests/Fixtures/generated/notes'));
});
