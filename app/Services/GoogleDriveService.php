<?php

namespace App\Services;

use App\Models\GoogleDriveConnection;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class GoogleDriveService
{
    public function __construct(
        private readonly GoogleDriveOAuthService $oauth,
    ) {}

    public function ensureRootFolder(User $user): string
    {
        $connection = $this->getConnection($user);

        if (! blank($connection->root_folder_id)) {
            return (string) $connection->root_folder_id;
        }

        $name = (string) config('drive.root_folder_name', 'InovaFinance');
        $folder = $this->createFolder($user, $name, null);

        $connection->forceFill([
            'root_folder_id' => $folder['id'] ?? null,
        ])->save();

        return (string) ($folder['id'] ?? '');
    }

    public function ensureFolderPath(User $user, string $path, ?string $parentId = null): array
    {
        $path = trim($path, "/ \t\n\r\0\x0B");
        $parts = array_values(array_filter(array_map('trim', explode('/', $path))));

        $parentId = $parentId ?: $this->ensureRootFolder($user);
        $currentPath = [];

        foreach ($parts as $part) {
            $currentPath[] = $part;
            $existing = $this->findChildFolder($user, $parentId, $part);
            if ($existing) {
                $parentId = (string) $existing['id'];
                continue;
            }

            $created = $this->createFolder($user, $part, $parentId);
            $parentId = (string) ($created['id'] ?? '');
        }

        return [
            'folder_id' => $parentId,
            'drive_path' => implode('/', $currentPath),
        ];
    }

    public function uploadFileFromPath(User $user, string $localPath, string $fileName, ?string $mimeType, ?string $parentId): array
    {
        if (! is_file($localPath)) {
            throw new RuntimeException('Arquivo nao encontrado para upload.');
        }

        $size = filesize($localPath) ?: 0;
        $mimeType = $mimeType ?: 'application/octet-stream';

        // Use resumable for bigger files to avoid payload limits.
        if ($size > 5 * 1024 * 1024) {
            return $this->uploadResumable($user, $localPath, $fileName, $mimeType, $size, $parentId);
        }

        return $this->uploadMultipart($user, $localPath, $fileName, $mimeType, $parentId);
    }

    public function buildFileWebUrl(string $driveFileId): string
    {
        return 'https://drive.google.com/file/d/'.rawurlencode($driveFileId).'/view';
    }

    private function uploadMultipart(User $user, string $localPath, string $fileName, string $mimeType, ?string $parentId): array
    {
        $boundary = '===============InovaFinanceDrive'.Str::random(24);
        $metadata = [
            'name' => $fileName,
        ];
        if (! blank($parentId)) {
            $metadata['parents'] = [$parentId];
        }

        $metaJson = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $binary = file_get_contents($localPath);
        if ($binary === false) {
            throw new RuntimeException('Falha ao ler arquivo local.');
        }

        $body = "--{$boundary}\r\n";
        $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
        $body .= $metaJson."\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: {$mimeType}\r\n\r\n";
        $body .= $binary."\r\n";
        $body .= "--{$boundary}--";

        $response = $this->send($user, fn (PendingRequest $request) => $request
            ->withHeaders([
                'Content-Type' => 'multipart/related; boundary='.$boundary,
            ])
            ->withBody($body, 'multipart/related; boundary='.$boundary)
            ->post('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name,parents,mimeType,size,webViewLink')
        );

        if (! $response->successful()) {
            Log::warning('Falha no upload multipart para Google Drive', [
                'user_id' => $user->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('Falha ao enviar arquivo ao Google Drive.');
        }

        return $response->json();
    }

    private function uploadResumable(User $user, string $localPath, string $fileName, string $mimeType, int $size, ?string $parentId): array
    {
        $metadata = [
            'name' => $fileName,
        ];
        if (! blank($parentId)) {
            $metadata['parents'] = [$parentId];
        }

        $start = $this->send($user, fn (PendingRequest $request) => $request
            ->withHeaders([
                'X-Upload-Content-Type' => $mimeType,
                'X-Upload-Content-Length' => (string) $size,
                'Content-Type' => 'application/json; charset=UTF-8',
            ])
            ->post('https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&fields=id,name,parents,mimeType,size,webViewLink', $metadata)
        );

        if (! $start->successful()) {
            Log::warning('Falha ao iniciar upload resumable no Google Drive', [
                'user_id' => $user->id,
                'status' => $start->status(),
                'body' => $start->body(),
            ]);
            throw new RuntimeException('Falha ao iniciar upload no Google Drive.');
        }

        $uploadUrl = $start->header('Location');
        if (! is_string($uploadUrl) || $uploadUrl === '') {
            throw new RuntimeException('Google Drive nao retornou URL de upload.');
        }

        $binary = file_get_contents($localPath);
        if ($binary === false) {
            throw new RuntimeException('Falha ao ler arquivo local.');
        }

        $finish = $this->send($user, fn (PendingRequest $request) => $request
            ->withHeaders([
                'Content-Length' => (string) $size,
                'Content-Type' => $mimeType,
            ])
            ->withBody($binary, $mimeType)
            ->put($uploadUrl)
        );

        if (! $finish->successful()) {
            Log::warning('Falha no upload resumable para Google Drive', [
                'user_id' => $user->id,
                'status' => $finish->status(),
                'body' => $finish->body(),
            ]);
            throw new RuntimeException('Falha ao enviar arquivo ao Google Drive.');
        }

        return $finish->json();
    }

    private function createFolder(User $user, string $name, ?string $parentId): array
    {
        $payload = [
            'name' => $name,
            'mimeType' => 'application/vnd.google-apps.folder',
        ];

        if (! blank($parentId)) {
            $payload['parents'] = [$parentId];
        }

        $response = $this->send($user, fn (PendingRequest $request) => $request
            ->post('https://www.googleapis.com/drive/v3/files?fields=id,name,parents,mimeType', $payload)
        );

        if (! $response->successful()) {
            Log::warning('Falha ao criar pasta no Google Drive', [
                'user_id' => $user->id,
                'status' => $response->status(),
                'body' => $response->body(),
                'name' => $name,
                'parent_id' => $parentId,
            ]);
            throw new RuntimeException('Falha ao criar pasta no Google Drive.');
        }

        return $response->json();
    }

    private function findChildFolder(User $user, string $parentId, string $name): ?array
    {
        $q = sprintf(
            "mimeType = 'application/vnd.google-apps.folder' and name = '%s' and '%s' in parents and trashed = false",
            str_replace("'", "\\'", $name),
            str_replace("'", "\\'", $parentId),
        );

        $response = $this->send($user, fn (PendingRequest $request) => $request
            ->get('https://www.googleapis.com/drive/v3/files', [
                'q' => $q,
                'fields' => 'files(id,name,parents,mimeType)',
                'pageSize' => 1,
            ])
        );

        if (! $response->successful()) {
            return null;
        }

        $files = $response->json('files') ?? [];
        return is_array($files) && isset($files[0]) ? $files[0] : null;
    }

    private function request(User $user): PendingRequest
    {
        $token = $this->oauth->getAccessToken($user);

        return Http::timeout(30)->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ]);
    }

    private function requestWithFreshToken(User $user): PendingRequest
    {
        $this->oauth->clearCachedToken($user);
        $token = $this->oauth->getAccessToken($user, true);

        return Http::timeout(30)->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ]);
    }

    private function send(User $user, callable $callback): Response
    {
        $response = $callback($this->request($user));

        if (! $response->successful() && $this->shouldRetryWithFreshToken($response)) {
            Log::info('Google Drive: tentando novamente com token renovado', [
                'user_id' => $user->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            $response = $callback($this->requestWithFreshToken($user));
        }

        return $response;
    }

    private function shouldRetryWithFreshToken(Response $response): bool
    {
        if (in_array($response->status(), [401, 403], true)) {
            return true;
        }

        $body = (string) $response->body();

        return str_contains($body, 'Invalid Credentials')
            || str_contains($body, 'insufficient authentication scopes')
            || str_contains($body, 'Request had insufficient authentication scopes');
    }

    private function getConnection(User $user): GoogleDriveConnection
    {
        $connection = $user->googleDriveConnection;
        if (! $connection) {
            throw new RuntimeException('Google Drive nao conectado.');
        }

        return $connection;
    }
}
