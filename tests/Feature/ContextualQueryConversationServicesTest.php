<?php

use App\Models\Note;
use App\Models\Reminder;
use App\Models\User;
use App\Services\WhatsApp\NotesConversationService;
use App\Services\WhatsApp\ReminderConversationService;

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
