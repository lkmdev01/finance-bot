<?php

namespace App\Services\WhatsApp\Handlers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\CategoryRecognitionService;
use App\Services\WhatsApp\ConversationStateService;
use App\Services\WhatsApp\TransactionReferenceResolver;
use Illuminate\Support\Facades\Cache;

class SplitTransactionHandler extends BaseHandler
{
    public function canHandle(?string $action): bool
    {
        return $action === 'split_transaction';
    }

    public function handle(?string $action, array &$result, User $user, WhatsAppContact $contact, ProcessWhatsAppMessage $job): bool
    {
        $data = $result['transaction_data'] ?? [];
        $resolver = app(TransactionReferenceResolver::class);
        $state = app(ConversationStateService::class)->getState($contact);
        $resolution = $resolver->resolve($user, $data, $state);

        /** @var Transaction|null $transaction */
        $transaction = $resolution['transaction'] ?? null;
        if (! $transaction instanceof Transaction) {
            $this->sendErrorMessage($job, 'Nao consegui identificar qual lancamento voce quer dividir.');
            return true;
        }

        $items = collect($data['split_items'] ?? [])->filter(function ($item) {
            return ! empty($item['category_name']) && ! empty($item['amount']);
        })->values();

        if ($items->isEmpty()) {
            $result['_conversation_metadata'] = array_merge($result['_conversation_metadata'] ?? [], [
                'pending_intent' => 'split_transaction_details',
                'pending_mode' => 'awaiting_clarification',
                'pending_payload' => [
                    'transaction_data' => ['transaction_id' => $transaction->id],
                ],
                'clear_pending' => false,
                'reply_kind' => 'message',
                'entities' => [
                    'topic' => 'transactions',
                    'transaction_id' => $transaction->id,
                    'latest_transaction_id' => $transaction->id,
                ],
            ]);

            $this->sendResponse($job, 'Entendi. Me diga como dividir esse lancamento. Exemplo: mercado 20 e lazer 15.', $user);
            return true;
        }

        $totalSplit = round((float) $items->sum('amount'), 2);
        $originalAmount = round((float) $transaction->amount, 2);

        if (abs($totalSplit - $originalAmount) > 0.01) {
            $this->sendErrorMessage($job, sprintf(
                'A soma das categorias deu R$ %s, mas o lancamento original e de R$ %s. Me manda os valores fechando o total.',
                number_format($totalSplit, 2, ',', '.'),
                number_format($originalAmount, 2, ',', '.')
            ));
            return true;
        }

        $categoryService = app(CategoryRecognitionService::class);
        $newTransactions = [];

        foreach ($items as $index => $item) {
            $category = $categoryService->findExistingCategoryByName($user, $item['category_name'], $transaction->type)
                ?? $categoryService->findOrCreateCategory($user, $item['category_name'], $transaction->type);

            if ($index === 0) {
                $transaction->update([
                    'category_id' => $category?->id,
                    'amount' => (float) $item['amount'],
                    'description' => $category?->name ?? $transaction->description,
                    'metadata' => array_merge($transaction->metadata ?? [], [
                        'split_source' => true,
                    ]),
                ]);
                $newTransactions[] = $transaction->fresh('category');
                continue;
            }

            $newTransactions[] = Transaction::query()->create([
                'user_id' => $transaction->user_id,
                'whatsapp_contact_id' => $transaction->whatsapp_contact_id,
                'category_id' => $category?->id,
                'bank_account_id' => $transaction->bank_account_id,
                'credit_card_id' => $transaction->credit_card_id,
                'subscription_id' => $transaction->subscription_id,
                'type' => $transaction->type,
                'amount' => (float) $item['amount'],
                'description' => $category?->name ?? (string) $item['category_name'],
                'date' => $transaction->date?->toDateString() ?? now()->toDateString(),
                'metadata' => array_merge($transaction->metadata ?? [], [
                    'split_source' => true,
                    'origin_transaction_id' => $transaction->id,
                ]),
            ])->load('category');
        }

        Cache::forget("user.{$user->id}.financial_data");
        Cache::forget("user.{$user->id}.financial_projections");

        $lines = collect($newTransactions)->map(function (Transaction $entry) {
            return sprintf(
                '- %s: R$ %s',
                $entry->category?->name ?? $entry->description,
                number_format((float) $entry->amount, 2, ',', '.')
            );
        })->implode("\n");

        $first = $newTransactions[0];
        $result['_conversation_metadata'] = array_merge($result['_conversation_metadata'] ?? [], [
            'reply_kind' => 'action',
            'entities' => [
                'topic' => 'transactions',
                'transaction_id' => $first->id,
                'latest_transaction_id' => $first->id,
                'latest_transaction_ids' => collect($newTransactions)->pluck('id')->all(),
                'latest_transaction_description' => $first->description,
                'category_name' => $first->category?->name,
            ],
        ]);

        $this->sendResponse($job, "Dividi esse lancamento assim:\n{$lines}", $user);
        return true;
    }
}
