<?php

namespace App\Services\Onboarding;

use App\Models\User;

class OnboardingChecklistService
{
    /**
     * @return array{
     *   total: int,
     *   completed: int,
     *   steps: array<int, array{key: string, title: string, done: bool, hint: string, example: string|null, url: string|null}>
     * }
     */
    public function checklist(User $user): array
    {
        $hasTransaction = $user->transactions()->exists();
        $hasBudget = $user->budgets()->exists();
        $hasSource = $user->bankAccounts()->where('is_active', true)->exists()
            || $user->creditCards()->where('is_active', true)->exists();

        $steps = [
            [
                'key' => 'transaction',
                'title' => 'Registrar sua primeira transacao',
                'done' => $hasTransaction,
                'hint' => 'Isso destrava saldo, graficos e comparacoes.',
                'example' => 'gastei 20 no uber',
                'url' => rtrim((string) config('app.url'), '/').'/transactions/create',
            ],
            [
                'key' => 'budget',
                'title' => 'Criar seu primeiro orcamento',
                'done' => $hasBudget,
                'hint' => 'Voce recebe alertas e sabe o que esta mais apertado.',
                'example' => 'criar orcamento de 500 para compras',
                'url' => rtrim((string) config('app.url'), '/').'/budgets/create',
            ],
            [
                'key' => 'source',
                'title' => 'Adicionar uma conta ou cartao',
                'done' => $hasSource,
                'hint' => 'Ajuda a separar saldo da conta e limite do cartao.',
                'example' => 'registrar cartao de credito Nubank limite de 5000',
                'url' => rtrim((string) config('app.url'), '/').'/credit-cards',
            ],
        ];

        $completed = count(array_filter($steps, fn ($step) => (bool) ($step['done'] ?? false)));

        return [
            'total' => count($steps),
            'completed' => $completed,
            'steps' => $steps,
        ];
    }
}

