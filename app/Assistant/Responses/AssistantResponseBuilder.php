<?php

namespace App\Assistant\Responses;

use App\Assistant\DTO\ActionResultDTO;
use App\Assistant\DTO\AssistantContextDTO;
use App\Assistant\DTO\AssistantResponseDTO;
use App\Assistant\DTO\IncomingMessageDTO;
use App\Assistant\DTO\ParsedIntentDTO;

class AssistantResponseBuilder
{
    public function build(
        IncomingMessageDTO $message,
        AssistantContextDTO $context,
        ParsedIntentDTO $intent,
        ActionResultDTO $actionResult,
    ): AssistantResponseDTO {
        return new AssistantResponseDTO(
            normalizedMessage: $message->normalizedMessage ?? $message->rawMessage,
            intent: $intent,
            context: $context,
            preflight: $actionResult->preflight,
            result: $actionResult->result,
            usedAI: $actionResult->usedAI,
        );
    }
}
