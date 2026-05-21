<?php

namespace App\Services\WhatsApp\Handlers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\WhatsApp\ConversationStateService;
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

        if ($this->countProvidedUpdates($data) === 0) {
            $this->sendErrorMessage($job, 'Preciso do que voce quer alterar nessa transacao. Exemplo: ajustar aquele uber para 28.');
            return true;
        }

        $resolver = app(TransactionReferenceResolver::class);
        $state = app(ConversationStateService::class)->getState($contact);
        $resolution = $resolver->resolve($user, $data, $state);

        if ($resolution['ambiguous'] ?? false) {
            $options = $resolver->formatAmbiguousOptions($resolution['matches']);
            $this->sendErrorMessage($job, "Encontrei mais de uma transacao parecida para editar. Me diga qual delas voce quer ajustar:\n{$options}");
            return true;
        }

        /** @var Transaction|null $transaction */
        $transaction = $resolution['transaction'] ?? null;
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
        $this->sendResponse($job, sprintf('Atualizei %s para R$ %s.', $label, number_format((float) $transaction->amount, 2, ',', '.')), $user);
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
        ]);
    }
}
