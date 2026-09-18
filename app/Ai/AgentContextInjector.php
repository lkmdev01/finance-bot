<?php

namespace App\Ai;

use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\FinancialDataCalculator;
use Illuminate\Support\Facades\Cache;

class AgentContextInjector
{
    public function __construct(
        private readonly FinancialDataCalculator $financialCalculator
    ) {}

    public function getContextString(User $user, WhatsAppContact $contact): string
    {
        $financialData = Cache::remember(
            "user.{$user->id}.financial_data",
            300,
            fn () => $this->financialCalculator->calculate($user)
        );

        $out = [];
        
        $out[] = 'DADOS FINANCEIROS DO USUÁRIO';
        $out[] = 'Ganhos totais: R$ ' . number_format($financialData['total_income_all_time'] ?? 0, 2, ',', '.');
        $out[] = 'Gastos totais: R$ ' . number_format($financialData['total_expenses_all_time'] ?? 0, 2, ',', '.');
        $out[] = 'Saldo atual: R$ ' . number_format($financialData['available_balance'] ?? 0, 2, ',', '.');

        if (! empty($financialData['current_month'])) {
            $out[] = sprintf(
                'Mês atual (%s): entradas R$ %s | saídas R$ %s | saldo R$ %s',
                (string) $financialData['current_month'],
                number_format($financialData['monthly_income'] ?? 0, 2, ',', '.'),
                number_format($financialData['monthly_expenses'] ?? 0, 2, ',', '.'),
                number_format($financialData['monthly_balance'] ?? 0, 2, ',', '.')
            );
        }

        $categories = $user->categories()->get();
        if ($categories->isNotEmpty()) {
            $out[] = "\nCATEGORIAS DISPONÍVEIS";
            foreach ($categories as $cat) {
                $out[] = "ID {$cat->id}: {$cat->name}";
            }
        }

        return implode("\n", $out);
    }
}
