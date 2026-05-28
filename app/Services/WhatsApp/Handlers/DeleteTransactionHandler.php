<?php

namespace App\Services\WhatsApp\Handlers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\WhatsApp\ConversationStateService;
use App\Services\WhatsApp\TransactionReferenceResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DeleteTransactionHandler extends BaseHandler
{
    public function canHandle(?string $action): bool
    {
        return $action === 'delete_transaction';
    }

    public function handle(?string $action, array &$result, User $user, WhatsAppContact $contact, ProcessWhatsAppMessage $job): bool
    {
        $data = $this->normalizeData($result['transaction_data'] ?? []);
        $confirmed = (bool) ($data['confirmed'] ?? false);

        $resolver = app(TransactionReferenceResolver::class);
        $state = app(ConversationStateService::class)->getState($contact);
        $resolution = $resolver->resolve($user, $data, $state);

        if ($resolution['ambiguous'] ?? false) {
            $options = $resolver->formatAmbiguousOptions($resolution['matches']);
            $this->sendErrorMessage($job, "Encontrei mais de uma transacao parecida para apagar. Me diga qual delas voce quer remover:\n{$options}");
            return true;
        }

        /** @var Transaction|null $transaction */
        $transaction = $resolution['transaction'] ?? null;
        if (! $transaction instanceof Transaction) {
            $this->sendErrorMessage($job, 'Nao consegui identificar qual transacao apagar. Tente citar a descricao, a data ou diga ultimo gasto.');
            return true;
        }

        if (! $confirmed) {
            $result['_conversation_metadata'] = array_merge($result['_conversation_metadata'] ?? [], [
                'pending_intent' => 'delete_transaction',
                'pending_payload' => [
                    'transaction_data' => [
                        'transaction_id' => $transaction->id,
                        'confirmed' => true,
                    ],
                ],
                'clear_pending' => false,
                'reply_kind' => 'confirmation_request',
                'entities' => [
                    'topic' => 'transactions',
                    'transaction_id' => $transaction->id,
                    'latest_transaction_id' => $transaction->id,
                    'latest_transaction_description' => $transaction->description,
                    'transaction_type' => $transaction->type,
                    'category_name' => $transaction->category?->name,
                ],
            ]);

            $this->sendResponse($job, sprintf('Encontrei %s. Quer que eu apague essa transacao?', $resolver->formatTransactionLabel($transaction)), $user);
            return true;
        }

        $label = $resolver->formatTransactionLabel($transaction);
        $transactionId = $transaction->id;
        $beforeDelete = $transaction->only([
            'category_id',
            'bank_account_id',
            'credit_card_id',
            'type',
            'amount',
            'description',
            'date',
            'metadata',
        ]);
        $transaction->delete();

        Cache::forget("user.{$user->id}.financial_data");
        Cache::forget("user.{$user->id}.financial_projections");

        Log::info('Transacao deletada via WhatsApp', [
            'user_id' => $user->id,
            'transaction_id' => $transactionId,
            'resolution_source' => $resolution['source'] ?? null,
        ]);

        $result['_conversation_metadata'] = array_merge($result['_conversation_metadata'] ?? [], [
            'reply_kind' => 'action',
            'undo' => [
                'kind' => 'transaction_delete',
                'attributes' => $beforeDelete,
                'expires_at' => now()->addSeconds(60)->toIso8601String(),
            ],
            'entities' => [
                'topic' => 'transactions',
                'latest_transaction_description' => null,
            ],
        ]);

        $this->sendResponse($job, sprintf('Transacao apagada com sucesso: %s.', $label), $user);
        return true;
    }

    private function normalizeData(array $data): array
    {
        return [
            'transaction_id' => isset($data['transaction_id']) && is_numeric($data['transaction_id']) ? (int) $data['transaction_id'] : null,
            'target_description' => trim((string) ($data['target_description'] ?? '')) ?: null,
            'reference' => $data['reference'] ?? null,
            'target_date_scope' => $data['target_date_scope'] ?? null,
            'category_name' => isset($data['category_name']) && trim((string) $data['category_name']) !== '' ? trim((string) $data['category_name']) : null,
            'confirmed' => (bool) ($data['confirmed'] ?? false),
        ];
    }
}
