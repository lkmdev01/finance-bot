<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AISmokeCommand extends Command
{
    protected $signature = 'ai:smoke
        {--live : Faz uma chamada real para o provider configurado}
        {--drive : Testa tambem os modelos de metadata do Drive}';

    protected $description = 'Valida a configuracao de IA sem expor chaves sensiveis.';

    public function handle(): int
    {
        $this->info('AI smoke check');

        $mainProvider = (string) config('ai.provider');
        $mainModel = $this->effectiveModel($mainProvider, (string) data_get(config('ai'), "{$mainProvider}.model", ''));
        $mainKey = (string) config('ai.api_key');

        $this->reportConfig('principal', $mainProvider, $mainModel, $mainKey);

        if ($this->option('drive')) {
            $driveProvider = (string) config('ai.drive_metadata.provider');
            $driveKey = (string) config('ai.drive_metadata.api_key');

            $this->reportConfig(
                'drive_metadata',
                $driveProvider,
                $this->effectiveModel($driveProvider, (string) config('ai.drive_metadata.metadata_model')),
                $driveKey
            );

            $this->reportConfig(
                'drive_vision',
                $driveProvider,
                $this->effectiveModel($driveProvider, (string) config('ai.drive_metadata.vision_model')),
                $driveKey
            );
        }

        if (! $this->option('live')) {
            $this->line('Use --live para fazer uma chamada real na API.');

            return self::SUCCESS;
        }

        $checks = [
            ['principal', $mainProvider, $mainModel, $mainKey, false, false],
        ];

        if ($this->option('drive')) {
            $driveProvider = (string) config('ai.drive_metadata.provider');
            $driveKey = (string) config('ai.drive_metadata.api_key');
            $checks[] = [
                'drive_metadata',
                $driveProvider,
                $this->effectiveModel($driveProvider, (string) config('ai.drive_metadata.metadata_model')),
                $driveKey,
                false,
                true,
            ];
            $checks[] = [
                'drive_vision',
                $driveProvider,
                $this->effectiveModel($driveProvider, (string) config('ai.drive_metadata.vision_model')),
                $driveKey,
                true,
                true,
            ];
        }

        $failed = false;

        foreach ($checks as [$label, $provider, $model, $key, $vision, $jsonMode]) {
            if (! in_array($provider, ['groq', 'openai'], true)) {
                $this->warn("[SKIP] {$label}: provider {$provider} nao usa smoke HTTP.");

                continue;
            }

            if ($key === '') {
                $this->error("[FAIL] {$label}: chave nao configurada.");
                $failed = true;

                continue;
            }

            $ok = $this->callChatCompletion($label, $provider, $model, $key, $vision, $jsonMode);
            $failed = $failed || ! $ok;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function reportConfig(string $label, string $provider, string $model, string $key): void
    {
        $this->line(sprintf(
            '[%s] provider=%s model=%s key=%s',
            $label,
            $provider !== '' ? $provider : 'nao configurado',
            $model !== '' ? $model : 'nao configurado',
            $key !== '' ? 'configurada' : 'ausente',
        ));
    }

    private function callChatCompletion(string $label, string $provider, string $model, string $key, bool $vision, bool $jsonMode): bool
    {
        $url = match ($provider) {
            'groq' => 'https://api.groq.com/openai/v1/chat/completions',
            'openai' => 'https://api.openai.com/v1/chat/completions',
        };

        $content = $vision
            ? [
                ['type' => 'text', 'text' => 'Descreva a imagem em JSON valido no formato {"ok":true,"kind":"vision","description":"..."}'],
                ['type' => 'image_url', 'image_url' => ['url' => 'https://upload.wikimedia.org/wikipedia/commons/d/da/SF_From_Marin_Highlands3.jpg']],
            ]
            : ($jsonMode
                ? 'Responda somente com este JSON valido, sem markdown: {"ok":true,"kind":"text"}'
                : 'Responda apenas: ok');

        $payload = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $content,
                ],
            ],
            'temperature' => 0,
            'max_tokens' => 80,
        ];

        if ($jsonMode) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $response = Http::withToken($key)
            ->acceptJson()
            ->timeout(30)
            ->post($url, $payload);

        if ($jsonMode && $this->shouldRetryWithoutJsonMode($response->status(), $response->body())) {
            unset($payload['response_format']);

            $response = Http::withToken($key)
                ->acceptJson()
                ->timeout(30)
                ->post($url, $payload);

            if ($response->successful()) {
                $this->warn("[WARN] {$label}: JSON mode falhou, mas retry simples passou.");

                return true;
            }
        }

        if ($response->successful()) {
            $this->info("[OK] {$label}: chamada aceita.");

            return true;
        }

        $error = data_get($response->json(), 'error.message') ?: $response->body();
        $this->error(sprintf(
            '[FAIL] %s: HTTP %s - %s',
            $label,
            $response->status(),
            Str::limit((string) $error, 240, ''),
        ));

        return false;
    }

    private function effectiveModel(string $provider, string $model): string
    {
        if ($provider !== 'groq') {
            return $model;
        }

        return match ($model) {
            'llama-3.1-8b-instant' => 'openai/gpt-oss-20b',
            'llama-3.3-70b-versatile' => 'openai/gpt-oss-120b',
            'meta-llama/llama-4-scout-17b-16e-instruct' => 'qwen/qwen3.6-27b',
            default => $model,
        };
    }

    private function shouldRetryWithoutJsonMode(int $status, string $body): bool
    {
        return $status === 400
            && str_contains(Str::lower($body), 'failed to validate json');
    }
}
