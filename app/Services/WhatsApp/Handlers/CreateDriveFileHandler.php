<?php

namespace App\Services\WhatsApp\Handlers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\DriveFile;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppIncomingMedia;
use App\Services\BillingPlanService;
use App\Services\DocumentTextExtractorService;
use App\Services\GoogleDriveService;
use App\Services\OCRService;
use App\Services\WhatsApp\DriveFileSemanticMetadataService;
use App\Services\WhatsApp\IncomingMessageNormalizer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CreateDriveFileHandler extends BaseHandler
{
    public function canHandle(?string $action): bool
    {
        return $action === 'create_drive_file';
    }

    public function handle(?string $action, array &$result, User $user, WhatsAppContact $contact, ProcessWhatsAppMessage $job): bool
    {
        $billingPlanService = app(BillingPlanService::class);
        if (! $billingPlanService->userCanCreateRecords($user)) {
            $plansUrl = rtrim((string) config('app.url'), '/').'/billing/plans';
            $reply = $billingPlanService->writeAccessMessage($user)
                ."\n\nAssine aqui:\n{$plansUrl}";
            $this->sendResponse($job, $reply, $user);

            return true;
        }

        $data = is_array($result['drive_data'] ?? null) ? $result['drive_data'] : [];
        $incomingMediaId = (int) ($data['incoming_media_id'] ?? ($contact->conversation_state['last_entities']['incoming_media_id'] ?? 0));

        if ($incomingMediaId <= 0) {
            $this->sendErrorMessage($job, "Nao encontrei nenhum arquivo recente para salvar.\n\nMe envie um arquivo/foto/audio e diga: \"salva isso no drive\".");

            return true;
        }

        $media = WhatsAppIncomingMedia::query()
            ->where('user_id', $user->id)
            ->find($incomingMediaId);

        if (! $media) {
            $this->sendErrorMessage($job, 'Nao consegui acessar o arquivo recebido. Pode me enviar novamente?');

            return true;
        }

        if (! $user->googleDriveConnection || $user->googleDriveConnection->revoked_at) {
            $url = rtrim((string) config('app.url'), '/').'/integrations/google-drive';
            $this->sendResponse($job, "Para eu salvar arquivos, conecte seu Google Drive aqui:\n{$url}", $user);

            return true;
        }

        $drive = app(GoogleDriveService::class);
        $extractor = app(DocumentTextExtractorService::class);
        $ocr = app(OCRService::class);

        $folderHint = isset($data['folder_hint']) && is_string($data['folder_hint']) ? trim($data['folder_hint']) : null;
        $autoFolderKey = isset($data['auto_folder_key']) && is_string($data['auto_folder_key']) ? $data['auto_folder_key'] : null;

        $rootId = $drive->ensureRootFolder($user);
        $folderName = null;
        $folderId = $rootId;
        $drivePath = null;

        if ($folderHint) {
            $resolved = $drive->ensureFolderPath($user, $this->normalizeFolderPath($folderHint), $rootId);
            $folderId = (string) $resolved['folder_id'];
            $drivePath = (string) ($resolved['drive_path'] ?? null);
            $folderName = $drivePath ?: $folderHint;
        } else {
            $defaultFolders = (array) config('drive.default_folders', []);
            $fallbackKey = $autoFolderKey ?: $this->inferFallbackFolderKey($media);
            if ($fallbackKey && isset($defaultFolders[$fallbackKey])) {
                $resolved = $drive->ensureFolderPath($user, (string) $defaultFolders[$fallbackKey], $rootId);
                $folderId = (string) $resolved['folder_id'];
                $drivePath = (string) ($resolved['drive_path'] ?? null);
                $folderName = $drivePath ?: $defaultFolders[$fallbackKey];
            }
        }

        $localPath = Storage::disk($media->storage_disk)->path($media->storage_path);
        $fileName = $media->original_name ?: ('arquivo_'.Str::random(6));
        $mimeType = $media->mime_type ?: 'application/octet-stream';

        try {
            $uploaded = $drive->uploadFileFromPath($user, $localPath, $fileName, $mimeType, $folderId);
            $driveFileId = (string) ($uploaded['id'] ?? '');

            $extractedText = null;
            $tags = [];

            if ($media->kind === 'audio') {
                $extractedText = (string) ($media->metadata['transcription'] ?? '');
            } elseif ($media->kind === 'image') {
                $analysis = $ocr->analyzeImageFromFile($localPath);
                $extractedText = is_string($analysis['text'] ?? null) ? $analysis['text'] : null;
                $tags = is_array($analysis['labels'] ?? null) ? array_values($analysis['labels']) : [];
            } else {
                $extractedText = $extractor->extractFromPath($localPath, $mimeType);
            }

            $title = $this->inferTitle($media, $tags);
            $semanticMetadata = app(DriveFileSemanticMetadataService::class)->build(
                $title,
                $fileName,
                $folderName,
                $media->kind,
                $tags,
                $extractedText
            );

            $tags = array_values(array_unique(array_merge(
                $this->buildSearchTags($title, $fileName, $folderName, $media->kind, $tags),
                $semanticMetadata['tags']
            )));
            $url = $driveFileId !== '' ? $drive->buildFileWebUrl($driveFileId) : null;

            $driveFile = DriveFile::create([
                'user_id' => $user->id,
                'whatsapp_incoming_media_id' => $media->id,
                'source' => 'whatsapp',
                'original_name' => $fileName,
                'mime_type' => $mimeType,
                'size_bytes' => $media->size_bytes,
                'sha256' => $media->sha256,
                'drive_file_id' => $driveFileId !== '' ? $driveFileId : null,
                'drive_parent_id' => $folderId,
                'drive_path' => $folderName,
                'title' => $title,
                'description' => $semanticMetadata['description'],
                'tags' => $tags !== [] ? $tags : null,
                'extracted_text' => $extractedText ? Str::limit($extractedText, 12000, '') : null,
                'metadata' => [
                    'uploaded' => $uploaded,
                    'web_url' => $url,
                ],
            ]);

            // Best-effort cleanup: once uploaded, we don't need the local copy.
            try {
                Storage::disk($media->storage_disk)->delete($media->storage_path);
            } catch (\Throwable) {
                // Ignore; cleanup is opportunistic.
            }

            $reply = "✅ Pronto! Salvei *{$fileName}*";
            if ($folderName) {
                $reply .= " na pasta *{$folderName}*.";
            } else {
                $reply .= ' no seu Drive.';
            }

            if ($url) {
                $reply .= "\n\nAbrir no Drive:\n{$url}";
            }

            $reply .= "\n\nSe quiser buscar depois, pode dizer:\n- ache meu comprovante do mecanico\n- meus arquivos";

            $result['_conversation_metadata'] = array_merge($result['_conversation_metadata'] ?? [], [
                'reply_kind' => 'action',
                'entities' => [
                    'topic' => 'drive',
                    'drive_file_id' => $driveFile->id,
                    'drive_file_title' => $driveFile->title,
                    'drive_path' => $driveFile->drive_path,
                    // clear the media reference after saving to avoid accidental re-saves
                    'incoming_media_id' => null,
                ],
            ]);

            $this->sendResponse($job, $reply, $user);

            return true;
        } catch (\Throwable $e) {
            Log::error('Erro ao salvar arquivo no Google Drive via WhatsApp', [
                'user_id' => $user->id,
                'media_id' => $media->id,
                'error' => $e->getMessage(),
            ]);

            $friendly = trim((string) $e->getMessage());
            if ($friendly === '' || str_starts_with($friendly, 'Falha ao ')) {
                $friendly = 'Nao consegui salvar esse arquivo no Drive agora. Tente novamente em alguns instantes.';
            }

            $this->sendErrorMessage($job, $friendly);

            return true;
        }
    }

    private function normalizeFolderPath(string $folderHint): string
    {
        $hint = trim($folderHint);
        $hint = str_replace('\\', '/', $hint);
        $hint = preg_replace('/\\s*\\/\\s*/u', '/', $hint) ?? $hint;
        $hint = preg_replace('/\\s+/u', ' ', $hint) ?? $hint;

        return trim($hint, "/ \t\n\r\0\x0B");
    }

    private function inferFallbackFolderKey(WhatsAppIncomingMedia $media): ?string
    {
        return match ($media->kind) {
            'image' => 'fotos',
            'audio' => 'audios',
            default => 'documentos',
        };
    }

    private function inferTitle(WhatsAppIncomingMedia $media, array $labels): string
    {
        if ($media->original_name) {
            return (string) Str::of($media->original_name)->beforeLast('.')->limit(80, '');
        }

        if (! empty($labels)) {
            return 'Arquivo: '.(string) $labels[0];
        }

        return 'Arquivo';
    }

    private function buildSearchTags(?string $title, string $fileName, ?string $folderName, string $kind, array $labels): array
    {
        $normalizer = app(IncomingMessageNormalizer::class);
        $sources = array_filter([
            $title,
            (string) Str::of($fileName)->beforeLast('.'),
            $folderName,
            $kind,
            ...$labels,
        ], fn ($value) => is_string($value) && trim($value) !== '');

        $tags = [];

        foreach ($sources as $source) {
            $normalized = $normalizer->normalize((string) $source);
            if ($normalized === '') {
                continue;
            }

            $tags[] = $normalized;

            $tokens = preg_split('/[^a-z0-9]+/u', $normalized) ?: [];
            foreach ($tokens as $token) {
                $token = trim($token);
                if ($token === '' || mb_strlen($token) < 3) {
                    continue;
                }

                $tags[] = $token;
            }
        }

        return array_values(array_unique($tags));
    }
}
