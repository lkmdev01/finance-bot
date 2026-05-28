<?php

namespace App\Services\WhatsApp;

use App\Models\User;
use App\Services\Onboarding\OnboardingChecklistService;

class ResponseComposer
{
    public function composeGreeting(User $user): string
    {
        $firstName = trim((string) explode(' ', trim($user->name))[0]);
        $namePart = $firstName !== '' ? " {$firstName}" : '';

        $base = "Ola{$namePart}! Eu sou o InovaFinance. Posso registrar gastos e receitas, consultar seu saldo, listar suas ultimas transacoes e gerar relatorios.";

        $checklist = app(OnboardingChecklistService::class)->checklist($user);
        if (($checklist['completed'] ?? 0) >= ($checklist['total'] ?? 3)) {
            return $base;
        }

        $lines = [];
        foreach ($checklist['steps'] ?? [] as $step) {
            $done = (bool) ($step['done'] ?? false);
            $title = (string) ($step['title'] ?? '');
            if ($title === '') {
                continue;
            }

            $lines[] = sprintf('%s %s', $done ? '[x]' : '[ ]', $title);
        }

        if ($lines === []) {
            return $base;
        }

        $progress = sprintf('%d/%d', (int) ($checklist['completed'] ?? 0), (int) ($checklist['total'] ?? 3));

        return $base
            ."\n\nChecklist rapida ({$progress}):\n"
            .implode("\n", $lines)
            ."\n\nSe quiser, me diga um passo e eu te guio com um exemplo.";
    }

    public function composeNeutralAcknowledgement(?string $lastAction = null, array $lastEntities = []): string
    {
        return match ($lastAction) {
            'query_budgets' => $this->composeBudgetAcknowledgement($lastEntities),
            'query_transactions', 'query_category' => 'Perfeito. Se quiser, eu posso aprofundar essa consulta, registrar um gasto ou comparar com outro periodo.',
            'query_savings' => 'Perfeito. Se quiser, eu posso abrir uma meta especifica, comparar o andamento ou calcular quanto falta para concluir.',
            'query_subscriptions' => 'Perfeito. Se quiser, eu posso olhar uma assinatura especifica, o proximo vencimento ou o peso mensal delas.',
            'query_projections' => 'Perfeito. Se quiser, eu posso abrir um mes especifico, olhar daqui a 3 meses ou comparar com seu saldo atual.',
            default => 'Perfeito. Se quiser, eu posso registrar um gasto, consultar seu saldo, olhar seus orcamentos ou gerar um relatorio.',
        };
    }

    public function composePendingConfirmationPrompt(): string
    {
        return 'Estou aguardando sua confirmacao da acao anterior. Se quiser confirmar, responda com algo como "sim" ou "ok". Se quiser cancelar, diga "cancelar".';
    }

    public function composeCancellationReply(): string
    {
        return 'Certo, cancelei essa acao pendente. Se quiser, podemos seguir com outra coisa.';
    }

    public function composeClarificationReply(string $topic): string
    {
        return "Posso continuar com {$topic}, mas preciso que voce me diga um pouco mais para eu acertar a resposta.";
    }

    private function composeBudgetAcknowledgement(array $lastEntities): string
    {
        if (! empty($lastEntities['category_name'])) {
            return 'Perfeito. Se quiser, eu posso comparar essa categoria com o mes passado, com outra categoria ou te mostrar quanto ainda sobra no orcamento.';
        }

        if (($lastEntities['comparison_mode'] ?? null) !== null) {
            return 'Perfeito. Se quiser, eu posso detalhar uma categoria especifica, mudar o periodo da comparacao ou registrar um gasto novo.';
        }

        return 'Perfeito. Se quiser, eu posso consultar outra categoria, comparar com o mes passado ou te dizer qual orcamento esta mais apertado.';
    }
}
