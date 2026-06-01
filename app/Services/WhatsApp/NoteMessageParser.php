<?php

namespace App\Services\WhatsApp;

use App\Services\WhatsApp\Support\NormalizesWhatsAppText;
use Illuminate\Support\Str;

class NoteMessageParser
{
    use NormalizesWhatsAppText;

    public function looksLikeCreateIntent(string $normalizedMessage): bool
    {
        return $this->containsAnyText($normalizedMessage, [
            'anota',
            'anotar',
            'anote',
            'nota:',
            'nota ',
            'salvar nota',
            'salva nota',
            'salvar isso',
            'salva isso',
            'guardar isso',
            'guarda isso',
        ]);
    }

    public function parseCreate(string $message): ?array
    {
        $clean = trim($message);
        $normalized = $this->normalizeText($clean);

        if (! $this->looksLikeCreateIntent($normalized)) {
            return null;
        }

        $body = $this->extractBody($clean);
        if ($body === null || trim($body) === '') {
            return null;
        }

        $body = trim($body);
        $title = $this->inferTitle($body);

        return [
            'title' => $title,
            'body' => $body,
            'source' => 'whatsapp',
            'metadata' => [
                'raw' => $clean,
            ],
        ];
    }

    public function parsePartialCreate(string $message): ?array
    {
        $clean = trim($message);
        $normalized = $this->normalizeText($clean);

        if (! $this->looksLikeCreateIntent($normalized)) {
            return null;
        }

        $body = $this->extractBody($clean);

        return [
            'body' => $body !== null ? trim($body) : null,
            'source' => 'whatsapp',
            'metadata' => [
                'raw' => $clean,
            ],
        ];
    }

    public function extractQueryTerm(string $message): ?string
    {
        $clean = trim($message);
        $normalized = $this->normalizeText($clean);

        if (! $this->looksLikeQueryIntent($normalized)) {
            return null;
        }

        $subject = preg_replace('/\\b(?:minhas|minha|meus|meu|quais|qual|liste|listar|lista|mostra|mostrar|procura|procurar|buscar|busca|encontra|encontrar|consulta|consultar)\\b/iu', ' ', $clean) ?? $clean;
        $subject = preg_replace('/\\b(?:nota|notas)\\b/iu', ' ', $subject) ?? $subject;
        $subject = preg_replace('/\\b(?:sobre|do|da|de)\\b/iu', ' ', $subject) ?? $subject;
        $subject = trim(preg_replace('/\\s+/u', ' ', $subject) ?? $subject);

        return $subject !== '' ? $subject : null;
    }

    public function looksLikeQueryIntent(string $normalizedMessage): bool
    {
        // Avoid false positives like "anota" containing "nota" as a substring.
        if (preg_match('/\\bminhas?\\s+notas?\\b/u', $normalizedMessage) === 1) {
            return true;
        }

        if (preg_match('/\\bnotas?\\b/u', $normalizedMessage) === 1) {
            return true;
        }

        return $this->containsAnyText($normalizedMessage, [
            'buscar nota',
            'busca nota',
            'procura nota',
            'procurar nota',
            'encontra nota',
            'consultar nota',
            'consulta nota',
            'o que eu anotei',
            'lembra da nota',
        ]);
    }

    private function extractBody(string $message): ?string
    {
        $body = $message;

        // "anota: ..." / "nota: ..." / "salva isso: ..."
        $body = preg_replace('/^(?:anota(?:\\s+isso)?|anotar|anote|salvar\\s+nota|salva\\s+nota|salvar\\s+isso|salva\\s+isso|guardar\\s+isso|guarda\\s+isso|nota)\\s*[:\\-–,]?\\s*/iu', '', $body) ?? $body;

        // "anota que ..."
        $body = preg_replace('/^\\s*(?:que|para|pra)\\s+/iu', '', $body) ?? $body;

        $body = trim($body);

        return $body === '' ? null : $body;
    }

    private function inferTitle(string $body): string
    {
        $firstLine = trim((string) Str::of($body)->explode("\n")->first());
        $firstLine = preg_replace('/\\s+/u', ' ', $firstLine) ?? $firstLine;
        $firstLine = trim($firstLine);

        if ($firstLine === '') {
            return 'Nota';
        }

        // Limit title length; keep it readable.
        if (mb_strlen($firstLine) > 80) {
            $firstLine = mb_substr($firstLine, 0, 80);
            $firstLine = rtrim($firstLine, " \t\n\r\0\x0B.,;:-");
        }

        // Title-case but keep acronyms reasonably.
        $title = mb_convert_case($firstLine, MB_CASE_TITLE, 'UTF-8');

        return $title !== '' ? $title : 'Nota';
    }
}
