<?php

use App\Models\DriveFile;
use App\Models\GoogleDriveConnection;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\WhatsApp\DriveConversationService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->contact = WhatsAppContact::factory()->create([
        'user_id' => $this->user->id,
        'phone_number' => '5513991290256',
    ]);

    GoogleDriveConnection::create([
        'user_id' => $this->user->id,
        'refresh_token' => 'fake-refresh-token',
        'scopes' => ['https://www.googleapis.com/auth/drive.file'],
        'connected_at' => now(),
    ]);
});

it('lista arquivos recentes quando a pergunta e generica', function () {
    DriveFile::create([
        'user_id' => $this->user->id,
        'source' => 'whatsapp',
        'original_name' => 'imagem.png',
        'mime_type' => 'image/png',
        'drive_file_id' => 'file-1',
        'drive_path' => 'Fotos',
        'title' => 'Imagem',
    ]);

    DriveFile::create([
        'user_id' => $this->user->id,
        'source' => 'whatsapp',
        'original_name' => 'contrato.pdf',
        'mime_type' => 'application/pdf',
        'drive_file_id' => 'file-2',
        'drive_path' => 'Contratos',
        'title' => 'Contrato aluguel',
    ]);

    $data = app(DriveConversationService::class)->buildReply(
        $this->user,
        'quais arquivos eu tenho no drive?',
        []
    );

    expect($data['reply'])->toContain('Seus arquivos recentes:')
        ->and($data['reply'])->toContain('Contrato aluguel')
        ->and($data['entities']['recent_drive_file_ids'])->toHaveCount(2);
});

it('responde follow up com a pasta do ultimo arquivo salvo', function () {
    $file = DriveFile::create([
        'user_id' => $this->user->id,
        'source' => 'whatsapp',
        'original_name' => 'identidade.pdf',
        'mime_type' => 'application/pdf',
        'drive_file_id' => 'file-3',
        'drive_path' => 'Documentos/Pessoais',
        'title' => 'Identidade',
    ]);

    $state = [
        'last_action' => 'create_drive_file',
        'last_entities' => [
            'topic' => 'drive',
            'drive_file_id' => $file->id,
            'drive_file_title' => 'Identidade',
            'drive_path' => 'Documentos/Pessoais',
        ],
    ];

    $data = app(DriveConversationService::class)->buildReply(
        $this->user,
        'em qual pasta ficou?',
        $state
    );

    expect($data['reply'])->toContain('Documentos/Pessoais')
        ->and($data['entities']['drive_file_id'])->toBe($file->id);
});

it('filtra arquivos salvos hoje usando o contexto temporal', function () {
    CarbonImmutable::setTestNow('2026-06-02 10:00:00');

    $today = DriveFile::create([
        'user_id' => $this->user->id,
        'source' => 'whatsapp',
        'original_name' => 'foto-hoje.png',
        'mime_type' => 'image/png',
        'drive_file_id' => 'file-4',
        'drive_path' => 'Fotos',
        'title' => 'Foto hoje',
    ]);
    $today->forceFill([
        'created_at' => CarbonImmutable::now()->subHour(),
        'updated_at' => CarbonImmutable::now()->subHour(),
    ])->save();

    $yesterday = DriveFile::create([
        'user_id' => $this->user->id,
        'source' => 'whatsapp',
        'original_name' => 'foto-ontem.png',
        'mime_type' => 'image/png',
        'drive_file_id' => 'file-5',
        'drive_path' => 'Fotos',
        'title' => 'Foto ontem',
    ]);
    $yesterday->forceFill([
        'created_at' => CarbonImmutable::now()->subDay(),
        'updated_at' => CarbonImmutable::now()->subDay(),
    ])->save();

    $data = app(DriveConversationService::class)->buildReply(
        $this->user,
        'quais arquivos eu salvei hoje?',
        ['last_entities' => ['topic' => 'drive']]
    );

    CarbonImmutable::setTestNow();

    expect($data['reply'])->toContain('Arquivos salvos hoje:')
        ->and($data['reply'])->toContain('Foto hoje')
        ->and($data['reply'])->not->toContain('Foto ontem')
        ->and($data['entities']['drive_file_id'])->toBe($today->id);
});
