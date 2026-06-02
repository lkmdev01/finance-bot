<?php

namespace App\Services\WhatsApp;

use App\Services\WhatsApp\Support\NormalizesWhatsAppText;

class DriveIntentClassifier
{
    use NormalizesWhatsAppText;

    public function __construct(
        private readonly DriveMessageParser $parser,
    ) {}

    public function classify(string $originalMessage, string $normalizedMessage, array $state): ?array
    {
        if ($this->parser->looksLikeSaveIntent($normalizedMessage, $state)) {
            return [
                'kind' => 'drive_save',
                'normalized' => $normalizedMessage,
                'payload' => $this->parser->parseSave($originalMessage, $state) ?? [],
            ];
        }

        if ($this->parser->looksLikeSaveWithoutMediaIntent($normalizedMessage, $state)) {
            return [
                'kind' => 'drive_needs_file',
                'normalized' => $normalizedMessage,
            ];
        }

        if ($this->parser->looksLikeQueryIntent($normalizedMessage, $state)) {
            return [
                'kind' => 'drive_query',
                'normalized' => $normalizedMessage,
            ];
        }

        return null;
    }
}

