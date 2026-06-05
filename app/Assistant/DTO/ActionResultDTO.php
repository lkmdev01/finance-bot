<?php

namespace App\Assistant\DTO;

class ActionResultDTO
{
    public function __construct(
        public readonly array $preflight,
        public readonly array $result,
        public readonly bool $usedAI,
    ) {}
}
