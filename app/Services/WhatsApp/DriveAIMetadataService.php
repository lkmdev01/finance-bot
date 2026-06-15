<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DriveAIMetadataService
{
    /**
     * @param  array<int, string>  $labels
     * @return array{description: string|null, tags: array<int, string>, source: string|null}
     */
    public function analyze(
        string $kind,
        ?string $localPath,
        ?string $mimeType,
        string $fileName,
        ?string $folderName,
        ?string $extractedText,
        array $labels = [],
    ): array {
        if (! $this->isAvailable()) {
            return $this->empty();
        }

        try {
            return match ($kind) {
                'image' => $this->analyzeImage($localPath, $mimeType, $fileName, $folderName, $extractedText, $labels),
                'audio' => $this->analyzeText('audio', $fileName, $folderName, $extractedText, $labels),
                default => $this->analyzeText('documento', $fileName, $folderName, $extractedText, $labels),
            };
        } catch (\Throwable $throwable) {
            Log::warning('Drive AI metadata indisponivel.', [
                'kind' => $kind,
                'file_name' => $fileName,
                'error' => $throwable->getMessage(),
            ]);

            return $this->empty();
        }
    }

    /**
     * @param  array<int, string>  $labels
     * @return array{description: string|null, tags: array<int, string>, source: string|null}
     */
    private function analyzeImage(?string $localPath, ?string $mimeType, string $fileName, ?string $folderName, ?string $extractedText, array $labels): array
    {
        if (! $localPath || ! is_file($localPath)) {
            return $this->empty();
        }

        $binary = file_get_contents($localPath);
        if ($binary === false || $binary === '') {
            return $this->empty();
        }

        $mimeType = $mimeType ?: 'image/jpeg';
        $dataUrl = 'data:'.$mimeType.';base64,'.base64_encode($binary);

        $response = $this->postChatCompletion((string) config('ai.drive_metadata.vision_model'), [
            [
                'role' => 'system',
                'content' => $this->systemPrompt(),
            ],
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $this->contextPrompt('imagem', $fileName, $folderName, $extractedText, $labels),
                    ],
                    [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => $dataUrl,
                            'detail' => 'low',
                        ],
                    ],
                ],
            ],
        ]);

        return $this->parseResponse($response, 'openai_vision');
    }

    /**
     * @param  array<int, string>  $labels
     * @return array{description: string|null, tags: array<int, string>, source: string|null}
     */
    private function analyzeText(string $kindLabel, string $fileName, ?string $folderName, ?string $extractedText, array $labels): array
    {
        $text = trim((string) $extractedText);
        if ($text === '' && $labels === []) {
            return $this->empty();
        }

        $response = $this->postChatCompletion((string) config('ai.drive_metadata.metadata_model'), [
            [
                'role' => 'system',
                'content' => $this->systemPrompt(),
            ],
            [
                'role' => 'user',
                'content' => $this->contextPrompt($kindLabel, $fileName, $folderName, $text, $labels),
            ],
        ]);

        return $this->parseResponse($response, 'openai_text');
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     */
    private function postChatCompletion(string $model, array $messages): array
    {
        $response = Http::withToken((string) config('ai.drive_metadata.api_key'))
            ->acceptJson()
            ->timeout(30)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'messages' => $messages,
                'temperature' => 0.2,
                'max_tokens' => 350,
                'response_format' => ['type' => 'json_object'],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('OpenAI metadata error: '.$response->body());
        }

        return $response->json();
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Voce gera metadados de busca para um Drive pessoal em portugues do Brasil.
Responda apenas JSON valido com:
{
  "description": "uma descricao curta, humana e pesquisavel do arquivo",
  "tags": ["5 a 12 tags curtas em portugues, sem hashtag"]
}
Nao invente dados sensiveis. Seja descritivo, util para busca semantica e conciso.
PROMPT;
    }

    /**
     * @param  array<int, string>  $labels
     */
    private function contextPrompt(string $kindLabel, string $fileName, ?string $folderName, ?string $extractedText, array $labels): string
    {
        $text = Str::limit(Str::squish((string) $extractedText), 1800, '');
        $labelsText = $labels !== [] ? implode(', ', array_slice($labels, 0, 12)) : 'nenhuma';
        $folder = $folderName ?: 'nao informada';

        return <<<PROMPT
Tipo: {$kindLabel}
Nome do arquivo: {$fileName}
Pasta sugerida: {$folder}
Labels/OCR atuais: {$labelsText}
Texto/transcricao extraida: {$text}

Gere uma descricao e tags para que o usuario encontre esse arquivo depois com frases naturais, por exemplo:
- "ache minha foto na neve"
- "procura o comprovante do mecanico"
- "encontra o audio sobre o projeto"
PROMPT;
    }

    /**
     * @return array{description: string|null, tags: array<int, string>, source: string|null}
     */
    private function parseResponse(array $response, string $source): array
    {
        $content = trim((string) data_get($response, 'choices.0.message.content', ''));
        if ($content === '') {
            return $this->empty();
        }

        $payload = json_decode($content, true);
        if (! is_array($payload)) {
            return $this->empty();
        }

        $description = trim((string) ($payload['description'] ?? ''));
        $tags = collect($payload['tags'] ?? [])
            ->filter(fn ($tag) => is_scalar($tag))
            ->map(fn ($tag) => $this->normalizeTag((string) $tag))
            ->filter(fn (string $tag) => $tag !== '')
            ->unique()
            ->values()
            ->take(16)
            ->all();

        return [
            'description' => $description !== '' ? Str::limit($description, 700, '') : null,
            'tags' => $tags,
            'source' => $source,
        ];
    }

    private function normalizeTag(string $tag): string
    {
        $tag = app(IncomingMessageNormalizer::class)->normalize($tag);
        $tag = preg_replace('/[^a-z0-9\s]+/u', ' ', $tag) ?? $tag;

        return trim(Str::squish($tag));
    }

    private function isAvailable(): bool
    {
        return config('ai.drive_metadata.provider') === 'openai'
            && filled(config('ai.drive_metadata.api_key'));
    }

    /**
     * @return array{description: string|null, tags: array<int, string>, source: string|null}
     */
    private function empty(): array
    {
        return [
            'description' => null,
            'tags' => [],
            'source' => null,
        ];
    }
}
