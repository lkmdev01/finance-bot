<?php

namespace App\Services;

class DocumentTextExtractorService
{
    public function extractFromPath(string $path, ?string $mimeType = null): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $mimeType = $mimeType ?: 'application/octet-stream';

        if ($mimeType === 'text/plain') {
            $content = trim((string) file_get_contents($path));
            return $content !== '' ? $content : null;
        }

        if ($mimeType === 'application/pdf') {
            $binary = (string) file_get_contents($path);
            return $this->extractPdfTextFromBinary($binary);
        }

        return null;
    }

    // Heuristic extractor (same approach as WhatsAppDocumentService) for MVP.
    public function extractPdfTextFromBinary(string $binary): ?string
    {
        $chunks = [];

        if (preg_match_all('/\\((.*?)\\)/s', $binary, $matches)) {
            foreach ($matches[1] as $match) {
                $decoded = preg_replace('/\\\\([nrtbf\\\\()])/', ' ', stripcslashes($match));
                $decoded = preg_replace('/\\s+/', ' ', trim((string) $decoded));

                if (mb_strlen($decoded) >= 4) {
                    $chunks[] = $decoded;
                }
            }
        }

        if (empty($chunks) && preg_match_all('/[A-Za-z0-9À-ÿ$%.,:;\\/\\-\\s]{8,}/u', $binary, $matches)) {
            foreach ($matches[0] as $match) {
                $clean = preg_replace('/\\s+/', ' ', trim($match));
                if (mb_strlen($clean) >= 8) {
                    $chunks[] = $clean;
                }
            }
        }

        $text = trim(implode(' ', array_unique($chunks)));

        return mb_strlen($text) >= 20 ? $text : null;
    }
}

