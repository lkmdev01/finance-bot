<?php

use App\Models\Note;
use App\Models\Reminder;
use App\Models\SavingsGoal;
use App\Models\Subscription;
use App\Models\User;
use App\Services\WhatsApp\NotesConversationService;
use App\Services\WhatsApp\ReminderConversationService;
use App\Services\WhatsApp\SavingsConversationService;
use App\Services\WhatsApp\SubscriptionConversationService;

it('supports contextual follow ups for listed notes', function () {
    $user = User::factory()->create();

    $first = Note::create([
        'user_id' => $user->id,
        'title' => 'Projeto Alpha',
        'body' => 'Detalhes da reuniao com o cliente.',
        'source' => 'whatsapp',
        'metadata' => [],
    ]);

    $second = Note::create([
        'user_id' => $user->id,
        'title' => 'Projeto Beta',
        'body' => 'Checklist de entrega.',
        'source' => 'whatsapp',
        'metadata' => [],
    ]);

    $state = [
        'last_action' => 'query_notes',
        'last_entities' => [
            'topic' => 'notes',
            'note_id' => $first->id,
            'note_title' => $first->title,
            'query_term' => 'projeto',
            'recent_note_ids' => [$first->id, $second->id],
            'note_result_count' => 2,
        ],
    ];

    $service = app(NotesConversationService::class);

    $more = $service->buildReply($user, 'tem mais notas?', $state);
    $show = $service->buildReply($user, 'me mostra essa nota', $state);

    expect($more['reply'])->toContain('2 notas')
        ->and($show['reply'])->toContain('Projeto Alpha')
        ->and($show['reply'])->toContain('Detalhes da reuniao');
});

it('supports contextual follow ups for listed reminders', function () {
    $user = User::factory()->create();

    $first = Reminder::create([
        'user_id' => $user->id,
        'title' => 'Pagar Academia',
        'message' => 'Lembrete mensal: Pagar Academia',
        'frequency' => 'monthly',
        'timezone' => config('app.timezone'),
        'next_trigger_at' => now()->addDays(3),
        'day_of_month' => 10,
        'trigger_time' => '09:00:00',
        'is_active' => true,
    ]);

    $second = Reminder::create([
        'user_id' => $user->id,
        'title' => 'Tomar Agua',
        'message' => 'Lembrete diario: Tomar Agua',
        'frequency' => 'daily',
        'timezone' => config('app.timezone'),
        'next_trigger_at' => now()->addDay(),
        'trigger_time' => '08:00:00',
        'is_active' => true,
    ]);

    $state = [
        'last_action' => 'query_reminders',
        'last_entities' => [
            'topic' => 'reminders',
            'reminder_id' => $first->id,
            'reminder_title' => $first->title,
            'recent_reminder_ids' => [$first->id, $second->id],
            'reminder_result_count' => 2,
        ],
    ];

    $service = app(ReminderConversationService::class);

    $more = $service->buildReply($user, 'tem mais lembretes?', $state);
    $show = $service->buildReply($user, 'me mostra esse lembrete', $state);

    expect($more['reply'])->toContain('2 lembretes')
        ->and($show['reply'])->toContain('Pagar Academia')
        ->and($show['reply'])->toContain('Proximo disparo');
});

it('supports contextual follow ups for listed subscriptions', function () {
    $user = User::factory()->create();

    $first = Subscription::create([
        'user_id' => $user->id,
        'name' => 'Netflix',
        'amount' => 39.90,
        'billing_cycle' => 'monthly',
        'due_day' => 10,
        'start_date' => now()->subMonths(2)->toDateString(),
        'next_due_date' => now()->addDays(4)->toDateString(),
        'is_active' => true,
    ]);

    $second = Subscription::create([
        'user_id' => $user->id,
        'name' => 'Spotify',
        'amount' => 21.90,
        'billing_cycle' => 'monthly',
        'due_day' => 18,
        'start_date' => now()->subMonths(2)->toDateString(),
        'next_due_date' => now()->addDays(8)->toDateString(),
        'is_active' => true,
    ]);

    $state = [
        'last_action' => 'query_subscriptions',
        'last_entities' => [
            'topic' => 'subscriptions',
            'subscription_name' => $first->name,
            'subscription_count' => 2,
            'recent_subscription_ids' => [$first->id, $second->id],
            'subscription_status_filter' => 'active',
        ],
    ];

    $service = app(SubscriptionConversationService::class);

    $more = $service->buildReply($user, 'tem mais assinaturas?', $state);
    $show = $service->buildReply($user, 'me mostra essa assinatura', $state);

    expect($more['reply'])->toContain('2 assinaturas')
        ->and($show['reply'])->toContain('Netflix')
        ->and($show['reply'])->toContain('R$ 39,90');
});

it('supports contextual follow ups for listed savings goals', function () {
    $user = User::factory()->create();

    $first = SavingsGoal::create([
        'user_id' => $user->id,
        'name' => 'Viagem',
        'target_amount' => 5000,
        'target_date' => now()->addMonths(8)->toDateString(),
        'is_completed' => false,
    ]);

    $second = SavingsGoal::create([
        'user_id' => $user->id,
        'name' => 'Reserva',
        'target_amount' => 10000,
        'target_date' => now()->addMonths(12)->toDateString(),
        'is_completed' => false,
    ]);

    $state = [
        'last_action' => 'query_savings',
        'last_entities' => [
            'topic' => 'savings',
            'goal_name' => $first->name,
            'goal_count' => 2,
            'recent_goal_ids' => [$first->id, $second->id],
        ],
    ];

    $service = app(SavingsConversationService::class);

    $more = $service->buildReply($user, 'tem mais metas?', $state);
    $show = $service->buildReply($user, 'me mostra essa meta', $state);

    expect($more['reply'])->toContain('2 metas')
        ->and($show['reply'])->toContain('Viagem')
        ->and($show['reply'])->toContain('R$ 5.000,00');
});
