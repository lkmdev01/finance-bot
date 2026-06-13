<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Note;
use App\Models\Reminder;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\AIService;
use App\Services\BaileysService;
use App\Services\WhatsApp\NotesConversationService;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

function fakeContextualDomainBaileysSuccessResponse(): Response
{
    return new Response(new Psr7Response(200, [], json_encode(['success' => true])));
}

function runContextualDomainJob(ProcessWhatsAppMessage $job): void
{
    $job->handle(
        app(AIService::class),
        app(BaileysService::class),
        app(\App\Services\PhoneNumberService::class),
        app(\App\Services\PerformanceMetricsService::class)
    );
}

beforeEach(function () {
    $this->user = User::factory()->create([
        'phone_number' => '5513991290256',
    ]);

    $this->contact = WhatsAppContact::factory()->create([
        'user_id' => $this->user->id,
        'phone_number' => '5513991290256',
    ]);
});

it('completa edicao contextual de nota em duas mensagens', function () {
    Http::preventStrayRequests();

    $note = Note::create([
        'user_id' => $this->user->id,
        'title' => 'Projeto Alpha',
        'body' => 'Texto antigo',
        'source' => 'whatsapp',
        'metadata' => [],
    ]);

    $this->contact->update([
        'conversation_state' => [
            'mode' => 'idle',
            'last_action' => 'query_notes',
            'last_entities' => [
                'topic' => 'notes',
                'note_id' => $note->id,
                'note_title' => $note->title,
            ],
        ],
    ]);

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->twice()->andReturn(fakeContextualDomainBaileysSuccessResponse());
    });

    runContextualDomainJob(new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'edita essa nota',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    ));

    $this->contact->refresh();
    expect($this->contact->conversation_state['pending_intent'] ?? null)->toBe('update_note_details');

    runContextualDomainJob(new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'ligar para o contador amanha cedo',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    ));

    expect($note->fresh()?->body)->toBe('ligar para o contador amanha cedo');
});

it('completa exclusao de nota em duas mensagens quando falta o alvo', function () {
    Http::preventStrayRequests();

    $target = Note::create([
        'user_id' => $this->user->id,
        'title' => 'Projeto Alpha',
        'body' => 'Apagar esta',
        'source' => 'whatsapp',
        'metadata' => [],
    ]);

    Note::create([
        'user_id' => $this->user->id,
        'title' => 'Projeto Beta',
        'body' => 'Manter esta',
        'source' => 'whatsapp',
        'metadata' => [],
    ]);

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->twice()->andReturn(fakeContextualDomainBaileysSuccessResponse());
    });

    runContextualDomainJob(new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'apaga a nota',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    ));

    $this->contact->refresh();
    expect($this->contact->conversation_state['pending_intent'] ?? null)->toBe('delete_note_target');

    runContextualDomainJob(new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'Projeto Alpha',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    ));

    expect(Note::find($target->id))->toBeNull();
});

it('completa edicao contextual de lembrete em duas mensagens', function () {
    Http::preventStrayRequests();

    $reminder = Reminder::create([
        'user_id' => $this->user->id,
        'title' => 'Pagar Academia',
        'message' => 'Lembrete mensal: Pagar Academia',
        'frequency' => 'monthly',
        'timezone' => config('app.timezone'),
        'next_trigger_at' => now()->addDays(5),
        'day_of_month' => 5,
        'trigger_time' => '09:00:00',
        'is_active' => true,
    ]);

    $this->contact->update([
        'conversation_state' => [
            'mode' => 'idle',
            'last_action' => 'query_reminders',
            'last_entities' => [
                'topic' => 'reminders',
                'reminder_id' => $reminder->id,
                'reminder_title' => $reminder->title,
            ],
        ],
    ]);

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->twice()->andReturn(fakeContextualDomainBaileysSuccessResponse());
    });

    runContextualDomainJob(new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'edita esse lembrete',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    ));

    $this->contact->refresh();
    expect($this->contact->conversation_state['pending_intent'] ?? null)->toBe('update_reminder_details');

    runContextualDomainJob(new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'amanha as 10:00',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    ));

    $updated = $reminder->fresh();
    expect($updated?->trigger_time)->toBe('10:00:00')
        ->and($updated?->frequency)->toBe('once');
});

it('abre nota pelo titulo explicito em vez da primeira nota recente', function () {
    Http::preventStrayRequests();

    Note::create([
        'user_id' => $this->user->id,
        'title' => 'No Drive',
        'body' => 'no drive',
        'source' => 'whatsapp',
        'metadata' => [],
    ]);

    $target = Note::create([
        'user_id' => $this->user->id,
        'title' => 'Tive Uma Ideia De Função De Gravar Arquivos No Drive Atraves Da Inovafinance',
        'body' => 'tive uma ideia de função de gravar arquivos no drive atraves da inovafinance',
        'source' => 'whatsapp',
        'metadata' => [],
    ]);

    $this->contact->update([
        'conversation_state' => [
            'mode' => 'idle',
            'last_action' => 'query_notes',
            'last_entities' => [
                'topic' => 'notes',
                'note_id' => 1,
                'note_title' => 'No Drive',
                'recent_note_ids' => [$target->id, 1],
                'note_result_count' => 2,
            ],
        ],
    ]);

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->with(\Mockery::type('string'), \Mockery::on(fn (string $message) => str_contains($message, 'Tive Uma Ideia') && ! str_contains($message, "Aqui esta a nota No Drive.\n\nno drive")))
            ->andReturn(fakeContextualDomainBaileysSuccessResponse());
    });

    runContextualDomainJob(new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'me mostra essa nota Tive Uma Ideia De Função De Gravar Arquivos No Drive Atraves Da Inovafinance',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    ));
});

it('abre nota diretamente quando a busca encontra um unico resultado', function () {
    Note::create([
        'user_id' => $this->user->id,
        'title' => 'Projeto Alpha',
        'body' => 'Insight importante sobre o projeto Alpha',
        'source' => 'whatsapp',
        'metadata' => [],
    ]);

    $reply = app(NotesConversationService::class)->buildReply(
        $this->user,
        'me mostra nota Projeto Alpha',
        []
    );

    expect($reply['reply'])->toContain('Aqui esta a nota Projeto Alpha')
        ->and($reply['reply'])->toContain('Insight importante sobre o projeto Alpha')
        ->and($reply['entities']['note_result_count'])->toBe(1);
});

it('abre nota por frase longa mesmo quando a consulta remove conectivos', function () {
    Note::create([
        'user_id' => $this->user->id,
        'title' => 'Tive Uma Ideia De Função De Gravar Arquivos No Drive Atraves Da Inovafinance',
        'body' => 'tive uma ideia de função de gravar arquivos no drive atraves da inovafinance',
        'source' => 'whatsapp',
        'metadata' => [],
    ]);

    $reply = app(NotesConversationService::class)->buildReply(
        $this->user,
        'me mostra nota Tive Uma Ideia De Função De Gravar Arquivos No Drive Atraves Da Inovafinance',
        []
    );

    expect($reply['reply'])->toContain('Aqui esta a nota Tive Uma Ideia')
        ->and($reply['reply'])->toContain('gravar arquivos no drive');
});

it('lista notas numeradas e abre nota pelo numero', function () {
    $first = Note::create([
        'user_id' => $this->user->id,
        'title' => 'Projeto Alpha',
        'body' => 'Texto Alpha',
        'source' => 'whatsapp',
        'metadata' => [],
    ]);

    $second = Note::create([
        'user_id' => $this->user->id,
        'title' => 'Projeto Beta',
        'body' => 'Texto Beta',
        'source' => 'whatsapp',
        'metadata' => [],
    ]);

    $service = app(NotesConversationService::class);
    $list = $service->buildReply($this->user, 'minhas notas', []);

    expect($list['reply'])->toContain('1.')
        ->and($list['reply'])->toContain('2.')
        ->and($list['entities']['recent_note_ids'])->toBe([$second->id, $first->id]);

    $opened = $service->buildReply($this->user, 'abrir nota 2', [
        'last_entities' => $list['entities'],
    ]);

    expect($opened['reply'])->toContain('Aqui esta a nota Projeto Alpha')
        ->and($opened['reply'])->toContain('Texto Alpha');
});

it('completa exclusao de lembrete em duas mensagens quando falta o alvo', function () {
    Http::preventStrayRequests();

    $target = Reminder::create([
        'user_id' => $this->user->id,
        'title' => 'Tomar Agua',
        'message' => 'Lembrete diario: Tomar Agua',
        'frequency' => 'daily',
        'timezone' => config('app.timezone'),
        'next_trigger_at' => now()->addDay(),
        'trigger_time' => '09:00:00',
        'is_active' => true,
    ]);

    Reminder::create([
        'user_id' => $this->user->id,
        'title' => 'Ler Livro',
        'message' => 'Lembrete diario: Ler Livro',
        'frequency' => 'daily',
        'timezone' => config('app.timezone'),
        'next_trigger_at' => now()->addDay(),
        'trigger_time' => '09:00:00',
        'is_active' => true,
    ]);

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->twice()->andReturn(fakeContextualDomainBaileysSuccessResponse());
    });

    runContextualDomainJob(new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'apaga o lembrete',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    ));

    $this->contact->refresh();
    expect($this->contact->conversation_state['pending_intent'] ?? null)->toBe('delete_reminder_target');

    runContextualDomainJob(new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'Tomar Agua',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    ));

    expect($target->fresh()?->is_active)->toBeFalse();
});

it('permite cancelar uma pendencia de salvar no drive', function () {
    Http::preventStrayRequests();

    $this->contact->update([
        'conversation_state' => [
            'mode' => 'awaiting_clarification',
            'pending_intent' => 'drive_save_waiting_media',
            'pending_payload' => ['drive_data' => []],
            'last_entities' => [
                'topic' => 'drive',
            ],
        ],
    ]);

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeContextualDomainBaileysSuccessResponse());
    });

    runContextualDomainJob(new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'cancela isso',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    ));

    $this->contact->refresh();
    expect($this->contact->conversation_state['pending_intent'] ?? null)->toBeNull();
});

it('limpa contexto de notas quando recebe gratidao', function () {
    Http::preventStrayRequests();

    $note = Note::create([
        'user_id' => $this->user->id,
        'title' => 'Projeto Alpha',
        'body' => 'Texto antigo',
        'source' => 'whatsapp',
        'metadata' => [],
    ]);

    $this->contact->update([
        'conversation_state' => [
            'mode' => 'idle',
            'last_action' => 'query_notes',
            'last_entities' => [
                'topic' => 'notes',
                'note_id' => $note->id,
                'note_title' => $note->title,
            ],
        ],
    ]);

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeContextualDomainBaileysSuccessResponse());
    });

    runContextualDomainJob(new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'obrigado',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    ));

    $this->contact->refresh();
    expect($this->contact->conversation_state['last_entities']['topic'] ?? null)->toBe('general');
});

it('limpa contexto de planejamento quando recebe pedido de ajuda', function () {
    Http::preventStrayRequests();

    $this->contact->update([
        'conversation_state' => [
            'mode' => 'idle',
            'last_action' => 'query_subscriptions',
            'last_entities' => [
                'topic' => 'subscriptions',
                'subscription_name' => 'Netflix',
            ],
        ],
    ]);

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')->once()->andReturn(fakeContextualDomainBaileysSuccessResponse());
    });

    runContextualDomainJob(new ProcessWhatsAppMessage(
        phoneNumber: '5513991290256',
        message: 'como voce pode me ajudar',
        userId: $this->user->id,
        pushName: 'Test User',
        remoteJid: '5513991290256@s.whatsapp.net'
    ));

    $this->contact->refresh();
    expect($this->contact->conversation_state['last_entities']['topic'] ?? null)->toBe('general');
});
