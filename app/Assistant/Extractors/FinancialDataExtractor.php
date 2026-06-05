<?php

namespace App\Assistant\Extractors;

use App\Assistant\DTO\AssistantContextDTO;
use App\Assistant\DTO\ParsedIntentDTO;

class FinancialDataExtractor
{
    public function extract(string $message, ParsedIntentDTO $intent, AssistantContextDTO $context): array
    {
        return [
            'message' => $message,
            'intent' => $intent->intent->value,
            'legacy_kind' => $intent->legacyKind,
            'confidence' => $intent->confidence,
            'data' => $intent->data,
            'missing_fields' => $intent->missingFields,
            'needs_confirmation' => $intent->needsConfirmation,
            'domain' => $intent->domain,
            'pending_action' => $context->pendingAction,
            'last_action' => $context->lastAction,
        ];
    }
}
