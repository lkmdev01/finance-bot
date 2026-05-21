<?php

namespace App\Services\WhatsApp\Handlers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\RecurringTransaction;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\BillingPlanService;
use App\Services\CategoryRecognitionService;
use App\Services\WhatsApp\FinancialSourceResolver;
use Illuminate\Support\Facades\Validator;

class CreateRecurringTransactionHandler extends BaseHandler
{
    public function canHandle(?string $action): bool
    {
        return $action === 'create_recurring_transaction';
    }

    public function handle(?string $action, array &$result, User $user, WhatsAppContact $contact, ProcessWhatsAppMessage $job): bool
    {
        $data = $this->normalizeData($result['recurring_data'] ?? []);

        if (! app(BillingPlanService::class)->userCanCreateRecords($user)) {
            $plansUrl = rtrim((string) config('app.url'), '/').'/billing/plans';
            $this->sendResponse($job, "Seu plano atual nao permite criar recorrencias.\n\nAssine um plano para continuar:\n{$plansUrl}", $user);
            return true;
        }

        $validation = Validator::make($data, [
            'type' => ['required', 'in:income,expense'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'description' => ['required', 'string', 'min:2', 'max:120'],
            'frequency' => ['required', 'in:weekly,monthly'],
            'start_date' => ['required', 'date'],
            'day_of_month' => ['nullable', 'integer', 'min:1', 'max:31'],
            'category_name' => ['nullable', 'string', 'max:120'],
            'bank_account_name' => ['nullable', 'string', 'max:120'],
            'credit_card_name' => ['nullable', 'string', 'max:120'],
        ]);

        if ($validation->fails()) {
            $this->sendErrorMessage($job, "Nao consegui criar essa recorrencia.\n\nTente assim:\n* todo dia 5 pago academia 89\n* todo mes recebo aluguel 1500");
            return true;
        }

        $category = null;
        if ($data['category_name'] !== null) {
            $categoryService = app(CategoryRecognitionService::class);
            $category = $categoryService->findExistingCategoryByName($user, $data['category_name'], $data['type'])
                ?? $categoryService->findOrCreateCategory($user, $data['category_name'], $data['type']);
        }

        [$bankAccount, $creditCard] = app(FinancialSourceResolver::class)->resolve($user, $data);

        $recurring = RecurringTransaction::query()->create([
            'user_id' => $user->id,
            'category_id' => $category?->id,
            'bank_account_id' => $bankAccount?->id,
            'credit_card_id' => $creditCard?->id,
            'type' => $data['type'],
            'amount' => $data['amount'],
            'description' => $data['description'],
            'frequency' => $data['frequency'],
            'start_date' => $data['start_date'],
            'is_active' => true,
            'day_of_month' => $data['day_of_month'],
            'day_of_week' => null,
        ]);

        $result['_conversation_metadata'] = array_merge($result['_conversation_metadata'] ?? [], [
            'reply_kind' => 'action',
            'entities' => [
                'topic' => 'recurring_transactions',
                'recurring_transaction_id' => $recurring->id,
                'category_name' => $category?->name,
            ],
        ]);

        $this->sendResponse(
            $job,
            sprintf(
                'Recorrencia criada para %s: R$ %s com frequencia %s.',
                $recurring->description,
                number_format((float) $recurring->amount, 2, ',', '.'),
                $recurring->frequency === 'weekly' ? 'semanal' : 'mensal'
            ),
            $user
        );

        return true;
    }

    private function normalizeData(array $data): array
    {
        return [
            'type' => $data['type'] ?? 'expense',
            'amount' => isset($data['amount']) ? (float) $data['amount'] : null,
            'description' => trim((string) ($data['description'] ?? '')),
            'frequency' => $data['frequency'] ?? 'monthly',
            'start_date' => (string) ($data['start_date'] ?? now()->toDateString()),
            'day_of_month' => isset($data['day_of_month']) ? (int) $data['day_of_month'] : null,
            'category_name' => isset($data['category_name']) && trim((string) $data['category_name']) !== '' ? trim((string) $data['category_name']) : null,
            'bank_account_name' => isset($data['bank_account_name']) && trim((string) $data['bank_account_name']) !== '' ? trim((string) $data['bank_account_name']) : null,
            'credit_card_name' => isset($data['credit_card_name']) && trim((string) $data['credit_card_name']) !== '' ? trim((string) $data['credit_card_name']) : null,
        ];
    }
}
