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
        if ($missingFieldPreflight = $this->missingFieldPreflight($intent)) {
            return new ActionResultDTO(
                preflight: $missingFieldPreflight,
                result: [],
                usedAI: false,
            );
        }

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

    private function missingFieldPreflight(ParsedIntentDTO $intent): ?array
    {
        if ($intent->missingFields === []) {
            return null;
        }

        [$reply, $pendingIntent, $pendingPayload] = match ($intent->intent) {
            FinancialIntent::CREATE_NOTE => [
                "Entendi. O que voce quer que eu salve na nota?\n\nExemplos:\n- anota: ideia para o projeto X\n- nota: lembrar de perguntar sobre o contrato",
                'create_note_content',
                ['note_data' => $intent->data],
            ],
            FinancialIntent::CREATE_REMINDER => [
                'Entendi o lembrete. Agora me diga quando devo te lembrar.',
                'create_reminder_schedule',
                ['reminder_data' => $intent->data],
            ],
            FinancialIntent::CREATE_DRIVE_FILE => [
                "Entendi. Agora me envie o arquivo/foto/audio que voce quer salvar no Drive.\n\nDepois disso, voce pode dizer:\n- salva isso no drive\n- salva na pasta de comprovantes/veiculos",
                'drive_save_waiting_media',
                ['drive_data' => $intent->data],
            ],
            FinancialIntent::CREATE_BUDGET => [
                $this->budgetMissingFieldReply($intent->missingFields),
                'create_budget_details',
                ['budget_data' => $intent->data],
            ],
            FinancialIntent::CREATE_GOAL => [
                $this->goalMissingFieldReply($intent->missingFields),
                'create_savings_goal_details',
                ['goal_data' => $intent->data],
            ],
            FinancialIntent::CREATE_SUBSCRIPTION => [
                $this->subscriptionMissingFieldReply($intent->missingFields),
                'create_subscription_details',
                ['subscription_data' => $intent->data],
            ],
            default => [null, null, null],
        };

        if ($reply === null || $pendingIntent === null) {
            return null;
        }

        return [
            'handled' => true,
            'reply' => $reply,
            'action' => null,
            'source' => 'assistant_router',
            'metadata' => [
                'clear_pending' => false,
                'reply_kind' => 'message',
                'pending_intent' => $pendingIntent,
                'pending_mode' => 'awaiting_clarification',
                'pending_payload' => $pendingPayload,
                'entities' => [
                    'topic' => $intent->domain,
                    'assistant_intent' => $intent->intent->value,
                ],
            ],
        ];
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
            FinancialIntent::CREATE_BUDGET => [
                'action' => 'create_budget',
                'reply' => $intent->raw['reply'] ?? '',
                'budget_data' => $intent->data,
            ],
            FinancialIntent::QUERY_BUDGETS => [
                'action' => 'query_budgets',
                'reply' => $intent->raw['reply'] ?? '',
            ],
            FinancialIntent::CREATE_GOAL => [
                'action' => 'create_savings_goal',
                'reply' => $intent->raw['reply'] ?? '',
                'goal_data' => $intent->data,
            ],
            FinancialIntent::QUERY_SAVINGS => [
                'action' => 'query_savings',
                'reply' => $intent->raw['reply'] ?? '',
            ],
            FinancialIntent::UPDATE_SAVINGS_GOAL => [
                'action' => 'update_savings_goal',
                'reply' => $intent->raw['reply'] ?? '',
                'goal_data' => $intent->data,
            ],
            FinancialIntent::CREATE_SUBSCRIPTION => [
                'action' => 'create_subscription',
                'reply' => $intent->raw['reply'] ?? '',
                'subscription_data' => $intent->data,
            ],
            FinancialIntent::QUERY_SUBSCRIPTIONS => [
                'action' => 'query_subscriptions',
                'reply' => $intent->raw['reply'] ?? '',
            ],
            FinancialIntent::UPDATE_SUBSCRIPTION => [
                'action' => 'update_subscription',
                'reply' => $intent->raw['reply'] ?? '',
                'subscription_data' => $intent->data,
            ],
            FinancialIntent::CANCEL_SUBSCRIPTION => [
                'action' => 'cancel_subscription',
                'reply' => $intent->raw['reply'] ?? '',
                'subscription_data' => $intent->data,
            ],
            FinancialIntent::CREATE_RECURRING_TRANSACTION => [
                'action' => 'create_recurring_transaction',
                'reply' => $intent->raw['reply'] ?? '',
                'recurring_data' => $intent->data,
            ],
            FinancialIntent::UPDATE_RECURRING_TRANSACTION => [
                'action' => 'update_recurring_transaction',
                'reply' => $intent->raw['reply'] ?? '',
                'recurring_data' => $intent->data,
            ],
            FinancialIntent::CANCEL_RECURRING_TRANSACTION => [
                'action' => 'cancel_recurring_transaction',
                'reply' => $intent->raw['reply'] ?? '',
                'recurring_data' => $intent->data,
            ],
            FinancialIntent::CREATE_NOTE => [
                'action' => 'create_note',
                'reply' => $intent->raw['reply'] ?? '',
                'note_data' => $intent->data,
            ],
            FinancialIntent::QUERY_NOTES => [
                'action' => 'query_notes',
                'reply' => $intent->raw['reply'] ?? '',
                'note_data' => $intent->data,
            ],
            FinancialIntent::CREATE_REMINDER => [
                'action' => 'create_reminder',
                'reply' => $intent->raw['reply'] ?? '',
                'reminder_data' => $intent->data,
            ],
            FinancialIntent::QUERY_REMINDERS => [
                'action' => 'query_reminders',
                'reply' => $intent->raw['reply'] ?? '',
            ],
            FinancialIntent::CREATE_DRIVE_FILE => [
                'action' => 'create_drive_file',
                'reply' => $intent->raw['reply'] ?? '',
                'drive_data' => $intent->data,
            ],
            FinancialIntent::QUERY_DRIVE_FILES => [
                'action' => 'query_drive_files',
                'reply' => $intent->raw['reply'] ?? '',
                'drive_data' => $intent->data,
            ],
            default => null,
        };
    }

    private function budgetMissingFieldReply(array $missingFields): string
    {
        $needsAmount = in_array('amount', $missingFields, true);
        $needsCategory = in_array('category_name', $missingFields, true);

        return match (true) {
            $needsAmount && $needsCategory => "Entendi o orcamento, mas faltou o valor e a categoria.\n\nExemplos:\n- 500 para compras\n- 800 para mercado em junho",
            $needsAmount => "Entendi a categoria do orcamento. Agora me diga o valor.\n\nExemplos:\n- 500\n- 800 em junho",
            $needsCategory => "Entendi o valor do orcamento. Agora me diga a categoria.\n\nExemplos:\n- para compras\n- mercado",
            default => "Me manda mais um detalhe para eu terminar esse orcamento.",
        };
    }

    private function goalMissingFieldReply(array $missingFields): string
    {
        $needsName = in_array('name', $missingFields, true);
        $needsAmount = in_array('target_amount', $missingFields, true);

        return match (true) {
            $needsName && $needsAmount => "Entendi a meta, mas faltou o nome e o valor objetivo.\n\nExemplos:\n- viagem 5000\n- meta carro 30000",
            $needsName => "Perfeito. Agora me diga o nome dessa meta.\n\nExemplos:\n- viagem europa\n- reserva de emergencia",
            $needsAmount => "Perfeito. Agora me diga o valor objetivo dessa meta.\n\nExemplos:\n- 5000\n- 30000 ate dezembro",
            default => "Me manda mais um detalhe para eu terminar essa meta.",
        };
    }

    private function subscriptionMissingFieldReply(array $missingFields): string
    {
        $needsName = in_array('name', $missingFields, true);
        $needsAmount = in_array('amount', $missingFields, true);
        $needsCycle = in_array('billing_cycle', $missingFields, true);
        $needsDueDay = in_array('due_day', $missingFields, true);

        return match (true) {
            $needsName && $needsAmount && $needsCycle && $needsDueDay => "Entendi a assinatura, mas faltaram nome, valor, ciclo e dia de vencimento.\n\nExemplo:\n- Netflix mensal dia 10 39,90",
            $needsAmount && $needsCycle && $needsDueDay => "Perfeito. Agora me diga o valor, se ela e mensal ou anual, e o dia do vencimento.\n\nExemplo:\n- 39,90 mensal dia 10",
            $needsAmount && $needsDueDay => "Perfeito. Agora me diga o valor e o dia do vencimento.\n\nExemplo:\n- 39,90 dia 10",
            $needsAmount => "Perfeito. Agora me diga o valor dessa assinatura.\n\nExemplos:\n- 39,90\n- 19 reais",
            $needsCycle => "Perfeito. Agora me diga se essa assinatura e mensal ou anual.",
            $needsDueDay => "Perfeito. Agora me diga o dia do vencimento.\n\nExemplos:\n- dia 10\n- vence dia 5",
            $needsName => "Perfeito. Agora me diga o nome da assinatura.\n\nExemplos:\n- Netflix\n- Spotify",
            default => "Me manda mais um detalhe para eu terminar essa assinatura.",
        };
    }
}
