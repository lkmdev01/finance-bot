<?php

namespace App\Services\WhatsApp;

use App\Services\WhatsApp\Support\NormalizesWhatsAppText;
class DriveMessageParser
{
    use NormalizesWhatsAppText;

    private const QUERY_STOPWORDS = [
        'acha', 'ache', 'achar', 'procura', 'procurar', 'buscar', 'busca', 'encontra', 'encontrar',
        'mostra', 'mostrar', 'me', 'meu', 'minha', 'meus', 'minhas', 'o', 'a', 'os', 'as', 'um', 'uma',
        'de', 'do', 'da', 'dos', 'das', 'sobre', 'pra', 'para', 'no', 'na', 'nos', 'nas', 'em', 'que',
        'eu', 'voce', 'voces', 'salvei', 'mandei', 'tenho', 'ficou', 'novo', 'novo', 'de', 'novo',
        'tem', 'mais', 'so', 'só',
        'esse', 'essa', 'ele', 'ela', 'aquele', 'aquela', 'arquivo', 'arquivos', 'documento', 'documentos',
        'drive', 'hoje', 'ontem', 'manha', 'tarde', 'noite', 'qual', 'quais',
    ];

    public function looksLikeSaveIntent(string $normalizedMessage, array $state): bool
    {
        $hasMedia = ! empty($state['last_entities']['incoming_media_id'] ?? null);
        if (! $hasMedia) {
            return false;
        }

        // When there is media, explicit save commands or folder/drive directives are strong signals.
        return $this->hasExplicitSaveCue($normalizedMessage)
            || $this->containsAnyText($normalizedMessage, ['drive', 'pasta']);
    }

    public function looksLikeSaveWithoutMediaIntent(string $normalizedMessage, array $state): bool
    {
        $hasMedia = ! empty($state['last_entities']['incoming_media_id'] ?? null);
        if ($hasMedia) {
            return false;
        }

        if ($this->looksLikeQueryIntent($normalizedMessage, $state)) {
            return false;
        }

        if ($this->containsAnyText($normalizedMessage, [
            'nota', 'notas', 'anota', 'anotar', 'lembrete', 'lembretes', 'me lembra', 'me lembre',
        ])) {
            return false;
        }

        // Without media, only treat as a Drive save intent when the user explicitly mentions
        // Drive/file semantics. Avoid misclassifying "quero guardar 5 mil" (savings goal).
        $hasSaveVerb = $this->hasExplicitSaveCue($normalizedMessage);

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

    private function hasExplicitSaveCue(string $normalizedMessage): bool
    {
        return preg_match('/\b(?:salva(?:r)?|salve|guarda(?:r)?|arquiva(?:r)?)\b/u', $normalizedMessage) === 1;
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
        if (($state['last_entities']['topic'] ?? null) === 'drive'
            && ($this->isListingIntent($normalizedMessage) || $this->isContextualFollowUp($normalizedMessage))) {
            return true;
        }

        if ($this->containsAnyText($normalizedMessage, [
            'meus arquivos',
            'meu drive',
            'meus documentos',
            'quais arquivos',
            'quais documentos',
            'listar arquivos',
            'lista meus arquivos',
            'arquivos eu tenho',
            'arquivos eu salvei',
            'me mostra esse arquivo',
            'em qual pasta ficou',
            'qual pasta ficou',
            'procura ele de novo',
            'abrir o ',
            'abre o ',
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
            'audio',
            'audios',
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

        if ($normalized === '' || $this->isListingIntent($normalized) || $this->isContextualFollowUp($normalized)) {
            return null;
        }

        $words = preg_split('/\\s+/u', $normalized) ?: [];
        $filtered = [];

        foreach ($words as $word) {
            $word = trim($word);
            if ($word === '' || in_array($word, self::QUERY_STOPWORDS, true)) {
                continue;
            }

            $filtered[] = $word;
        }

        $subject = trim(implode(' ', $filtered));

        return $subject !== '' ? $subject : null;
    }

    public function parseQuery(string $message, array $state): array
    {
        $normalized = $this->normalizeText($message);
        $mediaKind = $this->extractMediaKind($normalized);
        $timeScope = $this->extractTimeScope($normalized);
        $isContextualFollowUp = ($state['last_entities']['topic'] ?? null) === 'drive'
            && $this->isContextualFollowUp($normalized);

        if ($isContextualFollowUp) {
            $mediaKind ??= $state['last_entities']['drive_media_kind'] ?? null;
            $timeScope ??= $state['last_entities']['drive_time_scope'] ?? null;
        }

        $term = $this->extractQueryTerm($message);
        $term = $this->sanitizeQueryTerm($term, $mediaKind, $timeScope);

        return [
            'term' => $term,
            'list_mode' => $this->isListingIntent($normalized),
            'follow_up' => $this->detectFollowUp($normalized),
            'ordinal' => $this->extractOrdinal($normalized),
            'open_reference' => $this->extractOpenReference($normalized),
            'time_scope' => $timeScope,
            'media_kind' => $mediaKind,
            'has_drive_context' => ($state['last_entities']['topic'] ?? null) === 'drive',
        ];
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

    private function isListingIntent(string $normalizedMessage): bool
    {
        return $this->containsAnyText($normalizedMessage, [
            'meus arquivos',
            'meu drive',
            'meus documentos',
            'quais arquivos',
            'quais documentos',
            'lista meus arquivos',
            'listar arquivos',
            'arquivos eu tenho',
            'arquivos eu salvei',
        ]);
    }

    private function isContextualFollowUp(string $normalizedMessage): bool
    {
        return $this->containsAnyText($normalizedMessage, [
            'em qual pasta ficou',
            'qual pasta ficou',
            'me mostra esse arquivo',
            'me mostra ele',
            'mostra esse arquivo',
            'procura ele de novo',
            'procura esse arquivo de novo',
            'so essa',
            'só essa',
            'apenas essa',
            'tem mais arquivo',
            'tem mais arquivos',
            'tem mais documento',
            'tem mais documentos',
            'tem mais foto',
            'tem mais fotos',
            'tem mais imagem',
            'tem mais imagens',
            'tem mais audio',
            'tem mais audios',
            'tem mais áudio',
            'tem mais áudios',
            'abrir o ',
            'abre o ',
        ]);
    }

    private function detectFollowUp(string $normalizedMessage): ?string
    {
        if ($this->containsAnyText($normalizedMessage, ['em qual pasta ficou', 'qual pasta ficou', 'que pasta ficou'])) {
            return 'show_folder';
        }

        if ($this->extractOrdinal($normalizedMessage) !== null) {
            return 'open_ordinal';
        }

        if ($this->extractOpenReference($normalizedMessage) !== null) {
            return 'open_reference';
        }

        if ($this->containsAnyText($normalizedMessage, ['so essa', 'só essa', 'apenas essa'])) {
            return 'only_match';
        }

        if ($this->containsAnyText($normalizedMessage, [
            'me mostra esse arquivo',
            'me mostra ele',
            'mostra esse arquivo',
            'mostra ele',
            'procura ele de novo',
            'procura esse arquivo de novo',
            'abre esse arquivo',
            'abre ele',
        ])) {
            return 'show_recent';
        }

        return null;
    }

    private function extractOrdinal(string $normalizedMessage): ?int
    {
        if (preg_match('/\\b(?:abrir|abre|mostrar|mostra)\\s+(?:o|a)?\\s*(\\d+)\\b/u', $normalizedMessage, $matches) !== 1) {
            return null;
        }

        $ordinal = (int) ($matches[1] ?? 0);

        if ($ordinal <= 0 || $ordinal > 20) {
            return null;
        }

        return $ordinal;
    }

    private function extractOpenReference(string $normalizedMessage): ?string
    {
        if (preg_match('/\\b(?:abrir|abre|mostrar|mostra)\\s+(?:o|a)?\\s+(.+)$/u', $normalizedMessage, $matches) !== 1) {
            return null;
        }

        $reference = trim((string) ($matches[1] ?? ''));
        if ($reference === '') {
            return null;
        }

        $tokens = array_values(array_filter(preg_split('/\\s+/u', $reference) ?: []));
        $tokens = array_values(array_filter($tokens, fn (string $token) => ! in_array($token, self::QUERY_STOPWORDS, true)));

        $reference = trim(implode(' ', $tokens));

        return $reference !== '' ? $reference : null;
    }

    private function extractTimeScope(string $normalizedMessage): ?string
    {
        if ($this->containsAnyText($normalizedMessage, ['hoje de manha', 'hoje manha'])) {
            return 'today_morning';
        }

        if ($this->containsAnyText($normalizedMessage, ['hoje'])) {
            return 'today';
        }

        if ($this->containsAnyText($normalizedMessage, ['ontem'])) {
            return 'yesterday';
        }

        return null;
    }

    private function extractMediaKind(string $normalizedMessage): ?string
    {
        if ($this->containsAnyText($normalizedMessage, ['foto', 'imagem', 'print'])) {
            return 'image';
        }

        if ($this->containsAnyText($normalizedMessage, ['audio', 'voz', 'mp3'])) {
            return 'audio';
        }

        if ($this->containsAnyText($normalizedMessage, ['pdf', 'contrato', 'comprovante', 'boleto', 'documento'])) {
            return 'document';
        }

        return null;
    }

    private function sanitizeQueryTerm(?string $term, ?string $mediaKind, ?string $timeScope): ?string
    {
        if ($term === null) {
            return null;
        }

        $normalizedTerm = preg_replace('/[^a-z0-9\s]+/u', ' ', $this->normalizeText($term)) ?? $this->normalizeText($term);
        $normalizedTerm = trim(preg_replace('/\s+/u', ' ', $normalizedTerm) ?? $normalizedTerm);

        $genericByMediaKind = [
            'image' => ['foto', 'fotos', 'imagem', 'imagens', 'print', 'prints'],
            'audio' => ['audio', 'audios', 'áudio', 'áudios', 'voz', 'mp3'],
            'document' => ['documento', 'documentos', 'arquivo', 'arquivos', 'pdf', 'comprovante', 'comprovantes', 'contrato', 'contratos', 'boleto', 'boletos'],
        ];

        $genericTerms = $genericByMediaKind[$mediaKind] ?? [];
        if (in_array($normalizedTerm, $genericTerms, true)) {
            return null;
        }

        return $term;
    }
}
