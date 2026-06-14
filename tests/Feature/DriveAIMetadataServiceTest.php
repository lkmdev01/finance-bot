<?php

use App\Services\WhatsApp\DriveAIMetadataService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('ai.provider', 'openai');
    config()->set('ai.api_key', 'test-openai-key');
    config()->set('ai.openai.vision_model', 'gpt-4o-mini');
    config()->set('ai.openai.metadata_model', 'gpt-4o-mini');
});

it('gera metadados humanos para imagens usando visao', function () {
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response([
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
        ->and($metadata['source'])->toBe('openai_vision');

    Http::assertSent(function ($request) {
        $payload = $request->data();
        $content = $payload['messages'][1]['content'] ?? [];
        $image = $content[1]['image_url']['url'] ?? '';

        return $payload['model'] === 'gpt-4o-mini'
            && str_starts_with($image, 'data:image/png;base64,');
    });
});

it('gera metadados humanos para audios usando transcricao', function () {
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response([
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
        ->and($metadata['source'])->toBe('openai_text');
});

it('nao chama ia quando nao esta configurado', function () {
    config()->set('ai.provider', 'groq');
    config()->set('ai.api_key', null);
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
