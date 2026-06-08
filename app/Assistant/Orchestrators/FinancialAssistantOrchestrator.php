<?php

namespace App\Assistant\Orchestrators;

use App\Assistant\Classifiers\IntentClassifier;
use App\Assistant\Context\AssistantContextBuilder;
use App\Assistant\DTO\AssistantResponseDTO;
use App\Assistant\DTO\IncomingMessageDTO;
use App\Assistant\Extractors\FinancialDataExtractor;
use App\Assistant\Responses\AssistantResponseBuilder;
use App\Assistant\Routing\AssistantActionRouter;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\WhatsApp\IncomingMessageNormalizer;

class FinancialAssistantOrchestrator
{
    public function __construct(
        private readonly IncomingMessageNormalizer $normalizer,
        private readonly AssistantContextBuilder $contextBuilder,
        private readonly IntentClassifier $intentClassifier,
        private readonly FinancialDataExtractor $dataExtractor,
        private readonly AssistantActionRouter $actionRouter,
        private readonly AssistantResponseBuilder $responseBuilder,
    ) {}

    public function handle(User $user, WhatsAppContact $contact, IncomingMessageDTO $message): AssistantResponseDTO
    {
        $normalizedMessage = $this->normalizer->clean($message->rawMessage);
        $message = $message->withNormalizedMessage($normalizedMessage);

        $context = $this->contextBuilder->build($user, $contact);
        $intent = $this->intentClassifier->classify($normalizedMessage, $context);
        $extractedData = $this->dataExtractor->extract($normalizedMessage, $intent, $context);
        $actionResult = $this->actionRouter->execute($message, $context, $intent, $extractedData);

        return $this->responseBuilder->build($message, $context, $intent, $actionResult);
    }
}
