<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OCRService
{
    public function __construct(
        private readonly ?string $apiKey = null
    ) {}

    /**
     * Obtém a API key (do construtor ou do config)
     */
    private function getApiKey(): ?string
    {
        return $this->apiKey ?? config('services.google_vision.api_key');
    }

    /**
     * Extrai texto de uma imagem usando OCR
     */
    public function extractText(string $imageUrl): ?string
    {
        try {
            // Tenta usar Google Vision API se disponível
            $apiKey = $this->getApiKey();
            if ($apiKey) {
                return $this->extractTextWithGoogleVision($imageUrl, $apiKey);
            }

            // Fallback: retorna null (usuário pode descrever a imagem)
            Log::info('OCR não configurado, retornando null', [
                'image_url' => $imageUrl,
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('Erro ao processar OCR', [
                'image_url' => $imageUrl,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function extractTextFromFile(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $binary = file_get_contents($path);
        if ($binary === false || $binary === '') {
            return null;
        }

        $apiKey = $this->getApiKey();
        if (! $apiKey) {
            return null;
        }

        return $this->extractTextWithGoogleVisionBase64(base64_encode($binary), $apiKey);
    }

    public function analyzeImageFromFile(string $path): array
    {
        $apiKey = $this->getApiKey();
        if (! $apiKey || ! is_file($path)) {
            return ['text' => null, 'labels' => []];
        }

        $binary = file_get_contents($path);
        if ($binary === false || $binary === '') {
            return ['text' => null, 'labels' => []];
        }

        return $this->analyzeWithGoogleVisionBase64(base64_encode($binary), $apiKey);
    }

    /**
     * Extrai texto usando Google Vision API
     */
    private function extractTextWithGoogleVision(string $imageUrl, string $apiKey): ?string
    {
        try {
            // Baixa a imagem
            $imageContent = Http::timeout(30)->get($imageUrl)->body();
            $imageBase64 = base64_encode($imageContent);

            // Chama Google Vision API
            $response = Http::timeout(30)->post(
                "https://vision.googleapis.com/v1/images:annotate?key={$apiKey}",
                [
                    'requests' => [
                        [
                            'image' => [
                                'content' => $imageBase64,
                            ],
                            'features' => [
                                [
                                    'type' => 'TEXT_DETECTION',
                                    'maxResults' => 10,
                                ],
                            ],
                        ],
                    ],
                ]
            );

            if ($response->successful()) {
                $data = $response->json();
                $textAnnotations = $data['responses'][0]['textAnnotations'] ?? [];

                if (! empty($textAnnotations)) {
                    // Primeira anotação contém todo o texto
                    return $textAnnotations[0]['description'] ?? null;
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Erro ao processar OCR com Google Vision', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function extractTextWithGoogleVisionBase64(string $imageBase64, string $apiKey): ?string
    {
        try {
            $response = Http::timeout(30)->post(
                "https://vision.googleapis.com/v1/images:annotate?key={$apiKey}",
                [
                    'requests' => [
                        [
                            'image' => [
                                'content' => $imageBase64,
                            ],
                            'features' => [
                                [
                                    'type' => 'TEXT_DETECTION',
                                    'maxResults' => 10,
                                ],
                            ],
                        ],
                    ],
                ]
            );

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();
            $textAnnotations = $data['responses'][0]['textAnnotations'] ?? [];
            if (! empty($textAnnotations)) {
                return $textAnnotations[0]['description'] ?? null;
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Erro ao processar OCR base64 com Google Vision', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function analyzeWithGoogleVisionBase64(string $imageBase64, string $apiKey): array
    {
        try {
            $response = Http::timeout(30)->post(
                "https://vision.googleapis.com/v1/images:annotate?key={$apiKey}",
                [
                    'requests' => [
                        [
                            'image' => [
                                'content' => $imageBase64,
                            ],
                            'features' => [
                                [
                                    'type' => 'TEXT_DETECTION',
                                    'maxResults' => 10,
                                ],
                                [
                                    'type' => 'LABEL_DETECTION',
                                    'maxResults' => 8,
                                ],
                            ],
                        ],
                    ],
                ]
            );

            if (! $response->successful()) {
                return ['text' => null, 'labels' => []];
            }

            $data = $response->json();
            $res = $data['responses'][0] ?? [];
            $textAnnotations = $res['textAnnotations'] ?? [];
            $labelAnnotations = $res['labelAnnotations'] ?? [];

            $text = null;
            if (! empty($textAnnotations)) {
                $text = $textAnnotations[0]['description'] ?? null;
            }

            $labels = [];
            foreach ($labelAnnotations as $label) {
                $desc = $label['description'] ?? null;
                if (is_string($desc) && $desc !== '') {
                    $labels[] = $desc;
                }
            }

            return ['text' => $text, 'labels' => $labels];
        } catch (\Exception $e) {
            Log::error('Erro ao analisar imagem com Google Vision', [
                'error' => $e->getMessage(),
            ]);

            return ['text' => null, 'labels' => []];
        }
    }
}
