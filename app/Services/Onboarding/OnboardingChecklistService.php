<?php

namespace App\Services\Onboarding;

use App\Models\User;

class OnboardingChecklistService
{
    /**
     * @return array{
     *   total: int,
     *   completed: int,
     *   steps: array<int, array{key: string, title: string, done: bool, hint: string, example: string|null, url: string|null}>,
     *   next_step: array{key: string, title: string, done: bool, hint: string, example: string|null, url: string|null}|null
     * }
     */
    public function checklist(User $user): array
    {
        $hasTransaction = $user->transactions()->exists();
        $hasWhatsApp = $user->whatsapp_verified_at !== null;

        $steps = [
            [
                'key' => 'whatsapp',
                'title' => 'Ativar seu WhatsApp',
                'done' => $hasWhatsApp,
                'hint' => 'Esse é o canal mais rápido para registrar e consultar suas finanças.',
                'example' => null,
                'url' => route('whatsapp.settings'),
            ],
            [
                'key' => 'transaction',
                'title' => 'Registrar sua primeira transação',
                'done' => $hasTransaction,
                'hint' => 'Envie uma frase simples e veja saldo, gráficos e histórico ganharem contexto.',
                'example' => 'gastei 20 no uber',
                'url' => rtrim((string) config('app.url'), '/').'/transactions/create',
            ],
        ];

        $completed = count(array_filter($steps, fn ($step) => (bool) ($step['done'] ?? false)));
        $nextStep = collect($steps)->first(fn (array $step) => ! ($step['done'] ?? false));

        return [
            'total' => count($steps),
            'completed' => $completed,
            'steps' => $steps,
            'next_step' => $nextStep,
        ];
    }
}
