<?php

namespace App\Services\WhatsApp\Handlers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Budget;
use App\Models\Category;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\BillingPlanService;
use App\Services\CategoryRecognitionService;
use Illuminate\Support\Facades\Validator;

class CreateBudgetHandler extends BaseHandler
{
    public function canHandle(?string $action): bool
    {
        return $action === 'create_budget';
    }

    public function handle(?string $action, array &$result, User $user, WhatsAppContact $contact, ProcessWhatsAppMessage $job): bool
    {
        if (! isset($result['budget_data'])) {
            return false;
        }

        $billingPlanService = app(BillingPlanService::class);

        if (! $billingPlanService->userCanCreateRecords($user)) {
            $this->sendResponse($job, $this->buildSubscriptionRequiredReply($user, $billingPlanService), $user);
            return true;
        }

        $budgetData = $this->normalizeBudgetData($result['budget_data']);
        $validation = $this->validateBudgetData($budgetData, $user);

        if ($validation->fails()) {
            $this->sendErrorMessage($job, $this->buildBudgetValidationGuidanceReply($validation->errors()->all()));
            return true;
        }

        [$budget, $previous] = $this->upsertBudget($user, $budgetData);
        $reply = $this->buildBudgetCreatedReply($budget, $previous instanceof Budget);

        $result['_conversation_metadata'] = array_merge($result['_conversation_metadata'] ?? [], [
            'reply_kind' => 'action',
            'undo' => $previous instanceof Budget
                ? [
                    'kind' => 'budget_update',
                    'id' => $budget->id,
                    'before' => $previous->only(['amount', 'period', 'year', 'month']),
                    'expires_at' => now()->addSeconds(60)->toIso8601String(),
                ]
                : [
                    'kind' => 'budget_create',
                    'id' => $budget->id,
                    'expires_at' => now()->addSeconds(60)->toIso8601String(),
                ],
            'entities' => [
                'topic' => 'budgets',
                'budget_id' => $budget->id,
                'category_name' => $budget->category?->name,
                'period' => $budget->period,
                'year' => $budget->year,
                'month' => $budget->month,
            ],
        ]);

        $this->sendResponse($job, $reply, $user);
        return true;
    }

    private function normalizeBudgetData(array $data): array
    {
        $period = in_array(($data['period'] ?? 'monthly'), ['monthly', 'yearly'], true)
            ? $data['period']
            : 'monthly';
        $categoryName = isset($data['category_name']) ? trim((string) $data['category_name']) : null;

        return [
            'amount' => isset($data['amount']) ? (float) $data['amount'] : null,
            'period' => $period,
            'year' => (int) ($data['year'] ?? now()->year),
            'month' => $period === 'monthly' ? (int) ($data['month'] ?? now()->month) : null,
            'category_id' => isset($data['category_id']) ? (int) $data['category_id'] : null,
            'category_name' => $categoryName !== '' ? $categoryName : null,
        ];
    }

    private function validateBudgetData(array $data, User $user): \Illuminate\Contracts\Validation\Validator
    {
        $validator = Validator::make($data, [
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'period' => ['required', 'in:monthly,yearly'],
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'category_id' => ['nullable', 'integer'],
            'category_name' => ['nullable', 'string', 'max:100'],
        ]);

        $validator->after(function ($validator) use ($data, $user) {
            if (empty($data['category_id']) && blank($data['category_name'] ?? null)) {
                $validator->errors()->add('category', 'Informe uma categoria para o orcamento.');
            }

            if (($data['period'] ?? 'monthly') === 'monthly' && empty($data['month'])) {
                $validator->errors()->add('month', 'Informe o mes do orcamento mensal.');
            }

            if (! empty($data['category_id'])) {
                $category = Category::query()
                    ->where('id', $data['category_id'])
                    ->where('user_id', $user->id)
                    ->where('type', 'expense')
                    ->first();

                if (! $category && blank($data['category_name'] ?? null)) {
                    $validator->errors()->add('category_id', 'A categoria informada nao e uma categoria de despesa valida.');
                }
            }
        });

        return $validator;
    }

    /**
     * @return array{0: Budget, 1: Budget|null}
     */
    private function upsertBudget(User $user, array $data): array
    {
        $categoryRecognition = app(CategoryRecognitionService::class);
        $category = null;

        if (! empty($data['category_id'])) {
            $category = Category::query()
                ->where('id', $data['category_id'])
                ->where('user_id', $user->id)
                ->where('type', 'expense')
                ->first();
        }

        if (! $category && ! empty($data['category_name'])) {
            $category = $categoryRecognition->findExistingCategoryByName($user, $data['category_name'], 'expense');
        }

        if (! $category && ! empty($data['category_name'])) {
            $category = $categoryRecognition->findOrCreateCategory($user, $data['category_name'], 'expense');
        }

        $previous = Budget::query()
            ->where('user_id', $user->id)
            ->where('category_id', $category->id)
            ->where('period', $data['period'])
            ->where('year', $data['year'])
            ->where('month', $data['period'] === 'monthly' ? $data['month'] : null)
            ->first();

        $budget = Budget::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'category_id' => $category->id,
                'period' => $data['period'],
                'year' => $data['year'],
                'month' => $data['period'] === 'monthly' ? $data['month'] : null,
            ],
            [
                'amount' => $data['amount'],
            ]
        )->load('category');

        return [$budget, $previous];
    }

    private function buildBudgetCreatedReply(Budget $budget, bool $wasUpdate = false): string
    {
        $amount = number_format((float) $budget->amount, 2, ',', '.');
        $category = $budget->category?->name ?? 'Sem categoria';

        if ($budget->period === 'yearly') {
            return $wasUpdate
                ? "Orcamento anual atualizado: R$ {$amount} para {$category} em {$budget->year}."
                : "Orcamento anual criado: R$ {$amount} para {$category} em {$budget->year}.";
        }

        $monthLabel = str_pad((string) $budget->month, 2, '0', STR_PAD_LEFT).'/'.$budget->year;

        return $wasUpdate
            ? "Orcamento atualizado: R$ {$amount} para {$category} em {$monthLabel}."
            : "Orcamento de R$ {$amount} criado para {$category} em {$monthLabel}.";
    }

    private function buildBudgetValidationGuidanceReply(array $errors = []): string
    {
        $details = empty($errors) ? '' : "\n\nDetalhes: ".implode(' | ', $errors);

        return "Atencao: Nao consegui criar o orcamento com essa mensagem.\n\n"
            ."Tente assim:\n"
            ."* criar orcamento de 800 para mercado\n"
            ."* definir orcamento de 300 para transporte\n"
            ."* registrar orcamento de 500 para compras\n"
            ."* criar orcamento anual de 5000 para saude"
            .$details;
    }

    private function buildSubscriptionRequiredReply(User $user, BillingPlanService $billingPlanService): string
    {
        $plansUrl = rtrim((string) config('app.url'), '/').'/billing/plans';

        return $billingPlanService->writeAccessMessage($user)
            ."\n\nAssine um plano para voltar a registrar novas informacoes:\n"
            .$plansUrl;
    }
}

