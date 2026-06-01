<?php

namespace App\Services\WhatsApp;

use App\Services\WhatsApp\Support\NormalizesWhatsAppText;
use Illuminate\Support\Str;

class DriveMessageParser
{
    use NormalizesWhatsAppText;

    public function looksLikeSaveIntent(string $normalizedMessage, array $state): bool
    {
        $hasMedia = ! empty($state['last_entities']['incoming_media_id'] ?? null);
        if (! $hasMedia) {
            return false;
        }

        // When there is media, "salva/guarda/drive/pasta" are strong enough signals.
        return $this->containsAnyText($normalizedMessage, [
            'salva',
            'salvar',
            'salve',
            'guarda',
            'guardar',
            'arquiva',
            'arquivar',
            'drive',
            'pasta',
        ]);
    }

    public function looksLikeSaveWithoutMediaIntent(string $normalizedMessage, array $state): bool
    {
        $hasMedia = ! empty($state['last_entities']['incoming_media_id'] ?? null);
        if ($hasMedia) {
            return false;
        }

        // Without media, only treat as a Drive save intent when the user explicitly mentions
        // Drive/file semantics. Avoid misclassifying "quero guardar 5 mil" (savings goal).
        $hasSaveVerb = $this->containsAnyText($normalizedMessage, [
            'salva',
            'salvar',
            'salve',
            'guarda',
            'guardar',
            'arquiva',
            'arquivar',
        ]);

        if (! $hasSaveVerb) {
            return false;
        }

        $hasDriveNoun = $this->containsAnyText($normalizedMessage, [
            'drive',
            'arquivo',
            'documento',
            'foto',
            'imagem',
            'pdf',
            'pasta',
        ]);

        return $hasDriveNoun;
    }

    public function parseSave(string $message, array $state): ?array
    {
        $normalized = $this->normalizeText($message);
        if (! $this->looksLikeSaveIntent($normalized, $state)) {
            return null;
        }

        $incomingMediaId = (int) ($state['last_entities']['incoming_media_id'] ?? 0);
        if ($incomingMediaId <= 0) {
            return null;
        }

        $folder = $this->extractFolderHint($message);
        $autoFolderKey = $this->inferAutoFolderKey($normalized);

        return [
            'incoming_media_id' => $incomingMediaId,
            'folder_hint' => $folder,
            'auto_folder_key' => $autoFolderKey,
        ];
    }

    public function looksLikeQueryIntent(string $normalizedMessage, array $state): bool
    {
        if (($state['last_entities']['topic'] ?? null) === 'drive') {
            return true;
        }

        if ($this->containsAnyText($normalizedMessage, [
            'meus arquivos',
            'meu drive',
            'meus documentos',
        ])) {
            return true;
        }

        // Search verbs.
        $hasSearchVerb = $this->containsAnyText($normalizedMessage, [
            'acha',
            'ache',
            'achar',
            'procura',
            'procurar',
            'buscar',
            'busca',
            'encontra',
            'encontrar',
        ]);

        // Document nouns.
        $hasDocWord = $this->containsAnyText($normalizedMessage, [
            'arquivo',
            'documento',
            'comprovante',
            'contrato',
            'foto',
            'imagem',
            'pdf',
            'boleto',
            'nota fiscal',
        ]);

        return $hasSearchVerb && $hasDocWord;
    }

    public function extractQueryTerm(string $message): ?string
    {
        $clean = trim($message);
        $normalized = $this->normalizeText($clean);

        if ($normalized === '' || $normalized === 'meus arquivos' || $normalized === 'meus documentos') {
            return null;
        }

        // Drop common verbs and generic words.
        $subject = preg_replace('/\\b(?:acha|ache|achar|procura|procurar|buscar|busca|encontra|encontrar|mostrar|mostra|meu|minha|meus|minhas|arquivos?|documentos?|arquivo|documento)\\b/iu', ' ', $clean) ?? $clean;
        $subject = preg_replace('/\\b(?:o|a|os|as|um|uma|de|do|da|dos|das|sobre|pra|para|no|na|em)\\b/iu', ' ', $subject) ?? $subject;
        $subject = trim(preg_replace('/\\s+/u', ' ', $subject) ?? $subject);

        return $subject !== '' ? $subject : null;
    }

    private function extractFolderHint(string $message): ?string
    {
        // Examples:
        // - "salva isso na pasta de comprovantes"
        // - "salva no drive em comprovantes/veiculos"
        // - "guarda em contratos"
        $pattern = '/\\b(?:pasta|em|na|no)\\s+(?:a\\s+)?(?:pasta\\s+)?(?:de\\s+)?(?<folder>[A-Za-z0-9À-ÿ_\\-\\/ ]{2,120})/iu';

        if (preg_match($pattern, $message, $m) !== 1) {
            return null;
        }

        $folder = trim((string) ($m['folder'] ?? ''));
        if ($folder === '') {
            return null;
        }

        // Clean trailing punctuation / emojis.
        $folder = preg_replace('/[\\s\\.,;:!?]+$/u', '', $folder) ?? $folder;
        $folder = trim($folder);

        // Limit to a reasonable size.
        if (mb_strlen($folder) > 160) {
            $folder = mb_substr($folder, 0, 160);
        }

        return $folder !== '' ? $folder : null;
    }

    private function inferAutoFolderKey(string $normalizedMessage): ?string
    {
        // Very simple taxonomy for MVP.
        if ($this->containsAnyText($normalizedMessage, ['comprovante', 'recibo', 'boleto', 'nota fiscal'])) {
            return 'comprovantes';
        }

        if ($this->containsAnyText($normalizedMessage, ['contrato'])) {
            return 'contratos';
        }

        if ($this->containsAnyText($normalizedMessage, ['foto', 'imagem'])) {
            return 'fotos';
        }

        if ($this->containsAnyText($normalizedMessage, ['audio', 'voz'])) {
            return 'audios';
        }

        return null;
    }
}
