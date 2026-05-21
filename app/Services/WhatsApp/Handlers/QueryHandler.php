<?php

namespace App\Services\WhatsApp\Handlers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\WhatsApp\BudgetConversationService;
use App\Services\WhatsApp\ConversationStateService;
use App\Services\WhatsApp\TransactionConversationService;
use Illuminate\Support\Facades\Log;

class QueryHandler extends BaseHandler
{
    private const QUERY_ACTIONS = [
        'query_balance',
        'query_expenses',
        'query_income',
        'query_transactions',
        'query_category',
        'query_savings',
        'query_budgets',
        'query_evolution',
        'query_projections',
        'query_income_source',
        'query_categories',
    ];

    public function canHandle(?string $action): bool
    {
        return in_array($action, self::QUERY_ACTIONS, true);
    }

    public function handle(?string $action, array &$result, User $user, WhatsAppContact $contact, ProcessWhatsAppMessage $job): bool
    {
        $rawMessage = $result['_resolved_message'] ?? $job->message;
        $entities = [];

        try {
            [$reply, $entities] = $this->buildQueryReply($user, $contact, $action, $result['reply'] ?? '', $rawMessage);
        } catch (\Throwable $exception) {
            Log::error('Falha ao montar resposta de consulta via WhatsApp', [
                'user_id' => $user->id,
                'action' => $action,
                'message' => $rawMessage,
                'error' => $exception->getMessage(),
            ]);

            $reply = 'Não consegui consultar esses dados agora. Tente novamente em instantes.';
        }

        $result['_conversation_metadata'] = array_merge($result['_conversation_metadata'] ?? [], [
            'reply_kind' => 'query',
            'entities' => array_merge($this->extractConversationEntities($action, $rawMessage), $entities),
        ]);

        Log::info('Consulta processada via WhatsApp', [
            'user_id' => $user->id,
            'action' => $action,
            'reply_length' => mb_strlen($reply),
        ]);

        $this->sendResponse($job, $reply, $user);

        return true;
    }

    private function buildQueryReply(User $user, WhatsAppContact $contact, ?string $action, string $fallbackReply, string $rawMessage): array
    {
        return match ($action) {
            'query_transactions', 'query_category' => $this->buildTransactionReplyData($user, $contact, $rawMessage, $action),
            'query_budgets' => $this->buildBudgetReplyData($user, $contact, $rawMessage),
            default => [$fallbackReply, []],
        };
    }

    private function buildBudgetReplyData(User $user, WhatsAppContact $contact, string $rawMessage): array
    {
        $state = app(ConversationStateService::class)->getState($contact);
        $data = app(BudgetConversationService::class)->buildReply($user, $rawMessage, $state);

        return [$data['reply'], $data['entities'] ?? []];
    }

    private function buildTransactionReplyData(User $user, WhatsAppContact $contact, string $rawMessage, ?string $action): array
    {
        $state = app(ConversationStateService::class)->getState($contact);
        $data = app(TransactionConversationService::class)->buildReply($user, $rawMessage, $state, $action);

        return [$data['reply'], $data['entities'] ?? []];
    }

    private function extractConversationEntities(?string $action, string $rawMessage): array
    {
        return match ($action) {
            'query_budgets' => ['topic' => 'budget'],
            'query_category' => ['topic' => 'expense_category'],
            'query_transactions' => ['topic' => 'transactions'],
            default => [],
        };
    }
}
