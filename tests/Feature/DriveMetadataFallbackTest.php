<?php

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\DriveFile;
use App\Models\GoogleDriveConnection;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppIncomingMedia;
use App\Services\BaileysService;
use App\Services\BillingPlanService;
use App\Services\GoogleDriveService;
use App\Services\OCRService;
use App\Services\WhatsApp\DriveAIMetadataService;
use App\Services\WhatsApp\DriveConversationService;
use App\Services\WhatsApp\Handlers\CreateDriveFileHandler;
use App\Services\WhatsApp\SimulatedBaileysTransport;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->create([
        'phone_number' => '5513991290256',
        'whatsapp_verified_at' => now(),
    ]);

    $this->contact = WhatsAppContact::factory()->create([
        'user_id' => $this->user->id,
        'phone_number' => '5513991290256',
        'conversation_state' => [],
    ]);

    GoogleDriveConnection::create([
        'user_id' => $this->user->id,
        'refresh_token' => 'fake-refresh-token',
        'scopes' => ['https://www.googleapis.com/auth/drive.file'],
        'root_folder_id' => 'root-folder-id',
        'connected_at' => now(),
    ]);

    app()->instance(BaileysService::class, new SimulatedBaileysTransport);

    $this->mock(BillingPlanService::class, function ($mock) {
        $mock->shouldReceive('userCanCreateRecords')->andReturnTrue();
    });

    $this->mock(GoogleDriveService::class, function ($mock) {
        $mock->shouldReceive('ensureRootFolder')->andReturn('root-folder-id');
        $mock->shouldReceive('ensureFolderPath')->andReturn([
            'folder_id' => 'folder-fotos',
            'drive_path' => 'Fotos',
        ]);
        $mock->shouldReceive('uploadFileFromPath')->andReturn([
            'id' => 'drive-file-id',
            'name' => 'imagem.jpg',
        ]);
        $mock->shouldReceive('buildFileWebUrl')->andReturn('https://drive.google.com/file/d/drive-file-id/view');
    });

    $this->mock(OCRService::class, function ($mock) {
        $mock->shouldReceive('analyzeImageFromFile')->andReturn([
            'text' => '',
            'labels' => ['foto'],
        ]);
    });
});

it('salva imagem com metadata completed e informa analise no WhatsApp', function () {
    $media = createIncomingImage($this->user);

    $this->mock(DriveAIMetadataService::class, function ($mock) {
        $mock->shouldReceive('analyze')->once()->andReturn([
            'description' => 'Foto de uma pessoa em uma viagem na neve.',
            'tags' => ['foto', 'neve', 'viagem'],
            'source' => 'groq_vision',
            'status' => 'completed',
            'error' => null,
        ]);
    });

    $job = runDriveHandler($this->user, $this->contact, $media);
    $file = DriveFile::query()->latest('id')->first();

    expect($file)->not->toBeNull()
        ->and($file->metadata_status)->toBe('completed')
        ->and($file->metadata_error)->toBeNull()
        ->and($file->metadata_analyzed_at)->not->toBeNull()
        ->and($file->description)->toContain('viagem na neve')
        ->and($file->tags)->toContain('neve')
        ->and($job->getFinalReply())->toContain('Analisei o conteudo')
        ->and($job->getFinalReply())->toContain('viagem na neve');
});

it('salva imagem mesmo quando metadata falha e registra fallback observavel', function () {
    $media = createIncomingImage($this->user);

    $this->mock(DriveAIMetadataService::class, function ($mock) {
        $mock->shouldReceive('analyze')->once()->andReturn([
            'description' => null,
            'tags' => [],
            'source' => null,
            'status' => 'failed',
            'error' => 'groq metadata error: rate limit',
        ]);
    });

    $job = runDriveHandler($this->user, $this->contact, $media);
    $file = DriveFile::query()->latest('id')->first();

    expect($file)->not->toBeNull()
        ->and($file->metadata_status)->toBe('failed')
        ->and($file->metadata_error)->toContain('rate limit')
        ->and($file->metadata_analyzed_at)->not->toBeNull()
        ->and($job->getFinalReply())->toContain('Salvei normalmente')
        ->and($job->getFinalReply())->toContain('nao consegui analisar');
});

it('busca por nome pasta tipo e data mesmo sem metadata de IA', function () {
    $file = DriveFile::create([
        'user_id' => $this->user->id,
        'source' => 'whatsapp',
        'original_name' => 'contrato-aluguel.pdf',
        'mime_type' => 'application/pdf',
        'drive_file_id' => 'fallback-document',
        'drive_path' => 'Documentos/Contratos',
        'title' => 'Contrato Aluguel',
        'description' => 'Documento salvo sem analise por IA',
        'tags' => ['contrato', 'aluguel', 'documento'],
        'metadata_status' => 'unavailable',
        'metadata' => [],
    ]);

    $data = app(DriveConversationService::class)->buildReply(
        $this->user->fresh(),
        'busca o contrato de aluguel',
        ['last_entities' => ['topic' => 'drive']]
    );

    expect($data['reply'])->toContain('Contrato Aluguel')
        ->and($data['entities']['drive_file_id'])->toBe($file->id);
});

it('mostra observabilidade de metadata failed e permite marcar como pending', function () {
    DriveFile::create([
        'user_id' => $this->user->id,
        'source' => 'whatsapp',
        'original_name' => 'imagem.jpg',
        'mime_type' => 'image/jpeg',
        'drive_file_id' => 'failed-image',
        'title' => 'Imagem',
        'metadata_status' => 'failed',
        'metadata_error' => 'groq metadata error',
        'metadata' => [],
    ]);

    $this->artisan('drive:metadata-report', ['--failed' => true])
        ->expectsOutputToContain('- failed: 1')
        ->expectsOutputToContain('Imagem')
        ->assertSuccessful();

    expect(DriveFile::query()->first()?->metadata_error)->toContain('groq metadata error');

    $this->artisan('drive:metadata-report', ['--reset-failed' => true])
        ->expectsOutputToContain('Arquivos marcados como pending: 1')
        ->expectsOutputToContain('- pending: 1')
        ->assertSuccessful();

    expect(DriveFile::query()->first()?->metadata_status)->toBe('pending');
});

function createIncomingImage(User $user): WhatsAppIncomingMedia
{
    Storage::disk('local')->put('testing/incoming/imagem.jpg', 'fake-image');

    return WhatsAppIncomingMedia::create([
        'user_id' => $user->id,
        'phone_number' => '5513991290256',
        'kind' => 'image',
        'storage_disk' => 'local',
        'storage_path' => 'testing/incoming/imagem.jpg',
        'original_name' => 'imagem.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 10,
        'sha256' => hash('sha256', 'fake-image'),
        'received_at' => now(),
        'metadata' => [],
    ]);
}

function runDriveHandler(User $user, WhatsAppContact $contact, WhatsAppIncomingMedia $media): ProcessWhatsAppMessage
{
    $job = new ProcessWhatsAppMessage(
        phoneNumber: $contact->phone_number,
        message: 'salva essa foto',
        userId: $user->id,
        incomingMediaId: $media->id,
    );

    $result = [
        'drive_data' => [
            'incoming_media_id' => $media->id,
            'folder_hint' => 'Fotos',
        ],
    ];

    app(CreateDriveFileHandler::class)->handle('create_drive_file', $result, $user->fresh(), $contact->fresh(), $job);

    return $job;
}
