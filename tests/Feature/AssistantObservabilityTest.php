<?php

use App\Assistant\Reports\AssistantObservabilityService;
use App\Models\User;
use App\Models\WhatsAppConversationLog;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    File::delete(storage_path('app/assistant/weekly_review_activity.jsonl'));
});

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

it('renders preview diff for a selected domain on observability page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    WhatsAppConversationLog::query()->create([
        'user_id' => $user->id,
        'message' => 'tem mais assinaturas?',
        'classification' => 'default',
        'action' => null,
        'used_ai' => true,
        'status' => 'error',
        'reply' => 'erro',
        'metadata' => [
            'assistant_intent' => 'unknown',
            'assistant_domain' => 'planning',
            'assistant_confidence' => 0.31,
            'assistant_missing_fields' => [],
        ],
    ]);

    $response = $this->get(route('assistant.observability', ['preview_domain' => 'planning']));

    $response->assertOk();
    $response->assertSee('Preview do fixture');
    $response->assertSee('Diff');
    $response->assertSee('assistant_observability_planning_examples.php');
});

it('renders preview diff for a selected backlog item on observability page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    WhatsAppConversationLog::query()->create([
        'user_id' => $user->id,
        'message' => 'ajusta meu orçamento',
        'classification' => 'budget_update_needs_target',
        'action' => null,
        'used_ai' => false,
        'status' => 'handled_preflight',
        'reply' => 'Qual orcamento voce quer ajustar?',
        'metadata' => [
            'assistant_intent' => 'create_budget',
            'assistant_domain' => 'budget',
            'assistant_confidence' => 0.44,
            'assistant_missing_fields' => ['amount'],
        ],
    ]);

    $itemKey = collect(app(AssistantObservabilityService::class)->summary(14, 100)['regression_backlog'])
        ->firstWhere('domain', 'budget')['key'];

    $response = $this->get(route('assistant.observability', [
        'preview_domain' => 'budget',
        'preview_item' => $itemKey,
    ]));

    $response->assertOk();
    $response->assertSee('Preview seletivo por item');
    $response->assertSee('Gerado com esse item');
    $response->assertSee('ajusta meu orçamento');
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

it('syncs a single backlog item from the observability page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    WhatsAppConversationLog::query()->create([
        'user_id' => $user->id,
        'message' => 'qual orçamento eu ajusto?',
        'classification' => 'default',
        'action' => null,
        'used_ai' => true,
        'status' => 'error',
        'reply' => 'erro',
        'metadata' => [
            'assistant_intent' => 'unknown',
            'assistant_domain' => 'budget',
            'assistant_confidence' => 0.2,
            'assistant_missing_fields' => [],
        ],
    ]);

    File::deleteDirectory(base_path('tests/Fixtures/generated/budget'));

    $itemKey = collect(app(AssistantObservabilityService::class)->summary(14, 100)['regression_backlog'])
        ->firstWhere('domain', 'budget')['key'];

    $response = $this->post(route('assistant.observability.sync-fixtures'), [
        'days' => 14,
        'focus' => 'all',
        'item_key' => $itemKey,
    ]);

    $response->assertRedirect(route('assistant.observability', ['days' => 14, 'focus' => 'all']));
    $response->assertSessionHas('message');
    expect(File::exists(base_path('tests/Fixtures/generated/budget/assistant_observability_budget_examples.php')))->toBeTrue();
    expect(File::get(base_path('tests/Fixtures/generated/budget/assistant_observability_budget_examples.php')))->toContain('qual orçamento eu ajusto?');

    File::deleteDirectory(base_path('tests/Fixtures/generated/budget'));
});

it('exports approved fixtures from the selected weekly review window', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    app(AssistantObservabilityService::class)->recordApprovalActivity([
        [
            'key' => 'item-a',
            'domain' => 'notes',
            'intent' => 'create_note',
            'message' => 'anota isso',
            'suggested_example' => [
                'message' => 'anota isso',
                'expected_intent' => 'create_note',
            ],
        ],
    ], 'test');

    $response = $this->get(route('assistant.observability.export-fixtures', [
        'approved' => 1,
        'approved_days' => 7,
    ]));

    $response->assertOk();
    $response->assertSee('anota isso');
    $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
});

it('renders weekly review usage metrics on the observability page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $service = app(AssistantObservabilityService::class);
    $service->recordReviewRun([
        'days' => 7,
        'sample' => 100,
        'focus' => 'all',
        'sync' => true,
        'backlog_count' => 3,
    ]);
    $service->recordApprovalActivity([
        [
            'key' => 'item-b',
            'domain' => 'budget',
            'intent' => 'query_budgets',
            'message' => 'tem mais orcamentos?',
            'suggested_example' => [
                'message' => 'tem mais orcamentos?',
                'expected_intent' => 'query_budgets',
            ],
        ],
    ], 'test');

    $response = $this->get(route('assistant.observability', ['approved_days' => 7]));

    $response->assertOk();
    $response->assertSee('Uso da revisao semanal');
    $response->assertSee('Aprovacoes de itens');
    $response->assertSee('budget');
});

it('builds weekly operational snapshot with goals alerts and comparison', function () {
    $service = app(AssistantObservabilityService::class);
    config()->set('assistant.weekly_goals.review_runs', 1);
    config()->set('assistant.weekly_goals.item_approvals', 10);
    config()->set('assistant.weekly_goals.sync_runs', 1);

    $service->recordReviewRun([
        'occurred_at' => now()->subWeek()->startOfWeek()->addDay()->toIso8601String(),
        'days' => 7,
        'sample' => 100,
        'focus' => 'all',
        'sync' => true,
        'backlog_count' => 6,
    ]);
    $service->recordApprovalActivity([
        [
            'key' => 'previous-1',
            'occurred_at' => now()->subWeek()->startOfWeek()->addDay()->toIso8601String(),
            'domain' => 'notes',
            'intent' => 'create_note',
            'message' => 'nota passada',
            'suggested_example' => ['message' => 'nota passada', 'expected_intent' => 'create_note'],
        ],
        [
            'key' => 'previous-2',
            'occurred_at' => now()->subWeek()->startOfWeek()->addDays(2)->toIso8601String(),
            'domain' => 'drive',
            'intent' => 'query_drive_files',
            'message' => 'drive passado',
            'suggested_example' => ['message' => 'drive passado', 'expected_intent' => 'query_drive_files'],
        ],
    ], 'weekly_review');

    $service->recordReviewRun([
        'occurred_at' => now()->startOfWeek()->addDay()->toIso8601String(),
        'days' => 7,
        'sample' => 100,
        'focus' => 'all',
        'sync' => false,
        'backlog_count' => 2,
    ]);
    $service->recordApprovalActivity([
        [
            'key' => 'current-1',
            'occurred_at' => now()->startOfWeek()->addDay()->toIso8601String(),
            'domain' => 'budget',
            'intent' => 'query_budgets',
            'message' => 'orcamento atual',
            'suggested_example' => ['message' => 'orcamento atual', 'expected_intent' => 'query_budgets'],
        ],
    ], 'weekly_review');

    $snapshot = $service->weeklyOperationalSnapshot('weekly_review');

    expect($snapshot['goals']['review_runs']['current'])->toBe(1)
        ->and($snapshot['goals']['review_runs']['met'])->toBeTrue()
        ->and($snapshot['goals']['item_approvals']['met'])->toBeFalse()
        ->and($snapshot['comparison']['item_approvals']['delta'])->toBe(-1)
        ->and(collect($snapshot['alerts'])->pluck('title')->all())->toContain('Sync semanal pendente', 'Meta de aprovacoes em aberto')
        ->and($snapshot['sla']['status'])->toBe('yellow')
        ->and($snapshot['alerts'][0]['cta']['label'] ?? null)->toBe('Abrir ritual');
});

it('uses assistant weekly goals from configuration', function () {
    config()->set('assistant.weekly_goals.review_runs', 2);
    config()->set('assistant.weekly_goals.item_approvals', 6);
    config()->set('assistant.weekly_goals.sync_runs', 3);

    $goals = app(AssistantObservabilityService::class)->weeklyGoals();

    expect($goals['review_runs'])->toBe(2)
        ->and($goals['item_approvals'])->toBe(6)
        ->and($goals['sync_runs'])->toBe(3);
});

it('filters approved weekly exports by approval source', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $service = app(AssistantObservabilityService::class);
    $service->recordApprovalActivity([
        [
            'key' => 'item-dashboard',
            'domain' => 'notes',
            'intent' => 'create_note',
            'message' => 'nota pelo dashboard',
            'suggested_example' => [
                'message' => 'nota pelo dashboard',
                'expected_intent' => 'create_note',
            ],
        ],
    ], 'dashboard_item');
    $service->recordApprovalActivity([
        [
            'key' => 'item-weekly',
            'domain' => 'planning',
            'intent' => 'query_subscriptions',
            'message' => 'assinatura pelo review',
            'suggested_example' => [
                'message' => 'assinatura pelo review',
                'expected_intent' => 'query_subscriptions',
            ],
        ],
    ], 'weekly_review');

    $response = $this->get(route('assistant.observability.export-fixtures', [
        'approved' => 1,
        'approved_days' => 7,
        'source' => 'weekly_review',
    ]));

    $response->assertOk();
    $response->assertSee('assinatura pelo review');
    $response->assertDontSee('nota pelo dashboard');
});

it('runs the weekly review immediately from observability', function () {
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

    $response = $this->post(route('assistant.observability.run-review'), [
        'days' => 7,
        'focus' => 'all',
        'sync' => 1,
    ]);

    $response->assertRedirect(route('assistant.observability', ['days' => 7, 'focus' => 'all']));
    $response->assertSessionHas('message');
    expect(app(AssistantObservabilityService::class)->weeklyReviewUsage(7)['review_runs'])->toBeGreaterThan(0);
});

it('prints a weekly operational review and can sync fixtures', function () {
    $user = User::factory()->create();

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

    $outputDirectory = base_path('tests/Fixtures/generated-weekly-review-test');
    File::deleteDirectory($outputDirectory);

    $this->artisan('assistant:weekly-review', [
        '--days' => 7,
        '--sync' => true,
        '--output' => $outputDirectory,
    ])
        ->expectsOutputToContain('Revisao semanal do assistente')
        ->expectsOutputToContain('Backlog priorizado')
        ->expectsOutputToContain('Sincronizando fixtures')
        ->assertSuccessful();

    expect(File::exists($outputDirectory.DIRECTORY_SEPARATOR.'notes'.DIRECTORY_SEPARATOR.'assistant_observability_notes_examples.php'))->toBeTrue();
    expect(app(AssistantObservabilityService::class)->weeklyReviewUsage(7)['review_runs'])->toBe(1)
        ->and(app(AssistantObservabilityService::class)->weeklyReviewUsage(7)['item_approvals'])->toBeGreaterThan(0);

    File::deleteDirectory($outputDirectory);
});
