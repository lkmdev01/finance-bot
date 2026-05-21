<?php

namespace App\Services\WhatsApp\Handlers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\BillingPlanService;
use App\Services\CategoryRecognitionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

class CreateInstallmentTransactionHandler extends BaseHandler
{
    public function canHandle(?string $action): bool
    {
        return $action === 'create_installment_transaction';
    }

    public function handle(?string $action, array &$result, User $user, WhatsAppContact $contact, ProcessWhatsAppMessage $job): bool
    {
        $data = $this->normalizeData($result['installment_data'] ?? []);

        if (! app(BillingPlanService::class)->userCanCreateRecords($user)) {
            $plansUrl = rtrim((string) config('app.url'), '/').'/billing/plans';
            $this->sendResponse($job, "Seu plano atual nao permite criar parcelamentos.\n\nAssine um plano para continuar:\n{$plansUrl}", $user);
            return true;
        }

        $validation = Validator::make($data, [
            'description' => ['required', 'string', 'min:2', 'max:120'],
            'total_amount' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'installment_count' => ['required', 'integer', 'min:2', 'max:36'],
            'per_installment_amount' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'date' => ['required', 'date'],
            'category_name' => ['nullable', 'string', 'max:120'],
        ]);

        if ($validation->fails()) {
            $this->sendErrorMessage($job, "Nao consegui registrar esse parcelado.\n\nTente assim:\n* comprei celular por 2000 em 10x\n* paguei notebook 3600 em 12x");
            return true;
        }

        $category = null;
        if ($data['category_name'] !== null) {
            $categoryService = app(CategoryRecognitionService::class);
            $category = $categoryService->findExistingCategoryByName($user, $data['category_name'], 'expense')
                ?? $categoryService->findOrCreateCategory($user, $data['category_name'], 'expense');
        }

        $transactions = [];
        $baseDate = Carbon::parse($data['date']);

        for ($index = 0; $index < $data['installment_count']; $index++) {
            $transactions[] = Transaction::query()->create([
                'user_id' => $user->id,
                'whatsapp_contact_id' => $contact->id,
                'category_id' => $category?->id,
                'type' => 'expense',
                'amount' => $data['per_installment_amount'],
                'description' => sprintf('%s (%d/%d)', $data['description'], $index + 1, $data['installment_count']),
                'date' => $baseDate->copy()->addMonthsNoOverflow($index)->toDateString(),
                'metadata' => [
                    'source' => 'installment_plan',
                    'installment_plan' => [
                        'description' => $data['description'],
                        'installment_count' => $data['installment_count'],
                        'current_installment' => $index + 1,
                        'total_amount' => $data['total_amount'],
                    ],
                ],
            ]);
        }

        $firstTransaction = $transactions[0];
        $result['_conversation_metadata'] = array_merge($result['_conversation_metadata'] ?? [], [
            'reply_kind' => 'action',
            'entities' => [
                'topic' => 'transactions',
                'transaction_id' => $firstTransaction->id,
                'latest_transaction_id' => $firstTransaction->id,
                'latest_transaction_ids' => collect($transactions)->pluck('id')->all(),
                'latest_transaction_description' => $firstTransaction->description,
                'category_name' => $category?->name,
            ],
        ]);

        $this->sendResponse(
            $job,
            sprintf(
                'Registrei %s parcelado em %dx de R$ %s. A primeira parcela ficou para %s.',
                $data['description'],
                $data['installment_count'],
                number_format((float) $data['per_installment_amount'], 2, ',', '.'),
                $baseDate->format('d/m/Y')
            ),
            $user
        );

        return true;
    }

    private function normalizeData(array $data): array
    {
        return [
            'description' => trim((string) ($data['description'] ?? '')),
            'total_amount' => isset($data['total_amount']) ? (float) $data['total_amount'] : null,
            'installment_count' => isset($data['installment_count']) ? (int) $data['installment_count'] : null,
            'per_installment_amount' => isset($data['per_installment_amount']) ? (float) $data['per_installment_amount'] : null,
            'date' => (string) ($data['date'] ?? now()->toDateString()),
            'category_name' => isset($data['category_name']) && trim((string) $data['category_name']) !== '' ? trim((string) $data['category_name']) : null,
        ];
    }
}
