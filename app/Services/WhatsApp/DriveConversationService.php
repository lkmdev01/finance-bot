<?php

namespace App\Services\WhatsApp;

use App\Models\DriveFile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

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

        $queryData = $this->parser->parseQuery($rawMessage, $state);

        if (($followUpReply = $this->buildFollowUpReply($user, $state, $queryData)) !== null) {
            return $followUpReply;
        }

        $query = $user->driveFiles()->orderByDesc('id');
        $this->applyFilters($query, $queryData);

        /** @var Collection<int, DriveFile> $files */
        $files = $query->limit(6)->get();

        if ($files->isEmpty()) {
            $suffix = ! empty($queryData['term']) ? ' para "'.$queryData['term'].'"' : '';

            return [
                'reply' => "Nao encontrei arquivos{$suffix}.\n\nDica: envie um arquivo no WhatsApp e diga \"salva isso no drive\".",
                'entities' => [
                    'topic' => 'drive',
                    'drive_query_term' => $queryData['term'],
                    'drive_time_scope' => $queryData['time_scope'],
                    'drive_media_kind' => $queryData['media_kind'],
                ],
            ];
        }

        $reply = $this->buildListReply($files, $queryData);
        $first = $files->first();

        return [
            'reply' => $reply,
            'entities' => $this->buildEntities($files, $first, $queryData),
        ];
    }

    private function buildFollowUpReply(User $user, array $state, array $queryData): ?array
    {
        $followUp = $queryData['follow_up'] ?? null;
        if ($followUp === null) {
            return null;
        }

        $referenced = match ($followUp) {
            'open_ordinal' => $this->resolveOrdinalFile($user, $state, (int) ($queryData['ordinal'] ?? 0)),
            default => $this->resolveRecentFile($user, $state),
        };

        if (! $referenced) {
            return [
                'reply' => "Nao encontrei um arquivo recente para essa referencia.\n\nSe quiser, diga \"meus arquivos\" para eu listar de novo.",
                'entities' => ['topic' => 'drive'],
            ];
        }

        $label = $this->displayName($referenced);
        $url = $referenced->drive_file_id ? $this->linkHelper->webUrl((string) $referenced->drive_file_id) : null;

        if ($followUp === 'show_folder') {
            $folder = $referenced->drive_path ?: 'Drive raiz';
            $reply = "Esse arquivo ficou na pasta {$folder}.";

            if ($url) {
                $reply .= "\n\nAbrir no Drive:\n{$url}";
            }

            return [
                'reply' => $reply,
                'entities' => $this->buildEntities(collect([$referenced]), $referenced, $queryData),
            ];
        }

        $reply = "Aqui esta {$label}.";
        if ($referenced->drive_path) {
            $reply .= "\nPasta: {$referenced->drive_path}";
        }
        if ($url) {
            $reply .= "\n\nAbrir no Drive:\n{$url}";
        }

        return [
            'reply' => $reply,
            'entities' => $this->buildEntities(collect([$referenced]), $referenced, $queryData),
        ];
    }

    private function applyFilters(Builder|HasMany $query, array $queryData): void
    {
        if (! empty($queryData['time_scope'])) {
            $this->applyTimeScope($query, (string) $queryData['time_scope']);
        }

        if (! empty($queryData['media_kind'])) {
            $this->applyMediaKind($query, (string) $queryData['media_kind']);
        }

        if (! empty($queryData['term'])) {
            $this->applySearchTerm($query, (string) $queryData['term']);
        }
    }

    private function applyTimeScope(Builder|HasMany $query, string $scope): void
    {
        $today = CarbonImmutable::now();

        match ($scope) {
            'today' => $query->whereDate('created_at', $today->toDateString()),
            'yesterday' => $query->whereDate('created_at', $today->subDay()->toDateString()),
            'today_morning' => $query
                ->whereDate('created_at', $today->toDateString())
                ->whereTime('created_at', '<', '12:00:00'),
            default => null,
        };
    }

    private function applyMediaKind(Builder|HasMany $query, string $mediaKind): void
    {
        match ($mediaKind) {
            'image' => $query->where('mime_type', 'like', 'image/%'),
            'audio' => $query->where('mime_type', 'like', 'audio/%'),
            'document' => $query->where(function (Builder $builder) {
                $builder
                    ->where('mime_type', 'not like', 'image/%')
                    ->where('mime_type', 'not like', 'audio/%');
            }),
            default => null,
        };
    }

    private function applySearchTerm(Builder|HasMany $query, string $term): void
    {
        $tokens = array_values(array_filter(preg_split('/\s+/u', trim($term)) ?: []));

        if ($tokens === []) {
            return;
        }

        $query->where(function (Builder $builder) use ($term, $tokens) {
            $builder
                ->where('title', 'like', '%'.$term.'%')
                ->orWhere('original_name', 'like', '%'.$term.'%')
                ->orWhere('drive_path', 'like', '%'.$term.'%')
                ->orWhere('extracted_text', 'like', '%'.$term.'%');

            foreach ($tokens as $token) {
                $builder->orWhere(function (Builder $nested) use ($token) {
                    $nested
                        ->where('title', 'like', '%'.$token.'%')
                        ->orWhere('original_name', 'like', '%'.$token.'%')
                        ->orWhere('drive_path', 'like', '%'.$token.'%')
                        ->orWhere('extracted_text', 'like', '%'.$token.'%');
                });
            }
        });
    }

    private function buildListReply(Collection $files, array $queryData): string
    {
        $header = match (true) {
            ! empty($queryData['time_scope']) && $queryData['time_scope'] === 'today' => 'Arquivos salvos hoje:',
            ! empty($queryData['time_scope']) && $queryData['time_scope'] === 'yesterday' => 'Arquivos salvos ontem:',
            ! empty($queryData['term']) => 'Encontrei estes arquivos:',
            default => 'Seus arquivos recentes:',
        };

        $lines = $files->values()->map(function (DriveFile $file, int $index) {
            $label = $this->displayName($file);
            $path = $file->drive_path ? " ({$file->drive_path})" : '';
            $date = $file->created_at?->format('d/m/Y') ?? '';

            return ($index + 1).". {$label}{$path} - {$date}";
        })->implode("\n");

        $first = $files->first();
        $firstUrl = $first?->drive_file_id ? $this->linkHelper->webUrl((string) $first->drive_file_id) : null;

        $reply = "{$header}\n{$lines}";
        if ($firstUrl) {
            $reply .= "\n\nAbrir o primeiro:\n{$firstUrl}";
        }

        $reply .= "\n\nSe quiser, diga: \"abrir o 2\", \"em qual pasta ficou?\" ou \"buscar arquivo sobre contrato\".";

        return $reply;
    }

    private function buildEntities(Collection $files, ?DriveFile $first, array $queryData): array
    {
        return [
            'topic' => 'drive',
            'drive_file_id' => $first?->id,
            'drive_file_title' => $first?->title ?: $first?->original_name,
            'drive_path' => $first?->drive_path,
            'drive_query_term' => $queryData['term'],
            'drive_time_scope' => $queryData['time_scope'],
            'drive_media_kind' => $queryData['media_kind'],
            'recent_drive_file_ids' => $files->pluck('id')->values()->all(),
        ];
    }

    private function resolveRecentFile(User $user, array $state): ?DriveFile
    {
        $lastEntities = $state['last_entities'] ?? [];

        $fileId = (int) ($lastEntities['drive_file_id'] ?? 0);
        if ($fileId > 0) {
            return $user->driveFiles()->find($fileId);
        }

        $recentIds = array_values(array_filter($lastEntities['recent_drive_file_ids'] ?? [], fn ($id) => (int) $id > 0));
        if ($recentIds === []) {
            return null;
        }

        return $user->driveFiles()->find((int) $recentIds[0]);
    }

    private function resolveOrdinalFile(User $user, array $state, int $ordinal): ?DriveFile
    {
        if ($ordinal <= 0) {
            return null;
        }

        $recentIds = array_values(array_filter($state['last_entities']['recent_drive_file_ids'] ?? [], fn ($id) => (int) $id > 0));
        $targetId = $recentIds[$ordinal - 1] ?? null;

        return $targetId ? $user->driveFiles()->find((int) $targetId) : null;
    }

    private function displayName(DriveFile $file): string
    {
        return $file->title ?: ($file->original_name ?: 'Arquivo');
    }
}
