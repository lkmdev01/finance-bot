<?php

namespace App\Assistant\Routing;

use App\Assistant\DTO\ActionResultDTO;
use App\Assistant\DTO\AssistantContextDTO;
use App\Assistant\DTO\IncomingMessageDTO;
use App\Assistant\DTO\ParsedIntentDTO;
use App\Services\WhatsApp\ConversationOrchestrator;
use App\Services\WhatsAppMessageProcessor;

class AssistantActionRouter
{
    public function __construct(
        private readonly ConversationOrchestrator $conversationOrchestrator,
        private readonly WhatsAppMessageProcessor $messageProcessor,
    ) {}

    public function execute(
        IncomingMessageDTO $message,
        AssistantContextDTO $context,
        ParsedIntentDTO $intent,
        array $extractedData,
    ): ActionResultDTO {
        $preflight = $this->conversationOrchestrator->beforeAI(
            $message->normalizedMessage ?? $message->rawMessage,
            $context->user,
            $context->contact,
        );

        if (($preflight['handled'] ?? false) === true) {
            return new ActionResultDTO(
                preflight: $preflight,
                result: [],
                usedAI: false,
            );
        }

        if (isset($preflight['result'])) {
            return new ActionResultDTO(
                preflight: $preflight,
                result: $preflight['result'],
                usedAI: false,
            );
        }

        return new ActionResultDTO(
            preflight: $preflight,
            result: $this->messageProcessor->process($message->normalizedMessage ?? $message->rawMessage, $context->user, $context->contact),
            usedAI: true,
        );
    }
}
