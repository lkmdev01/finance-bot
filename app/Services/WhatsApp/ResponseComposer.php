<?php

namespace App\Services\WhatsApp;

use App\Models\User;
use App\Models\WhatsAppContact;

class ResponseComposer
{
    public function composeGreeting(User $user): string
    {
        $firstName = trim((string) explode(' ', trim($user->name))[0]);
        $namePart = $firstName !== '' ? " {$firstName}" : '';

        return "Olá{$namePart}! Eu sou o InovaFinance. Posso registrar gastos e receitas, consultar seu saldo, listar suas últimas transações e gerar relatórios.";
    }

    public function composeNeutralAcknowledgement(?string $lastAction = null): string
    {
        return match ($lastAction) {
            'query_budgets' => 'Perfeito. Se quiser, eu posso consultar outro orçamento, registrar um gasto ou te mostrar seu saldo.',
            'query_transactions', 'query_category' => 'Perfeito. Se quiser, eu posso aprofundar essa consulta, registrar um gasto ou comparar com outro período.',
            default => 'Perfeito. Se quiser, eu posso registrar um gasto, consultar seu saldo, olhar seus orçamentos ou gerar um relatório.',
        };
    }

    public function composePendingConfirmationPrompt(): string
    {
        return 'Estou aguardando sua confirmação da ação anterior. Se quiser confirmar, responda com algo como "sim" ou "ok". Se quiser cancelar, diga "cancelar".';
    }

    public function composeCancellationReply(): string
    {
        return 'Certo, cancelei essa ação pendente. Se quiser, podemos seguir com outra coisa.';
    }

    public function composeClarificationReply(string $topic): string
    {
        return "Posso continuar com {$topic}, mas preciso que você me diga um pouco mais para eu acertar a resposta.";
    }
}
