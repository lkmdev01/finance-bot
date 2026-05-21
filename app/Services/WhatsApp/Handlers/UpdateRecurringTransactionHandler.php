<?php

namespace App\Services\WhatsApp\Handlers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\RecurringTransaction;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\CategoryRecognitionService;
use App\Services\WhatsApp\FinancialSourceResolver;
use Illuminate\Support\Facades\Validator;

class UpdateRecurringTransactionHandler extends BaseHandler
{
    public function canHandle(?string $action): bool
    {
        return $action === 'update_recurring_transaction';
    }

    public function handle(?string $action, array &$result, User $user, WhatsAppContact $contact, ProcessWhatsAppMessage $job): bool
    {
        $data = $this->normalizeData($result['recurring_data'] ?? []);

        $validation = Validator::make($data, [
            'description' => ['required', 'string', 'min:2', 'max:120'],
            'amount' => ['nullable', 'numeric', 'min:0.01', 'max:999999.99'],
            'frequency' => ['nullable', 'in:weekly,monthly'],
            'day_of_month' => ['nullable', 'integer', 'min:1', 'max:31'],
            'category_name' => ['nullable', 'string', 'max:120'],
            'bank_account_name' => ['nullable', 'string', 'max:120'],
            'credit_card_name' => ['nullable', 'string', 'max:120'],
        ]);

        if ($validation->fails()) {
            $this->sendErrorMessage($job, "Nao consegui atualizar essa recorrencia.\n\nTente assim:\n* ajusta academia para 99\n* muda academia para dia 8\n* cancela recorrencia academia");
            return true;
        }

        $recurring = $user->recurringTransactions()
            ->whereRaw('LOWER(description) = ?', [mb_strtolower($data['description'])])
            ->first();

        if (! $recurring instanceof RecurringTransaction) {
            $this->sendErrorMessage($job, 'Nao encontrei essa recorrencia para atualizar. Se quiser, eu posso listar as recorrencias atuais primeiro.');
            return true;
        }

        if ($data['amount'] !== null) {
            $recurring->amount = $data['amount'];
        }

        if ($data['frequency'] !== null) {
            $recurring->frequency = $data['frequency'];
        }

        if ($data['day_of_month'] !== null) {
            $recurring->day_of_month = $data['day_of_month'];
        }

        if ($data['category_name'] !== null) {
            $categoryService = app(CategoryRecognitionService::class);
            $category = $categoryService->findExistingCategoryByName($user, $data['category_name'], $recurring->type)
                ?? $categoryService->findOrCreateCategory($user, $data['category_name'], $recurring->type);
            $recurring->category_id = $category?->id;
        }

        [$bankAccount, $creditCard] = app(FinancialSourceResolver::class)->resolve($user, $data);
        if ($bankAccount !== null || $data['bank_account_name'] !== null) {
            $recurring->bank_account_id = $bankAccount?->id;
            if ($bankAccount !== null) {
                $recurring->credit_card_id = null;
            }
        }

        if ($creditCard !== null || $data['credit_card_name'] !== null) {
            $recurring->credit_card_id = $creditCard?->id;
            if ($creditCard !== null) {
                $recurring->bank_account_id = null;
            }
        }

        $recurring->save();

        $parts = [];
        if ($data['amount'] !== null) {
            $parts[] = 'valor de R$ '.number_format((float) $recurring->amount, 2, ',', '.');
        }
        if ($data['frequency'] !== null) {
            $parts[] = 'frequencia '.($recurring->frequency === 'weekly' ? 'semanal' : 'mensal');
        }
        if ($data['day_of_month'] !== null) {
            $parts[] = 'dia '.(int) $recurring->day_of_month;
        }
        if ($bankAccount !== null) {
            $parts[] = 'conta '.$bankAccount->name;
        } elseif ($creditCard !== null) {
            $parts[] = 'cartao '.$creditCard->name;
        }

        $result['_conversation_metadata'] = array_merge($result['_conversation_metadata'] ?? [], [
            'reply_kind' => 'action',
            'entities' => [
                'topic' => 'recurring_transactions',
                'recurring_transaction_id' => $recurring->id,
                'recurring_description' => $recurring->description,
                'category_name' => $recurring->category?->name,
            ],
        ]);

        $this->sendResponse($job, sprintf('Recorrencia %s atualizada com %s.', $recurring->description, implode(', ', $parts)), $user);
        return true;
    }

    private function normalizeData(array $data): array
    {
        return [
            'description' => trim((string) ($data['description'] ?? '')),
            'amount' => isset($data['amount']) ? (float) $data['amount'] : null,
            'frequency' => $data['frequency'] ?? null,
            'day_of_month' => isset($data['day_of_month']) ? (int) $data['day_of_month'] : null,
            'category_name' => trim((string) ($data['category_name'] ?? '')) ?: null,
            'bank_account_name' => trim((string) ($data['bank_account_name'] ?? '')) ?: null,
            'credit_card_name' => trim((string) ($data['credit_card_name'] ?? '')) ?: null,
        ];
    }
}
