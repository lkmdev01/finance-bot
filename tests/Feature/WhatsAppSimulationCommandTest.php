<?php

use App\Models\Reminder;
use App\Models\User;
use App\Models\WhatsAppContact;
use Illuminate\Support\Facades\File;

it('simula uma conversa de whatsapp em lote sem enviar ao provedor real', function () {
    $user = User::factory()->create([
        'phone_number' => '5513991290256',
    ]);

    WhatsAppContact::factory()->create([
        'user_id' => $user->id,
        'phone_number' => '5513991290256',
        'conversation_state' => [],
    ]);

    Reminder::query()->create([
        'user_id' => $user->id,
        'title' => 'Beber Agua',
        'message' => 'Lembrete diario: Beber Agua.',
        'frequency' => 'daily',
        'timezone' => config('app.timezone'),
        'next_trigger_at' => now()->addDay()->setTime(8, 30),
        'trigger_time' => '08:30:00',
        'is_active' => true,
    ]);

    $this->artisan('whatsapp:simulate', [
        'user' => (string) $user->id,
        '--message' => [
            'quais sao meus lembretes',
            'me mostra esse lembrete',
        ],
        '--phone' => '5513991290256',
    ])
        ->expectsOutputToContain('Mensagem 1')
        ->expectsOutputToContain('Seus lembretes ativos')
        ->expectsOutputToContain('Mensagem 2')
        ->expectsOutputToContain('Aqui esta o lembrete Beber Agua')
        ->expectsOutputToContain('Estado: action=query_reminders | topic=reminders')
        ->assertSuccessful();
});

it('carrega um transcript json rico, cria midia local e salva o transcript final', function () {
    $user = User::factory()->create([
        'phone_number' => '5513991290256',
    ]);

    WhatsAppContact::factory()->create([
        'user_id' => $user->id,
        'phone_number' => '5513991290256',
        'conversation_state' => [],
    ]);

    Reminder::query()->create([
        'user_id' => $user->id,
        'title' => 'Beber Agua',
        'message' => 'Lembrete diario: Beber Agua.',
        'frequency' => 'daily',
        'timezone' => config('app.timezone'),
        'next_trigger_at' => now()->addDay()->setTime(8, 30),
        'trigger_time' => '08:30:00',
        'is_active' => true,
    ]);

    $fixtureDirectory = storage_path('app/testing');
    File::ensureDirectoryExists($fixtureDirectory);

    $mediaPath = $fixtureDirectory.DIRECTORY_SEPARATOR.'simulated-media.txt';
    $inputPath = $fixtureDirectory.DIRECTORY_SEPARATOR.'simulated-transcript.json';
    $outputPath = $fixtureDirectory.DIRECTORY_SEPARATOR.'simulated-transcript-output.json';

    File::put($mediaPath, 'arquivo local de teste');
    File::put($inputPath, json_encode([
        'entries' => [
            [
                'label' => 'query-reminders',
                'message' => 'quais sao meus lembretes',
                'expected_intent' => 'query_reminders',
                'expected_reply_contains' => ['Seus lembretes ativos'],
            ],
            [
                'label' => 'media-probe',
                'message' => 'oi',
                'media_path' => $mediaPath,
                'media_kind' => 'document',
                'file_name' => 'simulated-media.txt',
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    File::delete($outputPath);

    $this->artisan('whatsapp:simulate', [
        'user' => (string) $user->id,
        '--phone' => '5513991290256',
        '--file' => $inputPath,
        '--transcript-out' => $outputPath,
        '--assert' => true,
    ])
        ->expectsOutputToContain('Mensagem 1')
        ->expectsOutputToContain('Mensagem 2')
        ->expectsOutputToContain('Resultado geral: passou')
        ->assertSuccessful();

    expect(File::exists($outputPath))->toBeTrue();

    $payload = json_decode((string) File::get($outputPath), true);

    expect($payload['all_passed'])->toBeTrue()
        ->and($payload['rounds'][0]['label'])->toBe('query-reminders')
        ->and($payload['rounds'][1]['input']['media_path'])->toBe($mediaPath)
        ->and($payload['rounds'][1]['input']['incoming_media_id'])->not->toBeNull();

    File::delete($mediaPath);
    File::delete($inputPath);
    File::delete($outputPath);
});
