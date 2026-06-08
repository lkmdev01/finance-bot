<?php

use App\Assistant\Reports\AssistantObservabilityService;
use App\Models\User;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    File::delete(storage_path('app/assistant/weekly_review_activity.jsonl'));
});

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    app(AssistantObservabilityService::class)->recordReviewRun([
        'days' => 7,
        'sample' => 100,
        'focus' => 'all',
        'sync' => true,
        'backlog_count' => 2,
    ]);
    app(AssistantObservabilityService::class)->recordApprovalActivity([
        [
            'key' => 'dashboard-trend',
            'domain' => 'notes',
            'intent' => 'create_note',
            'message' => 'nota aprovada',
            'suggested_example' => [
                'message' => 'nota aprovada',
                'expected_intent' => 'create_note',
            ],
        ],
    ], 'weekly_review');

    $response = $this->get(route('dashboard'));
    $response->assertStatus(200);
    $response->assertSee('Observabilidade IA ligada no fluxo');
    $response->assertSee('Tendencia semanal do assistente');
    $response->assertSee('Aprovacoes');
    $response->assertSee('Meta ok');
    $response->assertSee('Comparativo com a semana passada');
    $response->assertSee('SLA da operacao do assistente');
    $response->assertSee('Abrir observabilidade');
});

test('dashboard layout does not use unsupported flux sidebar toggle expression', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertDontSee('$flux.sidebar.toggle()', false);
});
