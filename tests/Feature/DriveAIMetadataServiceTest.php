<?php

use App\Services\WhatsApp\DriveAIMetadataService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('ai.provider', 'groq');
    config()->set('ai.api_key', 'test-groq-key');
    config()->set('ai.drive_metadata.provider', 'groq');
    config()->set('ai.drive_metadata.api_key', 'test-groq-key');
    config()->set('ai.drive_metadata.vision_model', 'meta-llama/llama-4-scout-17b-16e-instruct');
    config()->set('ai.drive_metadata.metadata_model', 'llama-3.1-8b-instant');
});

it('gera metadados humanos para imagens usando visao com groq', function () {
    Http::fake([
        'api.groq.com/openai/v1/chat/completions' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'description' => 'Foto de uma viagem na neve com montanhas ao fundo.',
                            'tags' => ['foto', 'neve', 'montanha', 'viagem'],
                        ]),
                    ],
                ],
            ],
        ]),
    ]);

    $path = tempnam(sys_get_temp_dir(), 'drive-ai-image-');
    file_put_contents($path, 'fake-image-binary');

    try {
        $metadata = app(DriveAIMetadataService::class)->analyze(
            kind: 'image',
            localPath: $path,
            mimeType: 'image/png',
            fileName: 'IMG_2042.png',
            folderName: 'Fotos / Viagens',
            extractedText: null,
            labels: ['Snow', 'Mountain'],
        );
    } finally {
        @unlink($path);
    }

    expect($metadata['description'])->toBe('Foto de uma viagem na neve com montanhas ao fundo.')
        ->and($metadata['tags'])->toContain('neve')
        ->and($metadata['source'])->toBe('groq_vision');

    Http::assertSent(function ($request) {
        $payload = $request->data();
        $content = $payload['messages'][1]['content'] ?? [];
        $image = $content[1]['image_url']['url'] ?? '';

        return $request->url() === 'https://api.groq.com/openai/v1/chat/completions'
            && $payload['model'] === 'meta-llama/llama-4-scout-17b-16e-instruct'
            && str_starts_with($image, 'data:image/png;base64,');
    });
});

it('gera metadados humanos para audios usando transcricao com groq', function () {
    Http::fake([
        'api.groq.com/openai/v1/chat/completions' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'description' => 'Audio com ideias sobre o projeto de expansao.',
                            'tags' => ['audio', 'projeto', 'ideia', 'expansao'],
                        ]),
                    ],
                ],
            ],
        ]),
    ]);

    $metadata = app(DriveAIMetadataService::class)->analyze(
        kind: 'audio',
        localPath: null,
        mimeType: 'audio/mpeg',
        fileName: 'audio_001.mp3',
        folderName: 'Audios / Projetos',
        extractedText: 'Tive uma ideia para o projeto de expansao da InovaFinance.',
        labels: [],
    );

    expect($metadata['description'])->toBe('Audio com ideias sobre o projeto de expansao.')
        ->and($metadata['tags'])->toContain('projeto')
        ->and($metadata['source'])->toBe('groq_text');
});

it('continua suportando openai como provider opcional', function () {
    config()->set('ai.drive_metadata.provider', 'openai');
    config()->set('ai.drive_metadata.api_key', 'test-openai-key');
    config()->set('ai.drive_metadata.metadata_model', 'gpt-4o-mini');

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'description' => 'Documento de teste.',
                            'tags' => ['documento', 'teste'],
                        ]),
                    ],
                ],
            ],
        ]),
    ]);

    $metadata = app(DriveAIMetadataService::class)->analyze(
        kind: 'document',
        localPath: null,
        mimeType: 'application/pdf',
        fileName: 'teste.pdf',
        folderName: 'Documentos',
        extractedText: 'Documento de teste.',
        labels: [],
    );

    expect($metadata['source'])->toBe('openai_text')
        ->and($metadata['tags'])->toContain('teste');
});

it('nao chama ia quando nao esta configurado', function () {
    config()->set('ai.provider', 'groq');
    config()->set('ai.api_key', 'test-groq-key');
    config()->set('ai.drive_metadata.provider', 'none');
    config()->set('ai.drive_metadata.api_key', null);
    Http::fake();

    $metadata = app(DriveAIMetadataService::class)->analyze(
        kind: 'audio',
        localPath: null,
        mimeType: 'audio/mpeg',
        fileName: 'audio_001.mp3',
        folderName: null,
        extractedText: 'Texto qualquer',
        labels: [],
    );

    expect($metadata['description'])->toBeNull()
        ->and($metadata['tags'])->toBe([])
        ->and($metadata['source'])->toBeNull();

    Http::assertNothingSent();
});
