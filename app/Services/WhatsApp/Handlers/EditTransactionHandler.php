<?php

namespace App\Services\WhatsApp\Handlers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\WhatsApp\ConversationStateService;
use App\Services\WhatsApp\FinancialSourceResolver;
use App\Services\WhatsApp\TransactionReferenceResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class EditTransactionHandler extends BaseHandler
{
    public function canHandle(?string $action): bool
    {
        return $action === 'edit_transaction';
    }

    public function handle(?string $action, array &$result, User $user, WhatsAppContact $contact, ProcessWhatsAppMessage $job): bool
    {
        $data = $this->normalizeTransactionData($result['transaction_data'] ?? []);

        $resolver = app(TransactionReferenceResolver::class);
        $state = app(ConversationStateService::class)->getState($contact);
        $resolution = $resolver->resolve($user, $data, $state);

        /** @var Transaction|null $transaction */
        $transaction = $resolution['transaction'] ?? null;

        if ($this->countProvidedUpdates($data) === 0) {
            if ($transaction instanceof Transaction) {
                $result['_conversation_metadata'] = array_merge($result['_conversation_metadata'] ?? [], [
                    'pending_intent' => 'edit_transaction_details',
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
                        'latest_transaction_description' => $transaction->description,
                        'category_name' => $transaction->category?->name,
                    ],
                ]);

                $this->sendResponse($job, sprintf(
                    'Encontrei %s. O que voce quer mudar? Exemplo: para 28, foi no debito ou muda para mercado.',
                    $resolver->formatTransactionLabel($transaction)
                ), $user);
                return true;
            }

            $this->sendErrorMessage($job, 'Preciso do que voce quer alterar nessa transacao. Exemplo: ajustar aquele uber para 28.');
            return true;
        }

        if ($resolution['ambiguous'] ?? false) {
            $options = $resolver->formatAmbiguousOptions($resolution['matches']);
            $this->sendErrorMessage($job, "Encontrei mais de uma transacao parecida para editar. Me diga qual delas voce quer ajustar:\n{$options}");
            return true;
        }

        if (! $transaction instanceof Transaction) {
            $this->sendErrorMessage($job, 'Nao consegui identificar qual transacao editar. Tente citar a descricao, a data ou diga ultimo gasto.');
            return true;
        }

        $validation = $this->validateTransactionData($data, $user);
        if ($validation->fails()) {
            $this->sendErrorMessage($job, 'Dados invalidos para edicao: '.implode(' | ', $validation->errors()->all()));
            return true;
        }

        $updates = [];
        if ($data['amount'] !== null) {
            $updates['amount'] = $data['amount'];
        }
        if ($data['date'] !== null) {
            $updates['date'] = $data['date'];
        }
        if ($data['type'] !== null) {
            $updates['type'] = $data['type'];
        }
        if ($data['description'] !== null) {
            $updates['description'] = $data['description'];
        }
        if ($data['category_id'] !== null) {
            $updates['category_id'] = $data['category_id'];
        }
        if ($data['category_name'] !== null) {
            $category = Category::query()
                ->where('user_id', $user->id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($data['category_name'])])
                ->first();

            if ($category instanceof Category) {
                $updates['category_id'] = $category->id;
            }
        }

        $sourceResolver = app(FinancialSourceResolver::class);
        $bankAccount = null;
        $creditCard = null;

        if ($data['bank_account_name'] !== null) {
            $bankAccount = $sourceResolver->findBankAccountByName($user, $data['bank_account_name']);
            if ($bankAccount === null) {
                $this->sendErrorMessage($job, 'Nao encontrei essa conta para usar. Tente o nome exato ou liste suas contas primeiro.');
                return true;
            }

            $updates['bank_account_id'] = $bankAccount->id;
            $updates['credit_card_id'] = null;
        }

        if ($data['credit_card_name'] !== null) {
            $creditCard = $sourceResolver->findCreditCardByName($user, $data['credit_card_name']);
            if ($creditCard === null) {
                $this->sendErrorMessage($job, 'Nao encontrei esse cartao para usar. Tente o nome exato ou liste seus cartoes primeiro.');
                return true;
            }

            $updates['credit_card_id'] = $creditCard->id;
            $updates['bank_account_id'] = null;
        }

        if ($data['payment_method'] !== null) {
            $updates['metadata'] = array_merge($transaction->metadata ?? [], [
                'payment_method' => $data['payment_method'],
            ]);
        }

        $transaction->update($updates);
        $transaction->refresh()->loadMissing('category');

        Cache::forget("user.{$user->id}.financial_data");
        Cache::forget("user.{$user->id}.financial_projections");

        Log::info('Transacao editada via WhatsApp', [
            'user_id' => $user->id,
            'transaction_id' => $transaction->id,
            'resolution_source' => $resolution['source'] ?? null,
        ]);

        $result['_conversation_metadata'] = array_merge($result['_conversation_metadata'] ?? [], [
            'reply_kind' => 'action',
            'entities' => [
                'topic' => 'transactions',
                'transaction_id' => $transaction->id,
                'latest_transaction_id' => $transaction->id,
                'latest_transaction_description' => $transaction->description,
                'transaction_type' => $transaction->type,
                'category_name' => $transaction->category?->name,
            ],
        ]);

        $label = $transaction->description ?: ($transaction->category?->name ?? 'transacao');
        $parts = [];
        if ($data['amount'] !== null) {
            $parts[] = sprintf('valor para R$ %s', number_format((float) $transaction->amount, 2, ',', '.'));
        }
        if ($data['payment_method'] !== null) {
            $parts[] = 'forma de pagamento para '.$this->formatPaymentMethod($data['payment_method']);
        }
        if ($data['category_name'] !== null && $transaction->category?->name) {
            $parts[] = 'categoria para '.$transaction->category->name;
        }
        if ($bankAccount !== null) {
            $parts[] = 'conta '.$bankAccount->name;
        } elseif ($creditCard !== null) {
            $parts[] = 'cartao '.$creditCard->name;
        }

        // Keep responses natural and predictable. When only the amount changes, prefer:
        // "Atualizei Uber para R$ 28,00." (used by tests and feels more conversational)
        $amountOnly = $data['amount'] !== null
            && $data['payment_method'] === null
            && $data['category_name'] === null
            && $data['bank_account_name'] === null
            && $data['credit_card_name'] === null
            && $data['type'] === null
            && $data['description'] === null
            && $data['date'] === null;

        if ($amountOnly) {
            $this->sendResponse($job, sprintf(
                'Atualizei %s para R$ %s.',
                $label,
                number_format((float) $transaction->amount, 2, ',', '.')
            ), $user);
            return true;
        }

        $detail = $parts === [] ? sprintf('R$ %s', number_format((float) $transaction->amount, 2, ',', '.')) : implode(' e ', $parts);
        $this->sendResponse($job, sprintf('Atualizei %s com %s.', $label, $detail), $user);
        return true;
    }

    private function normalizeTransactionData(array $data): array
    {
        return [
            'transaction_id' => isset($data['transaction_id']) && is_numeric($data['transaction_id']) ? (int) $data['transaction_id'] : null,
            'target_description' => trim((string) ($data['target_description'] ?? '')) ?: null,
            'reference' => $data['reference'] ?? null,
            'target_date_scope' => $data['target_date_scope'] ?? null,
            'amount' => isset($data['amount']) ? (float) $data['amount'] : null,
            'type' => $data['type'] ?? null,
            'description' => isset($data['description']) && trim((string) $data['description']) !== '' ? trim((string) $data['description']) : null,
            'category_id' => isset($data['category_id']) && is_numeric($data['category_id']) ? (int) $data['category_id'] : null,
            'category_name' => isset($data['category_name']) && trim((string) $data['category_name']) !== '' ? trim((string) $data['category_name']) : null,
            'date' => isset($data['date']) && $data['date'] !== '' ? (string) $data['date'] : null,
            'payment_method' => $data['payment_method'] ?? null,
            'bank_account_name' => isset($data['bank_account_name']) && trim((string) $data['bank_account_name']) !== '' ? trim((string) $data['bank_account_name']) : null,
            'credit_card_name' => isset($data['credit_card_name']) && trim((string) $data['credit_card_name']) !== '' ? trim((string) $data['credit_card_name']) : null,
        ];
    }

    private function countProvidedUpdates(array $data): int
    {
        return count(array_filter([
            $data['amount'],
            $data['type'],
            $data['description'],
            $data['category_id'],
            $data['category_name'],
            $data['date'],
            $data['payment_method'],
            $data['bank_account_name'],
            $data['credit_card_name'],
        ], fn ($value) => $value !== null && $value !== ''));
    }

    private function validateTransactionData(array $data, User $user)
    {
        return Validator::make($data, [
            'amount' => ['nullable', 'numeric', 'min:0.01', 'max:999999.99'],
            'description' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'in:income,expense'],
            'category_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date', 'before_or_equal:today'],
            'payment_method' => ['nullable', 'in:debit,credit,pix'],
            'bank_account_name' => ['nullable', 'string', 'max:120'],
            'credit_card_name' => ['nullable', 'string', 'max:120'],
        ]);
    }

    private function formatPaymentMethod(string $paymentMethod): string
    {
        return match ($paymentMethod) {
            'debit' => 'debito',
            'credit' => 'credito',
            'pix' => 'pix',
            default => $paymentMethod,
        };
    }
}
