<?php

namespace App\Assistant\DTO;

use App\Assistant\Enums\FinancialIntent;

class ParsedIntentDTO
{
    public function __construct(
        public readonly FinancialIntent $intent,
        public readonly float $confidence,
        public readonly array $data = [],
        public readonly array $missingFields = [],
        public readonly bool $needsConfirmation = false,
        public readonly ?string $domain = null,
        public readonly ?string $legacyKind = null,
        public readonly array $raw = [],
    ) {}
}
