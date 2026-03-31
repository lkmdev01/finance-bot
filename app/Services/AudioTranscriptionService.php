<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AudioTranscriptionService
{
    public function transcribeBase64(string $audioBase64, ?string $mimeType = null): ?string
    {
        $provider = config('services.transcription.provider');
        $apiKey = config('services.transcription.api_key');

        if (blank($provider) || blank($apiKey) || blank($audioBase64)) {
            Log::info('Transcricao de audio indisponivel por falta de configuracao.', [
                'provider' => $provider,
                'has_api_key' => ! blank($apiKey),
                'has_audio' => ! blank($audioBase64),
            ]);

            return null;
        }

        $audioBinary = base64_decode($audioBase64, true);

        if ($audioBinary === false) {
            Log::warning('Falha ao decodificar audio em base64 para transcricao.');

            return null;
        }

        $extension = $this->guessExtension($mimeType);
        $filename = "whatsapp-voice.{$extension}";

        return match ($provider) {
            'groq' => $this->transcribeWithGroq($apiKey, $audioBinary, $filename, $mimeType),
            'openai' => $this->transcribeWithOpenAI($apiKey, $audioBinary, $filename, $mimeType),
            default => $this->unsupportedProvider($provider),
        };
    }

    private function transcribeWithGroq(string $apiKey, string $audioBinary, string $filename, ?string $mimeType): ?string
    {
        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->attach('file', $audioBinary, $filename, [
                'Content-Type' => $mimeType ?? 'audio/ogg',
            ])
            ->post('https://api.groq.com/openai/v1/audio/transcriptions', [
                'model' => config('services.transcription.groq_model'),
                'response_format' => 'verbose_json',
            ]);

        if (! $response->successful()) {
            Log::error('Erro ao transcrever audio com Groq.', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        return $this->extractTranscript($response->json());
    }

    private function transcribeWithOpenAI(string $apiKey, string $audioBinary, string $filename, ?string $mimeType): ?string
    {
        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->attach('file', $audioBinary, $filename, [
                'Content-Type' => $mimeType ?? 'audio/ogg',
            ])
            ->post('https://api.openai.com/v1/audio/transcriptions', [
                'model' => config('services.transcription.openai_model'),
                'response_format' => 'verbose_json',
            ]);

        if (! $response->successful()) {
            Log::error('Erro ao transcrever audio com OpenAI.', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        return $this->extractTranscript($response->json());
    }

    private function extractTranscript(array $payload): ?string
    {
        $text = trim((string) ($payload['text'] ?? ''));

        return $text !== '' ? $text : null;
    }

    private function unsupportedProvider(string $provider): ?string
    {
        Log::info('Provider de transcricao nao suportado.', [
            'provider' => $provider,
        ]);

        return null;
    }

    private function guessExtension(?string $mimeType): string
    {
        return match ($mimeType) {
            'audio/ogg', 'audio/ogg; codecs=opus', 'audio/opus' => 'ogg',
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/mp4', 'audio/aac', 'audio/x-m4a' => 'm4a',
            'audio/wav', 'audio/x-wav' => 'wav',
            default => 'ogg',
        };
    }
}
