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

    expect($first['reply'])->toContain('Nao encontrei fotos salvas hoje')
        ->and($first['reply'])->toContain('procurar em outros dias')
        ->and($first['entities']['drive_media_kind'])->toBe('image')
        ->and($first['entities']['drive_time_scope'])->toBe('today');

    $second = $service->buildReply($this->user->fresh(), 'tem mais fotos?', [
        'last_entities' => $first['entities'],
    ]);

    expect($second['reply'])->toContain('Nao encontrei fotos salvas hoje')
        ->and($second['reply'])->toContain('listar todos os arquivos recentes')
        ->and($second['reply'])->not->toContain('imagem antiga')
        ->and($second['entities']['drive_media_kind'])->toBe('image')
        ->and($second['entities']['drive_time_scope'])->toBe('today');
});

it('ranqueia arquivos por significado usando tipo, tags, descricao e texto extraido', function () {
    DriveFile::create([
        'user_id' => $this->user->id,
        'source' => 'whatsapp',
        'original_name' => 'comprovante_mecanico.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 285500,
        'sha256' => hash('sha256', 'semantic-comprovante-mecanico'),
        'drive_file_id' => 'semantic-drive-file-1',
        'drive_path' => 'Comprovantes / Veiculos',
        'title' => 'comprovante_mecanico',
        'description' => 'Comprovante do mecanico de marco',
        'tags' => ['comprovante', 'veiculo'],
        'extracted_text' => 'servico mecanico realizado na oficina',
        'metadata' => [],
    ]);

    DriveFile::create([
        'user_id' => $this->user->id,
        'source' => 'whatsapp',
        'original_name' => 'foto_neve.png',
        'mime_type' => 'image/png',
        'size_bytes' => 91500,
        'sha256' => hash('sha256', 'semantic-foto-neve'),
        'drive_file_id' => 'semantic-drive-file-2',
        'drive_path' => 'Fotos / Viagens',
        'title' => 'foto_neve',
        'description' => 'Foto na neve durante a viagem',
        'tags' => ['foto', 'neve', 'viagem'],
        'extracted_text' => 'paisagem com neve e montanha',
        'metadata' => [],
    ]);

    DriveFile::create([
        'user_id' => $this->user->id,
        'source' => 'whatsapp',
        'original_name' => 'audio_projeto.mp3',
        'mime_type' => 'audio/mpeg',
        'size_bytes' => 2048000,
        'sha256' => hash('sha256', 'semantic-audio-projeto'),
        'drive_file_id' => 'semantic-drive-file-3',
        'drive_path' => 'Audios / Projetos',
        'title' => 'audio_projeto',
        'description' => 'Audio com ideias sobre o projeto',
        'tags' => ['audio', 'projeto', 'ideias'],
        'extracted_text' => 'brainstorm do projeto de expansao',
        'metadata' => [],
    ]);

    DriveFile::create([
        'user_id' => $this->user->id,
        'source' => 'whatsapp',
        'original_name' => 'contrato_aluguel.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 128000,
        'sha256' => hash('sha256', 'semantic-contrato-aluguel'),
        'drive_file_id' => 'semantic-drive-file-4',
        'drive_path' => 'Documentos / Contratos',
        'title' => 'contrato_aluguel',
        'description' => 'Contrato do apartamento',
        'tags' => ['contrato'],
        'extracted_text' => 'contrato de locacao residencial',
        'metadata' => [],
    ]);

    $service = app(DriveConversationService::class);

    $photo = $service->buildReply($this->user->fresh(), 'ache minha foto na neve', []);
    expect($photo['reply'])->toContain('foto_neve')
        ->and($photo['reply'])->not->toContain('audio_projeto');

    $receipt = $service->buildReply($this->user->fresh(), 'procura o comprovante do mecanico', []);
    expect($receipt['reply'])->toContain('comprovante_mecanico')
        ->and($receipt['reply'])->not->toContain('foto_neve');

    $audio = $service->buildReply($this->user->fresh(), 'encontra o audio sobre o projeto', []);
    expect($audio['reply'])->toContain('audio_projeto')
        ->and($audio['reply'])->not->toContain('contrato_aluguel');

    $contract = $service->buildReply($this->user->fresh(), 'busca o contrato que eu salvei', []);
    expect($contract['reply'])->toContain('contrato_aluguel')
        ->and($contract['reply'])->not->toContain('audio_projeto');
});

it('prioriza siglas fiscais no nome do arquivo em buscas de hoje', function () {
    DriveFile::create([
        'user_id' => $this->user->id,
        'source' => 'whatsapp',
        'original_name' => 'imagem.png',
        'mime_type' => 'image/png',
        'size_bytes' => 1000,
        'sha256' => hash('sha256', 'das-search-image'),
        'drive_file_id' => 'das-search-image',
        'drive_path' => 'drive em fotos',
        'title' => 'imagem',
        'description' => 'Imagem enviada hoje',
        'tags' => ['imagem', 'foto'],
        'metadata' => [],
    ]);

    $das = DriveFile::create([
        'user_id' => $this->user->id,
        'source' => 'whatsapp',
        'original_name' => 'DAS-PGMEI-64365816000100-AC2026-3.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 1000,
        'sha256' => hash('sha256', 'das-search-document'),
        'drive_file_id' => 'das-search-document',
        'drive_path' => 'drive Mei comprovante',
        'title' => 'DAS-PGMEI-64365816000100-AC2026-3',
        'description' => 'Documento DAS PGMEI de MEI',
        'tags' => ['das', 'pgmei', 'mei', 'comprovante'],
        'extracted_text' => 'Documento de Arrecadacao do Simples Nacional DAS PGMEI',
        'metadata' => [],
    ]);

    $data = app(DriveConversationService::class)->buildReply(
        $this->user->fresh(),
        'Ache no drive a DAS que mandei hoje',
        ['last_entities' => ['topic' => 'drive']]
    );

    expect($data['reply'])->toContain('Arquivos salvos hoje:')
        ->and($data['reply'])->toContain('1. DAS-PGMEI-64365816000100-AC2026-3')
        ->and($data['entities']['drive_file_id'])->toBe($das->id)
        ->and($data['entities']['drive_query_term'])->toBe('das');
});

it('nao retorna fotos genericas quando a busca pede um termo semantico especifico', function () {
    DriveFile::create([
        'user_id' => $this->user->id,
        'source' => 'whatsapp',
        'original_name' => 'imagem.png',
        'mime_type' => 'image/png',
        'size_bytes' => 1000,
        'sha256' => hash('sha256', 'generic-image'),
        'drive_file_id' => 'generic-image',
        'drive_path' => 'drive em fotos',
        'title' => 'imagem',
        'description' => 'Imagem enviada hoje',
        'tags' => ['imagem', 'foto'],
        'metadata' => [],
    ]);

    $data = app(DriveConversationService::class)->buildReply(
        $this->user->fresh(),
        'ache minha foto na neve',
        ['last_entities' => ['topic' => 'drive']]
    );

    expect($data['reply'])->toContain('Nao encontrei fotos sobre "neve"')
        ->and($data['reply'])->not->toContain('imagem')
        ->and($data['entities']['drive_query_term'])->toBe('neve')
        ->and($data['entities']['drive_media_kind'])->toBe('image');
});
