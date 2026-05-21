<?php

namespace App\Services\WhatsApp;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Budget;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\BaileysService;
use App\Services\FinancialProjectionService;
use App\Services\PhoneNumberService;

class ProactiveConversationTrigger
{
    public function __construct(
        private readonly ConversationStateService $stateService,
        private readonly FinancialProjectionService $projectionService,
        private readonly BaileysService $baileysService,
        private readonly PhoneNumberService $phoneNumberService,
    ) {}

    public function dispatch(User $user, WhatsAppContact $contact, ?string $action, array $result, ProcessWhatsAppMessage $job): void
    {
        $payload = $this->buildPayload($user, $contact, $action, $result);

        if ($payload === null) {
            return;
        }

        if ($this->stateService->wasRecentlyDispatched($contact, $payload['key'], 90)) {
            return;
        }

        $job->sendResponse($this->baileysService, $this->phoneNumberService, $payload['message'], $user);
        $this->stateService->recordProactiveMessage($contact, $job->message, $payload['message'], $payload['key']);
    }

    private function buildPayload(User $user, WhatsAppContact $contact, ?string $action, array $result): ?array
    {
        return match ($action) {
            'create_transaction', 'confirm_large_transaction' => $this->buildBudgetAfterExpenseAlert($user, $result),
            'query_savings' => $this->buildSavingsNudge($user, $result),
            'query_subscriptions' => $this->buildSubscriptionNudge($user, $result),
            'query_projections' => $this->buildProjectionNudge($user, $result),
            default => null,
        };
    }

    private function buildBudgetAfterExpenseAlert(User $user, array $result): ?array
    {
        $transactionData = $result['transaction_data'] ?? [];

        if (($transactionData['type'] ?? null) !== 'expense' || empty($transactionData['category_id'])) {
            return null;
        }

        $budget = Budget::query()
            ->with('category')
            ->where('user_id', $user->id)
            ->where('category_id', $transactionData['category_id'])
            ->where('year', now()->year)
            ->where(function ($query) {
                $query->where(function ($monthly) {
                    $monthly->where('period', 'monthly')->where('month', now()->month);
                })->orWhere('period', 'yearly');
            })
            ->orderByDesc('period')
            ->first();

        if (! $budget instanceof Budget) {
            return null;
        }

        if ($budget->percentage_used >= 100) {
            return [
                'key' => sprintf('budget:%d:%d:%d:over', $budget->category_id, now()->year, now()->month),
                'message' => sprintf(
                    'Alerta rapido: %s acabou de passar do limite do orcamento e agora esta com %s usado.',
                    $budget->category?->name ?? 'essa categoria',
                    $this->formatPercentage($budget->percentage_used)
                ),
            ];
        }

        if ($budget->percentage_used >= 80) {
            return [
                'key' => sprintf('budget:%d:%d:%d:near', $budget->category_id, now()->year, now()->month),
                'message' => sprintf(
                    'Alerta rapido: %s ja consumiu %s do orcamento deste periodo.',
                    $budget->category?->name ?? 'essa categoria',
                    $this->formatPercentage($budget->percentage_used)
                ),
            ];
        }

        return null;
    }

    private function buildSavingsNudge(User $user, array $result): ?array
    {
        if (! empty($result['_conversation_metadata']['entities']['goal_name'])) {
            return null;
        }

        $goals = $user->savingsGoals()->with('deposits')->where('is_completed', false)->orderBy('target_date')->get();
        $goal = $goals->first(function ($goal) {
            if (! $goal->target_date) {
                return false;
            }

            $daysLeft = now()->startOfDay()->diffInDays($goal->target_date->copy()->startOfDay(), false);

            return $daysLeft <= 45 && $goal->progress_percentage < 50;
        });

        if ($goal === null) {
            return null;
        }

        return [
            'key' => 'savings:urgent:' . $goal->id,
            'message' => sprintf(
                'Lembrete rapido: a meta %s vence em breve e ainda esta com %s de progresso.',
                $goal->name,
                $this->formatPercentage((float) $goal->progress_percentage)
            ),
        ];
    }

    private function buildSubscriptionNudge(User $user, array $result): ?array
    {
        if (! empty($result['_conversation_metadata']['entities']['subscription_name'])) {
            return null;
        }

        $subscriptions = $user->subscriptions()->where('is_active', true)->orderBy('next_due_date')->get();
        $subscription = $subscriptions->first(function (Subscription $subscription) {
            if (! $subscription->next_due_date) {
                return false;
            }

            return $subscription->next_due_date->copy()->startOfDay()->lte(now()->addDays(2)->startOfDay());
        });

        if (! $subscription instanceof Subscription) {
            return null;
        }

        $daysLeft = now()->startOfDay()->diffInDays($subscription->next_due_date->copy()->startOfDay(), false);
        $message = $daysLeft < 0
            ? sprintf('Lembrete rapido: %s ja passou da data prevista de cobranca.', $subscription->name)
            : sprintf('Lembrete rapido: %s vence em %d dias.', $subscription->name, $daysLeft);

        return [
            'key' => 'subscriptions:due:' . $subscription->id,
            'message' => $message,
        ];
    }

    private function buildProjectionNudge(User $user, array $result): ?array
    {
        if (! empty($result['_conversation_metadata']['entities']['projection_month'])) {
            return null;
        }

        if ($user->financialProjections()->count() === 0) {
            $this->projectionService->generateProjections($user, 6);
        }

        $projections = $user->financialProjections()
            ->orderBy('projection_date')
            ->limit(6)
            ->get()
            ->map(fn ($projection) => [
                'date' => $projection->projection_date->format('Y-m-d'),
                'month' => $projection->projection_date->locale('pt_BR')->translatedFormat('F/Y'),
                'projected_balance' => (float) $projection->projected_balance,
                'projected_income' => (float) $projection->projected_income,
                'projected_expenses' => (float) $projection->projected_expenses,
            ]);

        $riskyProjection = $projections->take(3)->first(function (array $projection) {
            return (float) $projection['projected_balance'] < 0;
        });

        if (! is_array($riskyProjection)) {
            return null;
        }

        return [
            'key' => 'projections:risk:' . ($riskyProjection['date'] ?? 'next'),
            'message' => sprintf(
                'Alerta rapido: a projecao de %s fica negativa em R$ %s.',
                $riskyProjection['month'],
                number_format(abs((float) $riskyProjection['projected_balance']), 2, ',', '.')
            ),
        ];
    }

    private function formatPercentage(float $value): string
    {
        return number_format($value, 0, ',', '.').'%';
    }
}
