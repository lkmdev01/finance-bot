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
            ],
        ];
    }
}

