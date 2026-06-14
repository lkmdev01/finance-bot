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

                $tokenGroups = $this->searchTokenGroups($q);
                if ($tokenGroups !== []) {
                    $builder->orWhere(function ($tokenMatch) use ($tokenGroups) {
                        foreach ($tokenGroups as $variants) {
                            $tokenMatch->where(function ($nested) use ($variants) {
                                foreach ($variants as $variant) {
                                    $nested
                                        ->orWhere('title', 'like', '%'.$variant.'%')
                                        ->orWhere('body', 'like', '%'.$variant.'%');
                                }
                            });
                        }
                    });
                }
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

        if ($term !== null && $notes->count() === 1 && $this->looksLikeOpenRequest($normalized)) {
            $note = $notes->first();

            return [
                'reply' => "Aqui esta a nota {$note->title}.\n\n{$note->body}",
                'entities' => [
                    'topic' => 'notes',
                    'note_id' => $note->id,
                    'note_title' => $note->title,
                    'query_term' => $term,
                    'recent_note_ids' => [$note->id],
                    'note_result_count' => 1,
                ],
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

            return sprintf('%d. %s%s: %s', $index + 1, $date ? "{$date} " : '', $title, $preview);
        })->implode("\n");

        $first = $notes->first();

        $openHint = $notes->count() > 1 ? 'abrir nota 2' : 'abrir nota 1';

        return [
            'reply' => $header."\n".$lines."\n\nDica: diga \"{$openHint}\" ou \"apagar nota <titulo>\".",
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

        $count = (int) ($state['last_entities']['note_result_count'] ?? 0);
        $queryTerm = $state['last_entities']['query_term'] ?? null;
        $selection = $this->extractSelectionIndex($normalized);

        if ($selection !== null && $this->containsAny($normalized, ['abrir nota', 'abre nota', 'mostrar nota', 'mostra nota'])) {
            $selectedNote = $this->resolveNoteBySelection($user, $selection, $state);

            if (! $selectedNote) {
                return [
                    'reply' => "Nao encontrei a nota {$selection} nessa lista. Tenho ".max(0, $count)." nota(s) no resultado atual. Diga \"minhas notas\" para listar de novo.",
                    'entities' => [
                        'topic' => 'notes',
                        'query_term' => $queryTerm,
                        'recent_note_ids' => $state['last_entities']['recent_note_ids'] ?? [],
                        'note_result_count' => max(0, $count),
                    ],
                ];
            }

            return [
                'reply' => "Aqui esta a nota {$selectedNote->title}.\n\n{$selectedNote->body}",
                'entities' => [
                    'topic' => 'notes',
                    'note_id' => $selectedNote->id,
                    'note_title' => $selectedNote->title,
                    'query_term' => $queryTerm,
                    'recent_note_ids' => $state['last_entities']['recent_note_ids'] ?? [],
                    'note_result_count' => max(1, $count),
                ],
            ];
        }

        $recentNote = $this->resolveRequestedNote($user, $message, $state)
            ?? $this->resolveRecentNote($user, $state);

        if ($this->containsAny($normalized, ['me mostra essa nota', 'me mostra ela', 'mostra essa nota', 'abre essa nota', 'abrir nota', 'abre nota'])) {
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

    private function resolveNoteBySelection(User $user, int $selection, array $state): ?Note
    {
        $index = $selection - 1;
        $recentIds = array_values(array_filter($state['last_entities']['recent_note_ids'] ?? [], fn ($id) => (int) $id > 0));

        if ($index < 0 || ! isset($recentIds[$index])) {
            return null;
        }

        return $user->notes()->find((int) $recentIds[$index]);
    }

    private function extractSelectionIndex(string $normalized): ?int
    {
        if (preg_match('/\b(?:abrir|abre|mostrar|mostra)?\s*(?:a|o|nota)?\s*(\d{1,2})\b/u', $normalized, $matches) !== 1) {
            return null;
        }

        $selection = (int) $matches[1];

        return $selection > 0 ? $selection : null;
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

    /**
     * @return array<int, string>
     */
    private function searchTokenGroups(string $term): array
    {
        $normalizer = app(IncomingMessageNormalizer::class);
        $originalTokens = preg_split('/\s+/u', trim($term)) ?: [];
        $groups = [];

        foreach ($originalTokens as $originalToken) {
            $originalToken = trim($originalToken, " \t\n\r\0\x0B-:.,;!?");
            $normalizedToken = $normalizer->normalize($originalToken);

            if (mb_strlen($normalizedToken) < 4
                || in_array($normalizedToken, [
                    'nota',
                    'notas',
                    'essa',
                    'esse',
                    'minha',
                    'meu',
                    'sobre',
                ], true)) {
                continue;
            }

            $groups[] = array_values(array_unique(array_filter([
                $originalToken,
                $normalizedToken,
            ], fn (string $token) => $token !== '')));
        }

        return $groups;
    }

    private function looksLikeOpenRequest(string $normalized): bool
    {
        return $this->containsAny($normalized, [
            'me mostra',
            'mostra',
            'mostrar',
            'abre',
            'abrir',
            'consulta',
            'consultar',
        ]);
    }
}
