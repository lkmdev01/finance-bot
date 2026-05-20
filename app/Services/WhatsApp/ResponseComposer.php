<?php

namespace App\Services\WhatsApp;

use App\Models\User;

class ResponseComposer
{
    public function composeGreeting(User $user): string
    {
        $firstName = trim((string) explode(' ', trim($user->name))[0]);
        $namePart = $firstName !== '' ? " {$firstName}" : '';

        return "Olá{$namePart}! Eu sou o InovaFinance. Posso registrar gastos e receitas, consultar seu saldo, listar suas últimas transações e gerar relatórios.";
    }

    public function composeNeutralAcknowledgement(?string $lastAction = null, array $lastEntities = []): string
    {
        return match ($lastAction) {
            'query_budgets' => $this->composeBudgetAcknowledgement($lastEntities),
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

    private function composeBudgetAcknowledgement(array $lastEntities): string
    {
        if (! empty($lastEntities['category_name'])) {
            return 'Perfeito. Se quiser, eu posso comparar essa categoria com o mês passado, com outra categoria ou te mostrar quanto ainda sobra no orçamento.';
        }

        if (($lastEntities['comparison_mode'] ?? null) !== null) {
            return 'Perfeito. Se quiser, eu posso detalhar uma categoria específica, mudar o período da comparação ou registrar um gasto novo.';
        }

        return 'Perfeito. Se quiser, eu posso consultar outra categoria, comparar com o mês passado ou te dizer qual orçamento está mais apertado.';
    }
}
