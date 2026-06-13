<?php

namespace App\Services\WhatsApp;

use App\Models\Note;
use App\Models\User;

class NotesConversationService
{
    public function buildReply(User $user, string $message, array $state): array
    {
        $normalizer = app(IncomingMessageNormalizer::class);
        $normalized = $normalizer->normalize($message);

        if (($followUpReply = $this->buildFollowUpReply($user, $message, $normalized, $state)) !== null) {
            return $followUpReply;
        }

        $parser = app(NoteMessageParser::class);
        $term = $parser->extractQueryTerm($message);

        // Follow-ups after listing notes are often just the topic term.
        if ($term === null && ($state['last_action'] ?? null) === 'query_notes') {
            $candidate = trim($message);
            if ($candidate !== '' && mb_strlen($candidate) <= 60) {
                $term = $candidate;
            }
        }

        $query = $user->notes()->orderByDesc('id');

        if ($term !== null && trim($term) !== '') {
            $q = trim($term);
            $query->where(function ($builder) use ($q) {
                $builder
                    ->where('title', 'like', '%'.$q.'%')
                    ->orWhere('body', 'like', '%'.$q.'%');
            });
        }

        $notes = $query->limit(8)->get();

        if ($notes->isEmpty()) {
            if ($term !== null && trim($term) !== '') {
                return [
                    'reply' => "Nao encontrei nenhuma nota sobre \"{$term}\".\n\nSe quiser, voce pode criar assim:\n- anota: {$term} ...",
                    'entities' => ['topic' => 'notes'],
                ];
            }

            return [
                'reply' => 'Voce ainda nao tem notas salvas. Se quiser, eu posso salvar uma por voce (ex.: "anota: ideia para o projeto X").',
                'entities' => ['topic' => 'notes'],
            ];
        }

        $header = $term !== null && trim($term) !== ''
            ? "Encontrei estas notas sobre \"{$term}\":"
            : 'Suas notas mais recentes:';

        $lines = $notes->map(function (Note $note, int $index) {
            $title = $note->title;
            $preview = trim((string) $note->body);
            $preview = preg_replace('/\\s+/u', ' ', $preview) ?? $preview;
            $preview = mb_strlen($preview) > 90 ? mb_substr($preview, 0, 90).'...' : $preview;
            $date = $note->created_at?->format('d/m') ?? '';

            return sprintf('- %s%s: %s', $date ? "{$date} " : '', $title, $preview);
        })->implode("\n");

        $first = $notes->first();

        return [
            'reply' => $header."\n".$lines."\n\nDica: para apagar uma nota, diga \"apagar nota <titulo>\".",
            'entities' => [
                'topic' => 'notes',
                'note_id' => $first?->id,
                'note_title' => $first?->title,
                'query_term' => $term,
                'recent_note_ids' => $notes->pluck('id')->values()->all(),
                'note_result_count' => $notes->count(),
            ],
        ];
    }

    private function buildFollowUpReply(User $user, string $message, string $normalized, array $state): ?array
    {
        if (($state['last_entities']['topic'] ?? null) !== 'notes') {
            return null;
        }

        $recentNote = $this->resolveRequestedNote($user, $message, $state) ?? $this->resolveRecentNote($user, $state);
        $count = (int) ($state['last_entities']['note_result_count'] ?? 0);
        $queryTerm = $state['last_entities']['query_term'] ?? null;

        if ($this->containsAny($normalized, ['me mostra essa nota', 'me mostra ela', 'mostra essa nota', 'abre essa nota'])) {
            if (! $recentNote) {
                return null;
            }

            return [
                'reply' => "Aqui esta a nota {$recentNote->title}.\n\n{$recentNote->body}",
                'entities' => [
                    'topic' => 'notes',
                    'note_id' => $recentNote->id,
                    'note_title' => $recentNote->title,
                    'query_term' => $queryTerm,
                    'recent_note_ids' => $state['last_entities']['recent_note_ids'] ?? [],
                    'note_result_count' => max(1, $count),
                ],
            ];
        }

        if ($this->containsAny($normalized, ['so essa', 'só essa', 'apenas essa'])) {
            $reply = $count <= 1
                ? 'Por enquanto, sim. '
                : "Nao. Encontrei {$count} notas nesse filtro. ";
            $reply .= $queryTerm ? "O filtro atual ainda esta em \"{$queryTerm}\"." : 'Essa e a nota mais recente da lista.';

            return [
                'reply' => trim($reply),
                'entities' => [
                    'topic' => 'notes',
                    'note_id' => $recentNote?->id,
                    'note_title' => $recentNote?->title,
                    'query_term' => $queryTerm,
                    'recent_note_ids' => $state['last_entities']['recent_note_ids'] ?? [],
                    'note_result_count' => max(1, $count),
                ],
            ];
        }

        if ($this->containsAny($normalized, ['tem mais nota', 'tem mais notas'])) {
            $reply = $count > 1
                ? "Sim. Eu encontrei {$count} notas nesse filtro."
                : 'Por enquanto, nao. So encontrei essa nota nesse filtro.';

            if ($queryTerm) {
                $reply .= "\n\nFiltro atual: {$queryTerm}";
            }

            return [
                'reply' => $reply,
                'entities' => [
                    'topic' => 'notes',
                    'note_id' => $recentNote?->id,
                    'note_title' => $recentNote?->title,
                    'query_term' => $queryTerm,
                    'recent_note_ids' => $state['last_entities']['recent_note_ids'] ?? [],
                    'note_result_count' => max(1, $count),
                ],
            ];
        }

        return null;
    }

    private function resolveRecentNote(User $user, array $state): ?Note
    {
        $noteId = (int) ($state['last_entities']['note_id'] ?? 0);
        if ($noteId > 0) {
            return $user->notes()->find($noteId);
        }

        $recentIds = array_values(array_filter($state['last_entities']['recent_note_ids'] ?? [], fn ($id) => (int) $id > 0));
        if ($recentIds === []) {
            return null;
        }

        return $user->notes()->find((int) $recentIds[0]);
    }

    private function resolveRequestedNote(User $user, string $message, array $state): ?Note
    {
        $target = $this->extractExplicitNoteTarget($message);
        if ($target === null) {
            return null;
        }

        $normalizer = app(IncomingMessageNormalizer::class);
        $normalizedTarget = $normalizer->normalize($target);
        $recentIds = array_values(array_filter($state['last_entities']['recent_note_ids'] ?? [], fn ($id) => (int) $id > 0));

        $query = $user->notes();
        if ($recentIds !== []) {
            $query->whereIn('id', $recentIds);
        }

        $notes = $query->latest('id')->get();

        return $notes->first(function (Note $note) use ($normalizer, $normalizedTarget) {
            $title = $normalizer->normalize((string) $note->title);
            $body = $normalizer->normalize((string) $note->body);

            return $title === $normalizedTarget
                || str_contains($title, $normalizedTarget)
                || str_contains($normalizedTarget, $title)
                || str_contains($body, $normalizedTarget);
        });
    }

    private function extractExplicitNoteTarget(string $message): ?string
    {
        $target = preg_replace('/^\s*(?:me\s+)?(?:mostra|mostrar|abre|abrir)\s+(?:essa|esse|a|o)?\s*nota\s*/iu', '', $message) ?? $message;
        $target = trim($target, " \t\n\r\0\x0B-:.,;!?");

        return $target !== '' ? $target : null;
    }

    private function containsAny(string $normalized, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($normalized, app(IncomingMessageNormalizer::class)->normalize($needle))) {
                return true;
            }
        }

        return false;
    }
}
