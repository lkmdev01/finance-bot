<?php

namespace App\Services\WhatsApp;

use App\Services\WhatsApp\Support\NormalizesWhatsAppText;

class NoteIntentClassifier
{
    use NormalizesWhatsAppText;

    public function __construct(
        private readonly NoteMessageParser $noteMessageParser,
    ) {}

    public function classify(string $originalMessage, string $normalizedMessage, array $state): ?array
    {
        if ($this->looksLikeNoteDelete($normalizedMessage, $state)) {
            return ['kind' => 'note_delete', 'normalized' => $normalizedMessage];
        }

        if ($this->looksLikeNoteEdit($normalizedMessage, $state)) {
            return ['kind' => 'note_edit', 'normalized' => $normalizedMessage];
        }

        if ($this->looksLikeNoteCreate($originalMessage, $normalizedMessage)) {
            return ['kind' => 'note_create', 'normalized' => $normalizedMessage];
        }

        if ($this->looksLikeNoteMissingContent($originalMessage, $normalizedMessage)) {
            return [
                'kind' => 'note_needs_content',
                'normalized' => $normalizedMessage,
                'payload' => $this->noteMessageParser->parsePartialCreate($originalMessage) ?? [],
            ];
        }

        if ($this->looksLikeNoteQuery($normalizedMessage, $state)) {
            return ['kind' => 'note_query', 'normalized' => $normalizedMessage];
        }

        return null;
    }

    private function looksLikeNoteCreate(string $originalMessage, string $normalizedMessage): bool
    {
        return $this->noteMessageParser->looksLikeCreateIntent($normalizedMessage)
            && $this->noteMessageParser->parseCreate($originalMessage) !== null;
    }

    private function looksLikeNoteMissingContent(string $originalMessage, string $normalizedMessage): bool
    {
        $partial = $this->noteMessageParser->parsePartialCreate($originalMessage);

        return $this->noteMessageParser->looksLikeCreateIntent($normalizedMessage)
            && $partial !== null
            && empty($partial['body']);
    }

    private function looksLikeNoteQuery(string $normalizedMessage, array $state): bool
    {
        $isNotesContext = ($state['last_action'] ?? null) === 'query_notes'
            || ($state['last_entities']['topic'] ?? null) === 'notes';

        if ($this->containsAnyText($normalizedMessage, [
            'arquivo',
            'arquivos',
            'documento',
            'documentos',
            'drive',
            'foto',
            'fotos',
            'imagem',
            'imagens',
            'audio',
            'audios',
            'comprovante',
            'contrato',
            'pdf',
        ]) && ! $this->containsAnyText($normalizedMessage, ['nota', 'notas'])) {
            return false;
        }

        if ($this->noteMessageParser->looksLikeQueryIntent($normalizedMessage)) {
            // Avoid treating "nota fiscal" as note query.
            if (str_contains($normalizedMessage, 'nota fiscal')) {
                return false;
            }

            return true;
        }

        return $isNotesContext
            && ! $this->containsAnyText($normalizedMessage, ['orcamento', 'gasto', 'receita', 'saldo', 'meta', 'assinatura', 'lembrete'])
            && $this->noteMessageParser->looksLikeContextualQueryFollowUp($normalizedMessage);
    }

    private function looksLikeNoteDelete(string $normalizedMessage, array $state): bool
    {
        $hasDeleteKeyword = $this->containsAnyText($normalizedMessage, ['apagar', 'apaga', 'deletar', 'deleta', 'remover', 'remove', 'excluir', 'exclui']);
        $hasNoteKeyword = $this->containsAnyText($normalizedMessage, ['nota', 'notas']);

        if ($hasDeleteKeyword && $hasNoteKeyword) {
            return true;
        }

        if (($state['last_entities']['topic'] ?? null) === 'notes' && $hasDeleteKeyword) {
            return true;
        }

        return false;
    }

    private function looksLikeNoteEdit(string $normalizedMessage, array $state): bool
    {
        $hasEditKeyword = $this->containsAnyText($normalizedMessage, ['editar', 'edita', 'alterar', 'altera', 'atualizar', 'atualiza']);
        $hasNoteKeyword = $this->containsAnyText($normalizedMessage, ['nota', 'notas']);

        if ($hasEditKeyword && $hasNoteKeyword) {
            return true;
        }

        return ($state['last_entities']['topic'] ?? null) === 'notes'
            && $hasEditKeyword;
    }
}
