<?php

namespace App\Services\WhatsApp;

use App\Models\DriveFile;
use App\Models\User;

class DriveConversationService
{
    public function __construct(
        private readonly DriveMessageParser $parser,
        private readonly GoogleDriveServiceLinkHelper $linkHelper,
    ) {}

    public function buildReply(User $user, string $rawMessage, array $state): array
    {
        if (! $user->googleDriveConnection || $user->googleDriveConnection->revoked_at) {
            $url = rtrim((string) config('app.url'), '/').'/integrations/google-drive';
            return [
                'reply' => "Para eu salvar e buscar arquivos, voce precisa conectar seu Google Drive:\n{$url}",
                'entities' => ['topic' => 'drive'],
            ];
        }

        $term = $this->parser->extractQueryTerm($rawMessage);

        $query = $user->driveFiles()->orderByDesc('id');
        if ($term !== null) {
            $q = trim($term);
            $query->where(function ($builder) use ($q) {
                $builder
                    ->where('title', 'like', '%'.$q.'%')
                    ->orWhere('original_name', 'like', '%'.$q.'%')
                    ->orWhere('drive_path', 'like', '%'.$q.'%')
                    ->orWhere('extracted_text', 'like', '%'.$q.'%')
                    ->orWhereJsonContains('tags', $q);
            });
        }

        /** @var \Illuminate\Support\Collection<int, DriveFile> $files */
        $files = $query->limit(6)->get();

        if ($files->isEmpty()) {
            $suffix = $term ? " para \"{$term}\"" : '';
            return [
                'reply' => "Nao encontrei arquivos{$suffix}.\n\nDica: envie um arquivo no WhatsApp e diga \"salva isso no drive\".",
                'entities' => [
                    'topic' => 'drive',
                    'drive_query_term' => $term,
                ],
            ];
        }

        $lines = $files->map(function (DriveFile $file, int $index) {
            $label = $file->title ?: ($file->original_name ?: 'Arquivo');
            $path = $file->drive_path ? " ({$file->drive_path})" : '';
            $date = $file->created_at?->format('d/m/Y') ?? '';
            return ($index + 1).". {$label}{$path} - {$date}";
        })->implode("\n");

        $first = $files->first();
        $firstUrl = $first?->drive_file_id ? $this->linkHelper->webUrl((string) $first->drive_file_id) : null;

        $reply = "Encontrei estes arquivos:\n{$lines}";
        if ($firstUrl) {
            $reply .= "\n\nAbrir o primeiro:\n{$firstUrl}";
        }

        $reply .= "\n\nSe quiser, diga: \"abrir o 2\" ou \"buscar arquivo sobre contrato\".";

        return [
            'reply' => $reply,
            'entities' => [
                'topic' => 'drive',
                'drive_query_term' => $term,
            ],
        ];
    }
}
