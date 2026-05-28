<?php

namespace App\Services\WhatsApp\Handlers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Budget;
use App\Models\Category;
use App\Models\User;
use App\Models\WhatsAppContact;
use Illuminate\Support\Str;

class DeleteBudgetHandler extends BaseHandler
{
    public function canHandle(?string $action): bool
    {
        return $action === 'delete_budget';
    }

    public function handle(?string $action, array &$result, User $user, WhatsAppContact $contact, ProcessWhatsAppMessage $job): bool
    {
        $data = $result['budget_data'] ?? [];
        $confirmed = (bool) ($data['confirmed'] ?? false);
        $categoryName = trim((string) ($data['category_name'] ?? ''));

        if ($categoryName === '') {
            $result['_conversation_metadata'] = array_merge($result['_conversation_metadata'] ?? [], [
                'pending_intent' => 'delete_budget_category',
                'pending_mode' => 'awaiting_clarification',
                'pending_payload' => [
                    'budget_data' => array_filter([
                        'period' => $data['period'] ?? null,
                        'year' => $data['year'] ?? now()->year,
                        'month' => $data['month'] ?? now()->month,
                    ], fn ($value) => $value !== null && $value !== ''),
                ],
                'clear_pending' => false,
                'reply_kind' => 'message',
                'entities' => [
                    'topic' => 'budget',
                    'year' => $data['year'] ?? now()->year,
                    'month' => $data['month'] ?? now()->month,
                ],
            ]);

            $this->sendErrorMessage($job, 'Preciso da categoria do orcamento para cancelar. Me diga so a categoria, por exemplo: Compras.');
            return true;
        }

        $category = $user->categories()
            ->where('type', 'expense')
            ->get();

        $category = $category->first(function (Category $item) use ($categoryName) {
            return $this->normalizeText($item->name) === $this->normalizeText($categoryName);
        }) ?? $category->first(function (Category $item) use ($categoryName) {
            $candidate = $this->normalizeText($item->name);
            $target = $this->normalizeText($categoryName);

            return str_starts_with($candidate, $target)
                || str_starts_with($target, $candidate)
                || levenshtein($candidate, $target) <= 3;
        });

        if (! $category instanceof Category) {
            $this->sendErrorMessage($job, 'Nao encontrei essa categoria de despesa para cancelar o orcamento.');
            return true;
        }

        $budget = Budget::query()
            ->where('user_id', $user->id)
            ->where('category_id', $category->id)
            ->where('period', $data['period'] ?? (($data['month'] ?? null) ? 'monthly' : 'yearly'))
            ->where('year', $data['year'] ?? now()->year)
            ->when(($data['period'] ?? 'monthly') === 'monthly', fn ($query) => $query->where('month', $data['month'] ?? now()->month))
            ->first();

        if (! $budget instanceof Budget) {
            $this->sendErrorMessage($job, 'Nao encontrei esse orcamento para cancelar. Se quiser, posso listar os orcamentos atuais primeiro.');
            return true;
        }

        if (! $confirmed) {
            $periodLabel = $budget->period === 'yearly'
                ? (string) $budget->year
                : sprintf('%02d/%d', (int) $budget->month, (int) $budget->year);

            $result['_conversation_metadata'] = array_merge($result['_conversation_metadata'] ?? [], [
                'pending_intent' => 'delete_budget',
                'pending_payload' => [
                    'budget_data' => [
                        'category_name' => $budget->category?->name,
                        'period' => $budget->period,
                        'year' => $budget->year,
                        'month' => $budget->month,
                        'confirmed' => true,
                    ],
                ],
                'clear_pending' => false,
                'reply_kind' => 'confirmation_request',
                'entities' => [
                    'topic' => 'budget',
                    'budget_id' => $budget->id,
                    'category_name' => $budget->category?->name,
                    'year' => $budget->year,
                    'month' => $budget->month,
                ],
            ]);

            $this->sendResponse($job, sprintf('Encontrei o orcamento de %s em %s com limite de R$ %s. Quer que eu cancele esse orcamento?', $budget->category?->name, $periodLabel, number_format((float) $budget->amount, 2, ',', '.')), $user);
            return true;
        }

        $name = $budget->category?->name;
        $beforeDelete = $budget->only(['category_id', 'period', 'year', 'month', 'amount']);
        $budget->delete();

        $result['_conversation_metadata'] = array_merge($result['_conversation_metadata'] ?? [], [
            'reply_kind' => 'action',
            'undo' => [
                'kind' => 'budget_delete',
                'attributes' => $beforeDelete,
                'expires_at' => now()->addSeconds(60)->toIso8601String(),
            ],
            'entities' => [
                'topic' => 'budget',
                'category_name' => $name,
            ],
        ]);

        $this->sendResponse($job, sprintf('Orcamento de %s cancelado. Se quiser, posso listar os orcamentos ativos ou criar um novo limite.', $name), $user);
        return true;
    }

    private function normalizeText(string $value): string
    {
        $value = mb_strtolower(Str::ascii(trim($value)));

        return preg_replace('/[^a-z0-9]/', '', $value) ?? $value;
    }
}
