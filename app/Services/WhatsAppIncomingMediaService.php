<?php

namespace App\Services;

use App\Models\User;
use App\Models\WhatsAppIncomingMedia;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WhatsAppIncomingMediaService
{
    public function storeFromDocumentBase64(
        User $user,
        string $phoneNumber,
        string $documentBase64,
        ?string $mimeType = null,
        ?string $fileName = null,
        array $metadata = []
    ): ?WhatsAppIncomingMedia {
        $binary = base64_decode($documentBase64, true);
        if ($binary === false || $binary === '') {
            return null;
        }

        $extension = $this->resolveExtension($mimeType, $fileName);
        $safeName = $this->safeFileName($fileName ?: 'documento', $extension);

        return $this->persistBinary($user, $phoneNumber, 'document', $binary, $safeName, $mimeType, $metadata);
    }

    public function storeFromAudioBase64(
        User $user,
        string $phoneNumber,
        string $audioBase64,
        ?string $mimeType = null,
        array $metadata = []
    ): ?WhatsAppIncomingMedia {
        $binary = base64_decode($audioBase64, true);
        if ($binary === false || $binary === '') {
            return null;
        }

        $extension = $this->resolveExtension($mimeType, null);
        $safeName = $this->safeFileName('audio', $extension);

        return $this->persistBinary($user, $phoneNumber, 'audio', $binary, $safeName, $mimeType, $metadata);
    }

    public function storeFromImageUrl(
        User $user,
        string $phoneNumber,
        string $imageUrl,
        array $metadata = []
    ): ?WhatsAppIncomingMedia {
        try {
            $response = Http::timeout(30)->get($imageUrl);
            if (! $response->successful()) {
                return null;
            }

            $binary = $response->body();
            if ($binary === '') {
                return null;
            }

            $mimeType = $response->header('Content-Type');
            $extension = $this->resolveExtension($mimeType, null);
            $safeName = $this->safeFileName('imagem', $extension);

            return $this->persistBinary($user, $phoneNumber, 'image', $binary, $safeName, $mimeType, $metadata);
        } catch (\Throwable $e) {
            Log::warning('Falha ao baixar imagem para armazenar como midia recebida', [
                'user_id' => $user->id,
                'url' => $imageUrl,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function persistBinary(
        User $user,
        string $phoneNumber,
        string $kind,
        string $binary,
        string $safeName,
        ?string $mimeType,
        array $metadata
    ): WhatsAppIncomingMedia {
        $disk = 'local';
        $hash = hash('sha256', $binary);
        $path = 'whatsapp/incoming/'.$user->id.'/'.now()->format('Y/m/d').'/'.Str::uuid().'_'.$safeName;

        Storage::disk($disk)->put($path, $binary);

        return WhatsAppIncomingMedia::create([
            'user_id' => $user->id,
            'phone_number' => $phoneNumber,
            'kind' => $kind,
            'storage_disk' => $disk,
            'storage_path' => $path,
            'original_name' => $safeName,
            'mime_type' => $mimeType,
            'size_bytes' => strlen($binary),
            'sha256' => $hash,
            'received_at' => now(),
            'metadata' => $metadata,
        ]);
    }

    private function safeFileName(string $fileName, string $fallbackExtension): string
    {
        $base = pathinfo($fileName, PATHINFO_FILENAME);
        $base = trim((string) $base);
        $base = preg_replace('/[^A-Za-z0-9._-]+/u', '_', $base) ?? 'arquivo';
        $base = trim($base, '._-');
        $base = $base !== '' ? $base : 'arquivo';

        $ext = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));
        $ext = $ext !== '' ? $ext : $fallbackExtension;
        $ext = preg_replace('/[^A-Za-z0-9]+/u', '', $ext) ?? $fallbackExtension;
        $ext = $ext !== '' ? $ext : 'bin';

        return $base.'.'.$ext;
    }

    private function resolveExtension(?string $mimeType, ?string $fileName): string
    {
        $extension = strtolower((string) pathinfo((string) $fileName, PATHINFO_EXTENSION));
        if ($extension !== '') {
            return $extension;
        }

        return match ($mimeType) {
            'application/pdf' => 'pdf',
            'text/plain' => 'txt',
            'text/csv', 'application/csv', 'application/vnd.ms-excel' => 'csv',
            'application/ofx', 'application/x-ofx' => 'ofx',
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'audio/mpeg' => 'mp3',
            'audio/ogg', 'audio/ogg; codecs=opus', 'audio/opus' => 'ogg',
            'audio/wav', 'audio/x-wav' => 'wav',
            default => 'bin',
        };
    }
}

