<?php

use App\Models\DriveFile;
use App\Models\GoogleDriveConnection;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\WhatsApp\ConversationOrchestrator;
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

it('abre arquivo recente por referencia textual', function () {
    $file = DriveFile::create([
        'user_id' => $this->user->id,
        'source' => 'whatsapp',
        'original_name' => '0424.mp3',
        'mime_type' => 'audio/mpeg',
        'drive_file_id' => 'file-42',
        'drive_path' => 'Musicas',
        'title' => '0424',
    ]);

    $state = [
        'last_action' => 'query_drive_files',
        'last_entities' => [
            'topic' => 'drive',
            'drive_file_id' => $file->id,
            'recent_drive_file_ids' => [$file->id],
        ],
    ];

    $data = app(DriveConversationService::class)->buildReply(
        $this->user,
        'abrir o 0424',
        $state
    );

    expect($data['reply'])->toContain('Aqui esta 0424.')
        ->and($data['reply'])->toContain('https://drive.google.com/file/d/file-42/view');
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

it('filtra fotos de hoje sem exigir termo textual literal', function () {
    CarbonImmutable::setTestNow('2026-06-02 10:00:00');

    $image = DriveFile::create([
        'user_id' => $this->user->id,
        'source' => 'whatsapp',
        'original_name' => 'snow-trip.png',
        'mime_type' => 'image/png',
        'drive_file_id' => 'file-img',
        'drive_path' => 'Fotos',
        'title' => 'Imagem',
    ]);
    $image->forceFill([
        'created_at' => CarbonImmutable::now()->subHour(),
        'updated_at' => CarbonImmutable::now()->subHour(),
    ])->save();

    DriveFile::create([
        'user_id' => $this->user->id,
        'source' => 'whatsapp',
        'original_name' => 'contract.pdf',
        'mime_type' => 'application/pdf',
        'drive_file_id' => 'file-doc',
        'drive_path' => 'Documentos',
        'title' => 'Contrato',
    ]);

    $data = app(DriveConversationService::class)->buildReply(
        $this->user,
        'procura a foto que eu mandei hoje',
        ['last_entities' => ['topic' => 'drive']]
    );

    CarbonImmutable::setTestNow();

    expect($data['reply'])->toContain('Arquivos salvos hoje:')
        ->and($data['reply'])->toContain('Imagem')
        ->and($data['reply'])->not->toContain('Contrato')
        ->and($data['entities']['drive_file_id'])->toBe($image->id);
});

it('nao deixa pending de salvar sequestrar consultas de drive', function () {
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

    $decision = app(ConversationOrchestrator::class)->beforeAI(
        'quais arquivos eu tenho no drive?',
        $this->user,
        $this->contact->fresh()
    );

    expect($decision['handled'])->toBeFalse()
        ->and($decision['result']['action'])->toBe('query_drive_files');
});
