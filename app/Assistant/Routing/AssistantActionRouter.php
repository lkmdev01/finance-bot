<?php

namespace App\Assistant\Routing;

use App\Assistant\DTO\ActionResultDTO;
use App\Assistant\DTO\AssistantContextDTO;
use App\Assistant\DTO\IncomingMessageDTO;
use App\Assistant\DTO\ParsedIntentDTO;
use App\Assistant\Enums\FinancialIntent;
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
        if ($deterministic = $this->deterministicResultForIntent($intent)) {
            return new ActionResultDTO(
                preflight: ['handled' => false, 'source' => 'assistant_router'],
                result: $deterministic,
                usedAI: false,
            );
        }

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

    private function deterministicResultForIntent(ParsedIntentDTO $intent): ?array
    {
        return match ($intent->intent) {
            FinancialIntent::CREATE_EXPENSE,
            FinancialIntent::CREATE_INCOME => [
                'action' => 'create_transaction',
                'reply' => $intent->raw['reply'] ?? 'Anotado.',
                'transaction_data' => $intent->data,
            ],
            FinancialIntent::QUERY_BALANCE => [
                'action' => 'query_balance',
                'reply' => $intent->raw['reply'] ?? '',
            ],
            FinancialIntent::QUERY_MONTH_REPORT => [
                'action' => 'query_expenses',
                'reply' => $intent->raw['reply'] ?? '',
            ],
            FinancialIntent::QUERY_CATEGORY_SPENDING => [
                'action' => 'query_category',
                'reply' => $intent->raw['reply'] ?? '',
            ],
            FinancialIntent::LIST_TRANSACTIONS => [
                'action' => 'query_transactions',
                'reply' => $intent->raw['reply'] ?? '',
            ],
            FinancialIntent::UPDATE_TRANSACTION => [
                'action' => 'edit_transaction',
                'reply' => $intent->raw['reply'] ?? '',
                'transaction_data' => $intent->data,
            ],
            FinancialIntent::DELETE_TRANSACTION => [
                'action' => 'delete_transaction',
                'reply' => $intent->raw['reply'] ?? '',
                'transaction_data' => $intent->data,
            ],
            default => null,
        };
    }
}
