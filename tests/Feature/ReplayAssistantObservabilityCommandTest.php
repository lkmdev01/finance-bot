<?php

use App\Models\User;
use App\Models\WhatsAppConversationLog;
use Illuminate\Support\Facades\File;

it('replays assistant observability backlog against a local user and writes a transcript', function () {
    $user = User::factory()->create([
        'phone_number' => '5513991290256',
    ]);

    WhatsAppConversationLog::query()->create([
        'user_id' => $user->id,
        'phone_number' => '5513991290256',
        'message' => 'anota',
        'classification' => 'note_needs_content',
        'action' => null,
        'used_ai' => false,
        'status' => 'handled_preflight',
        'reply' => 'O que voce quer que eu salve?',
        'metadata' => [
            'assistant_intent' => 'create_note',
            'assistant_domain' => 'notes',
            'assistant_missing_fields' => ['content'],
        ],
    ]);

    $outputPath = storage_path('app/testing/replay-observability.json');
    File::delete($outputPath);

    $this->artisan('assistant:replay-observability', [
        'user' => (string) $user->id,
        '--phone' => '5513991290256',
        '--focus' => 'missing',
        '--domain' => 'notes',
        '--limit' => 1,
        '--assert' => true,
        '--output' => $outputPath,
    ])
        ->expectsOutputToContain('Replays encontrados: 1')
        ->expectsOutputToContain('Mensagem 1')
        ->expectsOutputToContain('Assert: passou')
        ->assertSuccessful();

    expect(File::exists($outputPath))->toBeTrue();

    $payload = json_decode((string) File::get($outputPath), true);

    expect($payload['source'])->toBe('assistant_observability')
        ->and($payload['all_passed'])->toBeTrue()
        ->and($payload['rounds'][0]['label'])->toBe('notes:create_note')
        ->and($payload['rounds'][0]['assertions']['passed'])->toBeTrue();

    File::delete($outputPath);
});
