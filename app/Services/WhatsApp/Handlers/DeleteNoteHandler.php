<?php

namespace App\Services\WhatsApp\Handlers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Note;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\WhatsApp\IncomingMessageNormalizer;
use Illuminate\Support\Collection;

class DeleteNoteHandler extends BaseHandler
{
    public function canHandle(?string $action): bool
    {
        return $action === 'delete_note';
    }

    public function handle(?string $action, array &$result, User $user, WhatsAppContact $contact, ProcessWhatsAppMessage $job): bool
    {
        $normalized = $this->normalizeMessage($result['_resolved_message'] ?? $job->message ?? '');
        $notes = $user->notes()->orderByDesc('id')->limit(50)->get();
        $resolvedNote = $this->resolveFromResult($user, $result['note_data'] ?? []);

        if ($notes->isEmpty()) {
            $this->sendErrorMessage($job, 'Voce nao tem notas para apagar.');
            return true;
        }

        if ($resolvedNote) {
            return $this->deleteOne($resolvedNote, $result, $job, $user);
        }

        // "apaga essa" after listing notes.
        if ($this->containsAny($normalized, ['essa', 'esse', 'ultima', 'ultimo']) && ! empty($contact->conversation_state['last_entities']['note_id'] ?? null)) {
            $noteId = (int) $contact->conversation_state['last_entities']['note_id'];
            $note = $user->notes()->find($noteId);
            if ($note) {
                return $this->deleteOne($note, $result, $job, $user);
            }
        }

        $title = $this->extractNoteTitleFromMessage($job->message);
        if ($title !== null) {
            $matches = $this->findMatchingNotes($notes, $title);

            if ($matches->count() === 1) {
                return $this->deleteOne($matches->first(), $result, $job, $user);
            }

            if ($matches->count() > 1) {
                $this->sendErrorMessage($job, "Encontrei varias notas parecidas com '{$title}'. Qual voce quer apagar?\n\n{$this->renderNoteOptions($matches)}");
                return true;
            }

            $this->sendErrorMessage($job, "Nao encontrei nenhuma nota com o nome '{$title}'.\n\n{$this->renderNoteOptions($notes)}");
            return true;
        }

        // If user didn't provide title, default to the latest note.
        return $this->deleteOne($notes->first(), $result, $job, $user);
    }

    private function deleteOne(Note $note, array &$result, ProcessWhatsAppMessage $job, User $user): bool
    {
        $attributes = $note->only(['user_id', 'title', 'body', 'source', 'metadata', 'created_at', 'updated_at']);
        $note->delete();

        $result['_conversation_metadata'] = array_merge($result['_conversation_metadata'] ?? [], [
            'reply_kind' => 'action',
            'undo' => [
                'kind' => 'note_delete',
                'attributes' => $attributes,
                'expires_at' => now()->addSeconds(60)->toIso8601String(),
            ],
            'entities' => [
                'topic' => 'notes',
                'note_title' => $note->title,
            ],
        ]);

        $this->sendResponse($job, "Nota '{$note->title}' apagada com sucesso.", $user);
        return true;
    }

    private function resolveFromResult(User $user, mixed $noteData): ?Note
    {
        if (! is_array($noteData)) {
            return null;
        }

        $noteId = (int) ($noteData['note_id'] ?? 0);
        if ($noteId > 0) {
            return $user->notes()->find($noteId);
        }

        $title = trim((string) ($noteData['current_title'] ?? ''));
        if ($title === '') {
            return null;
        }

        $matches = $this->findMatchingNotes($user->notes()->orderByDesc('id')->limit(50)->get(), $title);

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function extractNoteTitleFromMessage(string $message): ?string
    {
        $subject = preg_replace('/\\b(?:apagar|apaga|apague|deletar|deleta|remover|remove|excluir|exclui|cancelar|cancela)\\b/iu', '', $message) ?? $message;
        $subject = preg_replace('/\\b(?:o|a|os|as|um|uma|minhas|minha|meus|meu|esse|essa|este|esta)\\b/iu', ' ', $subject) ?? $subject;
        $subject = preg_replace('/\\b(?:nota|notas)\\b/iu', ' ', $subject) ?? $subject;
        $subject = trim(preg_replace('/\\s+/u', ' ', $subject) ?? $subject);

        return $subject !== '' ? $subject : null;
    }

    /**
     * @param \Illuminate\Support\Collection<int, Note> $notes
     */
    private function findMatchingNotes(\Illuminate\Support\Collection $notes, string $title)
    {
        $normalizer = app(IncomingMessageNormalizer::class);
        $search = $normalizer->normalize($title);

        $exact = $notes->filter(fn ($note) => $normalizer->normalize($note->title) === $search);
        if ($exact->isNotEmpty()) {
            return $exact;
        }

        return $notes->filter(fn ($note) => str_contains($normalizer->normalize($note->title), $search));
    }

    private function renderNoteOptions(Collection $notes): string
    {
        return $notes->values()->take(8)->map(fn ($note, $index) => ($index + 1).". {$note->title}")->implode("\n");
    }

    private function normalizeMessage(string $message): string
    {
        return app(IncomingMessageNormalizer::class)->normalize($message);
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, (string) $needle)) {
                return true;
            }
        }

        return false;
    }
}
