<?php

namespace App\Assistant\DTO;

class IncomingMessageDTO
{
    public function __construct(
        public readonly string $rawMessage,
        public readonly ?string $phoneNumber = null,
        public readonly ?string $pushName = null,
        public readonly ?string $remoteJid = null,
        public readonly ?string $imageUrl = null,
        public readonly ?int $incomingMediaId = null,
        public readonly ?string $normalizedMessage = null,
    ) {}

    public function withNormalizedMessage(string $normalizedMessage): self
    {
        return new self(
            rawMessage: $this->rawMessage,
            phoneNumber: $this->phoneNumber,
            pushName: $this->pushName,
            remoteJid: $this->remoteJid,
            imageUrl: $this->imageUrl,
            incomingMediaId: $this->incomingMediaId,
            normalizedMessage: $normalizedMessage,
        );
    }
}
