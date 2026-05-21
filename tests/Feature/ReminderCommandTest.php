<?php

use App\Models\Reminder;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\BaileysService;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;

function fakeReminderBaileysSuccessResponse(): Response
{
    return new Response(new Psr7Response(200, [], json_encode(['success' => true])));
}

it('envia lembretes vencidos e avanca o proximo disparo mensal', function () {
    $user = User::factory()->create([
        'phone_number' => '5513991290256',
    ]);

    $contact = WhatsAppContact::factory()->create([
        'user_id' => $user->id,
        'phone_number' => '5513991290256',
    ]);

    $reminder = Reminder::query()->create([
        'user_id' => $user->id,
        'title' => 'Pagar Academia',
        'message' => 'Lembrete do mes: Pagar Academia.',
        'frequency' => 'monthly',
        'timezone' => config('app.timezone'),
        'next_trigger_at' => now()->subMinute(),
        'day_of_month' => 10,
        'is_active' => true,
    ]);

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->with(\Mockery::type('string'), 'Lembrete do mes: Pagar Academia.')
            ->andReturn(fakeReminderBaileysSuccessResponse());
    });

    $this->artisan('reminders:send-due')
        ->assertSuccessful();

    $reminder->refresh();
    $contact->refresh();

    expect($reminder->last_sent_at)->not->toBeNull();
    expect($reminder->next_trigger_at)->not->toBeNull();
    expect($contact->conversation_state['last_proactive_key'] ?? null)->toBe('reminder:'.$reminder->id);
});

it('avanca lembrete diario mantendo horario configurado', function () {
    $user = User::factory()->create([
        'phone_number' => '5513991290257',
    ]);

    WhatsAppContact::factory()->create([
        'user_id' => $user->id,
        'phone_number' => '5513991290257',
    ]);

    $reminder = Reminder::query()->create([
        'user_id' => $user->id,
        'title' => 'Beber Agua',
        'message' => 'Lembrete diario: Beber Agua.',
        'frequency' => 'daily',
        'timezone' => config('app.timezone'),
        'trigger_time' => '08:30:00',
        'next_trigger_at' => now()->subMinute(),
        'is_active' => true,
    ]);

    $this->mock(BaileysService::class, function ($mock) {
        $mock->shouldReceive('sendTextMessage')
            ->once()
            ->with(\Mockery::type('string'), 'Lembrete diario: Beber Agua.')
            ->andReturn(fakeReminderBaileysSuccessResponse());
    });

    $this->artisan('reminders:send-due')
        ->assertSuccessful();

    $reminder->refresh();

    expect($reminder->last_sent_at)->not->toBeNull();
    expect($reminder->next_trigger_at)->not->toBeNull();
    expect($reminder->next_trigger_at->format('H:i:s'))->toBe('08:30:00');
    expect($reminder->next_trigger_at->greaterThan(now()))->toBeTrue();
});
