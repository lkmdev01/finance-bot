<?php

namespace App\Assistant\DTO;

class AssistantResponseDTO
{
    public function __construct(
        public readonly string $normalizedMessage,
        public readonly ParsedIntentDTO $intent,
        public readonly AssistantContextDTO $context,
        public readonly array $preflight,
        public readonly array $result,
        public readonly bool $usedAI,
    ) {}

    public function isHandledPreflight(): bool
    {
        return ($this->preflight['handled'] ?? false) === true;
    }

    public function action(): ?string
    {
        return $this->result['action']
            ?? $this->preflight['action']
            ?? ($this->preflight['result']['action'] ?? null);
    }
}
