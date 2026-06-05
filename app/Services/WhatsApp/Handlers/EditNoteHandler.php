<?php

namespace App\Services\WhatsApp\Handlers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Note;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\WhatsApp\IncomingMessageNormalizer;

class EditNoteHandler extends BaseHandler
{
    public function canHandle(?string $action): bool
    {
        return $action === 'edit_note';
    }

    public function handle(?string $action, array &$result, User $user, WhatsAppContact $contact, ProcessWhatsAppMessage $job): bool
    {
        $noteData = is_array($result['note_data'] ?? null) ? $result['note_data'] : [];
        $note = $this->resolveNote($user, $contact, $noteData, $job->message);

        if (! $note) {
            $this->sendErrorMessage($job, 'Nao encontrei a nota que voce quer editar.');
            return true;
        }

        $body = trim((string) ($noteData['body'] ?? ''));
        if ($body === '') {
            $this->sendErrorMessage($job, "Ainda faltou o novo conteudo da nota '{$note->title}'.");
            return true;
        }

        $before = $note->only(['title', 'body', 'source', 'metadata']);
        $note->update([
            'body' => $body,
        ]);

        $result['_conversation_metadata'] = array_merge($result['_conversation_metadata'] ?? [], [
            'reply_kind' => 'action',
            'undo' => [
                'kind' => 'note_update',
                'id' => $note->id,
                'before' => $before,
                'expires_at' => now()->addSeconds(60)->toIso8601String(),
            ],
            'entities' => [
                'topic' => 'notes',
                'note_id' => $note->id,
                'note_title' => $note->title,
            ],
        ]);

        $this->sendResponse($job, "Nota '{$note->title}' atualizada com sucesso.", $user);

        return true;
    }

    private function resolveNote(User $user, WhatsAppContact $contact, array $noteData, string $rawMessage): ?Note
    {
        $noteId = (int) ($noteData['note_id'] ?? ($contact->conversation_state['last_entities']['note_id'] ?? 0));
        if ($noteId > 0) {
            return $user->notes()->find($noteId);
        }

        $title = trim((string) ($noteData['current_title'] ?? ''));
        if ($title === '') {
            $title = app(\App\Services\WhatsApp\NoteMessageParser::class)->extractActionTarget($rawMessage) ?? '';
        }

        if ($title === '') {
            return null;
        }

        $normalizedTitle = app(IncomingMessageNormalizer::class)->normalize($title);

        return $user->notes()
            ->get()
            ->first(function (Note $note) use ($normalizedTitle) {
                $current = app(IncomingMessageNormalizer::class)->normalize($note->title);

                return $current === $normalizedTitle || str_contains($current, $normalizedTitle);
            });
    }
}
