<?php

namespace App\Services\WhatsApp\Handlers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Budget;
use App\Models\Category;
use App\Models\User;
use App\Models\WhatsAppContact;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class UpdateBudgetHandler extends BaseHandler
{
    public function canHandle(?string $action): bool
    {
        return $action === 'update_budget';
    }

    public function handle(?string $action, array &$result, User $user, WhatsAppContact $contact, ProcessWhatsAppMessage $job): bool
    {
        $data = $this->normalizeData($result['budget_data'] ?? []);

        $validation = Validator::make($data, [
            'category_name' => ['required', 'string', 'min:2', 'max:120'],
            'amount' => ['nullable', 'numeric', 'min:0.01', 'max:99999999.99'],
            'period' => ['nullable', 'in:monthly,yearly'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        if ($validation->fails() || $data['amount'] === null) {
            $this->sendErrorMessage($job, $this->buildGuidanceReply($validation->errors()->all()));
            return true;
        }

        $category = $this->findCategory($user, $data['category_name']);
        if (! $category instanceof Category) {
            $this->sendErrorMessage($job, 'Nao encontrei essa categoria de despesa para atualizar o orcamento.');
            return true;
        }

        $budget = $this->findBudget($user, $category, $data);
        if (! $budget instanceof Budget) {
            $this->sendErrorMessage($job, 'Nao encontrei esse orcamento para atualizar. Se quiser, posso listar os orcamentos atuais primeiro.');
            return true;
        }

        $budget->amount = $data['amount'];
        if ($data['period'] !== null) {
            $budget->period = $data['period'];
            $budget->month = $data['period'] === 'yearly' ? null : ($data['month'] ?? $budget->month ?? now()->month);
        }
        $budget->year = $data['year'] ?? $budget->year;
        if (($data['period'] ?? $budget->period) === 'monthly') {
            $budget->month = $data['month'] ?? $budget->month ?? now()->month;
        }
        $budget->save();

        $result['_conversation_metadata'] = array_merge($result['_conversation_metadata'] ?? [], [
            'reply_kind' => 'action',
            'entities' => [
                'topic' => 'budget',
                'budget_id' => $budget->id,
                'category_name' => $budget->category?->name,
                'period_scope' => $budget->period === 'yearly' ? 'yearly_focus' : 'specific_month',
                'year' => $budget->year,
                'month' => $budget->month,
            ],
        ]);

        $periodLabel = $budget->period === 'yearly'
            ? (string) $budget->year
            : sprintf('%02d/%d', (int) $budget->month, (int) $budget->year);

        $this->sendResponse($job, sprintf('Orcamento de %s atualizado para R$ %s em %s.', $budget->category?->name, number_format((float) $budget->amount, 2, ',', '.'), $periodLabel), $user);
        return true;
    }

    private function normalizeData(array $data): array
    {
        return [
            'category_name' => trim((string) ($data['category_name'] ?? '')),
            'amount' => isset($data['amount']) ? (float) $data['amount'] : null,
            'period' => $data['period'] ?? null,
            'year' => isset($data['year']) ? (int) $data['year'] : null,
            'month' => isset($data['month']) ? (int) $data['month'] : null,
        ];
    }

    private function findCategory(User $user, string $categoryName): ?Category
    {
        $normalizedTarget = $this->normalizeText($categoryName);

        return $user->categories()
            ->where('type', 'expense')
            ->get()
            ->first(function (Category $category) use ($normalizedTarget) {
                return $this->normalizeText($category->name) === $normalizedTarget;
            });
    }

    private function findBudget(User $user, Category $category, array $data): ?Budget
    {
        $period = $data['period'] ?? (($data['month'] ?? null) ? 'monthly' : 'yearly');
        $year = $data['year'] ?? now()->year;

        return Budget::query()
            ->where('user_id', $user->id)
            ->where('category_id', $category->id)
            ->where('period', $period)
            ->where('year', $year)
            ->when($period === 'monthly', fn ($query) => $query->where('month', $data['month'] ?? now()->month))
            ->first();
    }

    private function buildGuidanceReply(array $errors = []): string
    {
        $details = empty($errors) ? '' : "\n\nDetalhes: ".implode(' | ', $errors);

        return "Nao consegui atualizar esse orcamento com a mensagem atual.\n\n"
            ."Tente assim:\n"
            ."* ajustar orcamento compras para 700\n"
            ."* editar orcamento alimentacao para 900 em junho 2026\n"
            ."* mudar orcamento anual de viagem para 5000"
            .$details;
    }

    private function normalizeText(string $value): string
    {
        return mb_strtolower(Str::ascii(trim($value)));
    }
}
