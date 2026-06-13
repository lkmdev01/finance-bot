<?php

use App\Models\DriveFile;
use App\Models\GoogleDriveConnection;
use App\Models\User;
use App\Services\WhatsApp\DriveConversationService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-06-13 09:30:00'));

    $this->user = User::factory()->create();

    GoogleDriveConnection::create([
        'user_id' => $this->user->id,
        'refresh_token' => 'fake-refresh-token',
        'scopes' => ['https://www.googleapis.com/auth/drive.file'],
        'root_folder_id' => 'root-folder',
        'connected_at' => now(),
        'revoked_at' => null,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('mantem filtro de hoje ao perguntar se tem mais fotos', function () {
    $oldImage = DriveFile::create([
        'user_id' => $this->user->id,
        'source' => 'whatsapp',
        'original_name' => 'imagem-antiga.png',
        'mime_type' => 'image/png',
        'size_bytes' => 1000,
        'sha256' => 'old-image',
        'drive_file_id' => 'old-drive-id',
        'drive_path' => 'Fotos',
        'title' => 'imagem antiga',
        'tags' => [],
        'metadata' => [],
    ]);
    $oldImage->forceFill([
        'created_at' => now()->subDays(10),
        'updated_at' => now()->subDays(10),
    ])->save();

    $service = app(DriveConversationService::class);

    $first = $service->buildReply($this->user->fresh(), 'procura a foto que eu mandei hoje', []);

    expect($first['reply'])->toContain('Nao encontrei arquivos')
        ->and($first['entities']['drive_media_kind'])->toBe('image')
        ->and($first['entities']['drive_time_scope'])->toBe('today');

    $second = $service->buildReply($this->user->fresh(), 'tem mais fotos?', [
        'last_entities' => $first['entities'],
    ]);

    expect($second['reply'])->toContain('Nao encontrei arquivos')
        ->and($second['reply'])->not->toContain('imagem antiga')
        ->and($second['entities']['drive_media_kind'])->toBe('image')
        ->and($second['entities']['drive_time_scope'])->toBe('today');
});
