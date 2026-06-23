<?php

namespace App\Services\WhatsApp;

class WhatsAppResponseBuilder
{
    /**
     * @param  array<string, string|null>  $details
     * @param  array<int, string>  $next
     */
    public function success(string $headline, array $details = [], array $next = []): string
    {
        $reply = trim($headline);

        $detailLines = $this->details($details);
        if ($detailLines !== '') {
            $reply .= "\n\n".$detailLines;
        }

        if ($next !== []) {
            $reply .= "\n\n".$this->next($next);
        }

        return $reply;
    }

    /**
     * @param  array<int, string>  $items
     */
    public function list(string $headline, array $items, bool $numbered = false): string
    {
        $lines = [];

        foreach (array_values($items) as $index => $item) {
            $lines[] = $numbered
                ? sprintf('%d. %s', $index + 1, $item)
                : '- '.$item;
        }

        return trim($headline)."\n\n".implode("\n", $lines);
    }

    /**
     * @param  array<int, string>  $examples
     */
    public function guidance(string $headline, array $examples, ?string $details = null): string
    {
        $reply = trim($headline)."\n\nTente assim:";

        foreach ($examples as $example) {
            $reply .= "\n- ".$example;
        }

        if ($details !== null && trim($details) !== '') {
            $reply .= "\n\nDetalhe: ".trim($details);
        }

        return $reply;
    }

    /**
     * @param  array<int, string>  $examples
     */
    public function empty(string $headline, array $examples = []): string
    {
        $reply = trim($headline);

        if ($examples !== []) {
            $reply .= "\n\nVoce pode tentar:";
            foreach ($examples as $example) {
                $reply .= "\n- ".$example;
            }
        }

        return $reply;
    }

    /**
     * @param  array<string, string|null>  $details
     */
    public function details(array $details): string
    {
        $lines = [];

        foreach ($details as $label => $value) {
            if ($value === null || trim($value) === '') {
                continue;
            }

            $lines[] = "{$label}: {$value}";
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, string>  $items
     */
    public function next(array $items): string
    {
        $reply = 'Voce pode continuar com:';

        foreach ($items as $item) {
            $reply .= "\n- ".$item;
        }

        return $reply;
    }
}
