<?php

use App\Console\Commands\SendAgendaDailyDigests;
use App\Models\AgendaDigestPreference;
use App\Models\GoogleCalendarConnection;
use App\Models\User;
use App\Services\AgendaDailySummaryService;
use App\Services\AgendaDigestPreferenceService;
use App\Services\PhoneNumberService;
use App\Services\WhatsApp\WhatsAppReplyDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function digestUserWithCalendar(): User
{
    $user = User::factory()->create(['phone_number' => '5511999999999']);
    GoogleCalendarConnection::create([
        'user_id' => $user->id,
        'google_email' => 'digest@example.test',
        'refresh_token' => Crypt::encryptString('test-refresh-token'),
        'scopes' => [
            'https://www.googleapis.com/auth/calendar.events.owned',
            'https://www.googleapis.com/auth/calendar.events.freebusy',
        ],
        'calendar_id' => 'primary',
        'connected_at' => now(),
    ]);

    return $user->fresh('googleCalendarConnection');
}

it('recognizes opt-in, status, time changes, and opt-out phrases with or without accents', function (): void {
    $service = app(AgendaDigestPreferenceService::class);

    expect($service->handles('me envie um resumo da agenda todo dia às 8'))->toBeTrue()
        ->and($service->handles('status do resumo diario'))->toBeTrue()
        ->and($service->handles('desative o resumo da agenda diária'))->toBeTrue()
        ->and($service->handles('qual horário do resumo diário'))->toBeTrue()
        ->and($service->handles('quanto gastei hoje'))->toBeFalse();
});

it('requires an explicit opt-in and persists the selected time and timezone', function (): void {
    $user = digestUserWithCalendar();
    $service = app(AgendaDigestPreferenceService::class);

    $reply = $service->handle('me envie um resumo da agenda todo dia as 8:30', $user);

    $preference = AgendaDigestPreference::query()->where('user_id', $user->id)->firstOrFail();

    expect($reply)->toContain('08:30 (America/Sao_Paulo)')
        ->and($preference->enabled)->toBeTrue()
        ->and($preference->send_time)->toBe('08:30:00')
        ->and($preference->timezone)->toBe('America/Sao_Paulo');
});

it('lets the user inspect and disable the daily digest', function (): void {
    $user = digestUserWithCalendar();
    $service = app(AgendaDigestPreferenceService::class);
    $service->handle('resumo diario todo dia as 09:15', $user);

    expect($service->handle('status do resumo diario', $user))->toContain('09:15')
        ->and($service->handle('mude o horario do resumo diario para as 10:45', $user))->toContain('10:45')
        ->and(AgendaDigestPreference::query()->where('user_id', $user->id)->value('send_time'))->toBe('10:45:00')
        ->and($service->handle('desative o resumo diario', $user))->toContain('Desativei')
        ->and(AgendaDigestPreference::query()->where('user_id', $user->id)->value('enabled'))->toBeFalse();
});

it('sends at most one digest per local calendar day and does not call AI', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-28 08:05:00', 'America/Sao_Paulo'));
    $user = digestUserWithCalendar();
    $preference = AgendaDigestPreference::create([
        'user_id' => $user->id,
        'enabled' => true,
        'send_time' => '08:00:00',
        'timezone' => 'America/Sao_Paulo',
    ]);

    $summaries = Mockery::mock(AgendaDailySummaryService::class);
    $summaries->shouldReceive('summarize')->once()->with(Mockery::on(fn ($arg) => $arg->is($user)), Mockery::type(CarbonImmutable::class))
        ->andReturn("Resumo de hoje:\nAgenda: sem compromissos.");
    $delivery = Mockery::mock(WhatsAppReplyDelivery::class);
    $delivery->shouldReceive('send')->once()->with('5511999999999@s.whatsapp.net', Mockery::type('string'), $user->id);
    $phoneNumbers = app(PhoneNumberService::class);

    $command = app(SendAgendaDailyDigests::class);
    expect($command->handle($summaries, $phoneNumbers, $delivery))->toBe(SendAgendaDailyDigests::SUCCESS)
        ->and($command->handle($summaries, $phoneNumbers, $delivery))->toBe(SendAgendaDailyDigests::SUCCESS)
        ->and($preference->fresh()->last_sent_for->format('Y-m-d'))->toBe('2026-10-28');
});
