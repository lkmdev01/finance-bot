<?php

namespace App\Services;

class AIPromptBuilder
{
    /**
     * Builds the full prompt for the LLM (system + context + user message).
     * Keep this file strictly UTF-8 to avoid "mojibake" leaking into replies.
     */
    public function build(string $message, array $context): string
    {
        $formattedContext = $this->formatContextForAI($context);
        $userName = $context['user_name'] ?? 'Usuário';

        return $this->buildSystemPrompt($userName)
            . "\n\n"
            . $formattedContext
            . "\n\n"
            . $this->buildUserMessage($message);
    }

    /**
     * Formats app context for the LLM in a compact, readable way.
     */
    private function formatContextForAI(array $context): string
    {
        $out = [];

        $financial = $context['financial_data'] ?? [];
        if (! empty($financial)) {
            $out[] = 'DADOS FINANCEIROS';
            $out[] = 'Ganhos totais: R$ ' . number_format($financial['total_income_all_time'] ?? 0, 2, ',', '.');
            $out[] = 'Gastos totais: R$ ' . number_format($financial['total_expenses_all_time'] ?? 0, 2, ',', '.');
            $out[] = 'Poupado: R$ ' . number_format($financial['total_savings'] ?? 0, 2, ',', '.');
            $out[] = 'Saldo atual: R$ ' . number_format($financial['available_balance'] ?? 0, 2, ',', '.');

            if (! empty($financial['current_month'])) {
                $out[] = sprintf(
                    'Mês atual (%s): entradas R$ %s | saídas R$ %s | saldo R$ %s',
                    (string) $financial['current_month'],
                    number_format($financial['monthly_income'] ?? 0, 2, ',', '.'),
                    number_format($financial['monthly_expenses'] ?? 0, 2, ',', '.'),
                    number_format($financial['monthly_balance'] ?? 0, 2, ',', '.')
                );
            }

            if (! empty($financial['expenses_by_category_this_month'])) {
                $top = array_slice($financial['expenses_by_category_this_month'], 0, 5);
                $out[] = 'Categorias (mês): ' . implode(', ', array_map(
                    fn ($cat) => "{$cat['category_name']}: R$ " . number_format($cat['total'], 0, ',', '.'),
                    $top
                ));
            }

            if (! empty($financial['monthly_evolution'])) {
                $last = array_slice($financial['monthly_evolution'], -3);
                $out[] = 'Evolução (3 meses): ' . implode(' | ', array_map(
                    fn ($m) => "{$m['month']}: R$ " . number_format($m['balance'], 0, ',', '.'),
                    $last
                ));
            }

            if (! empty($financial['exceeded_budgets'])) {
                $out[] = 'Orçamentos excedidos: ' . implode(', ', array_map(
                    fn ($b) => "{$b['category_name']} (R$ " . number_format($b['exceeded_by'], 0, ',', '.') . ')',
                    $financial['exceeded_budgets']
                ));
            }

            if (! empty($financial['savings_goals'])) {
                $active = array_values(array_filter($financial['savings_goals'], fn ($g) => ! ($g['is_completed'] ?? false)));
                $active = array_slice($active, 0, 5);
                if (! empty($active)) {
                    $out[] = 'Metas ativas: ' . implode(' | ', array_map(
                        fn ($g) => "{$g['name']}: {$g['progress_percentage']}% (falta R$ " . number_format($g['remaining_amount'], 0, ',', '.') . ')',
                        $active
                    ));
                }
            }
        }

        $transactions = $context['recent_transactions'] ?? [];
        if (! empty($transactions)) {
            $out[] = 'ÚLTIMAS TRANSAÇÕES';
            foreach (array_slice($transactions, 0, 10) as $t) {
                $sign = ($t['type'] ?? '') === 'income' ? '+' : '-';
                $line = sprintf(
                    'ID %s: %s %s - %s - R$ %s',
                    (string) ($t['id'] ?? '?'),
                    (string) ($t['date'] ?? ''),
                    $sign,
                    (string) ($t['description'] ?? ''),
                    number_format((float) ($t['amount'] ?? 0), 2, ',', '.')
                );
                if (! empty($t['category'])) {
                    $line .= " ({$t['category']})";
                }
                $out[] = $line;
            }
        }

        $categories = $context['categories'] ?? [];
        if (! empty($categories)) {
            $out[] = 'CATEGORIAS DISPONÍVEIS';
            foreach ($categories as $cat) {
                $out[] = "ID {$cat['id']}: {$cat['name']}";
            }
        }

        $conversation = $context['contact_context'] ?? [];
        if (! empty($conversation)) {
            $out[] = 'HISTÓRICO RECENTE (2 mensagens)';
            foreach (array_slice($conversation, -2) as $msg) {
                $out[] = 'U: "' . ($msg['message'] ?? '') . '" | V: "' . ($msg['reply'] ?? '') . '"';
            }
        }

        return implode("\n", $out);
    }

    private function buildSystemPrompt(string $userName = 'Usuário'): string
    {
        return <<<PROMPT
Você é o InovaFinance, um assistente financeiro amigável no WhatsApp.

Regras críticas:
1. Se houver dúvida ou faltar informação para executar com segurança, peça esclarecimento (1 pergunta objetiva).
2. Não invente dados. Use apenas as informações do contexto fornecido.
3. Se o usuário confirmar algo ("sim", "pode"), execute a ação baseada no histórico.
4. Gere relatórios (PDF/CSV/Excel) imediatamente quando solicitado.
5. Em respostas de consulta, seja claro, objetivo e específico.
6. Para saudações simples (ex: "oi"), responda curto e acolhedor. Não faça uma apresentação longa.

Regras de categorização e descrição:
1. Categorias oficiais (exemplos): Alimentação, Transporte, Saúde, Educação, Lazer, Casa, Compras, Salário, Pets.
2. Extração de descrição:
   - "Gastei 50 no Burger King" -> descrição "Burger King", categoria "Alimentação"
   - "Recebi 1200 do serviço" -> descrição "Serviço", categoria "Salário"
3. Mensagens vagas:
   - Se o usuário disser apenas o valor (ex: "Gastei 100" ou "Ganhei 200"), deixe category_id null e description null.
   - Não invente categorias se não houver pista na mensagem.
4. Prioridade de ID: se a categoria estiver na lista de categorias do contexto, use o ID exato.

AÇÕES permitidas:
create_transaction, edit_transaction, delete_transaction,
query_balance, query_expenses, query_income, query_transactions, query_category,
query_report, query_report_pdf, query_report_csv, query_report_excel,
query_savings, query_budgets, query_evolution, query_projections, query_subscriptions

Regras técnicas (JSON válido - crítico):
1. Responda apenas um objeto JSON em uma única linha.
2. Não use quebras de linha reais dentro do campo "reply". Use \\n literal.
3. Sempre inclua os campos: intent, confidence, data, missing_fields, needs_confirmation, user_friendly_summary, reply, action.
4. "intent" deve ser uma destas chaves:
   create_expense, create_income, query_balance, query_category_spending, query_month_report,
   update_transaction, delete_transaction, create_budget, list_transactions, help, unknown
5. "data" deve ser um objeto. Se não houver dados, use {}.
6. "missing_fields" deve ser uma lista. Se não faltar nada, use [].
7. "needs_confirmation" deve ser true ou false.

Exemplos de resposta (JSON apenas):
{"intent":"create_expense","confidence":0.97,"data":{"type":"expense","amount":50.0,"description":"Burger King","category_id":1,"date":"\$today"},"missing_fields":[],"needs_confirmation":false,"user_friendly_summary":"Despesa de R$ 50,00 em Burger King","reply":"OK. Registrei R$ 50,00 em Alimentacao (Burger King).","action":"create_transaction","transaction_data":{"type":"expense","amount":50.0,"description":"Burger King","category_id":1,"date":"\$today"},"transaction_id":null}
{"intent":"create_income","confidence":0.96,"data":{"type":"income","amount":1200.0,"description":"Salario","category_id":null,"date":"\$today"},"missing_fields":[],"needs_confirmation":false,"user_friendly_summary":"Receita de R$ 1.200,00 registrada","reply":"OK. Receita de R$ 1.200,00 registrada.","action":"create_transaction","transaction_data":{"type":"income","amount":1200.0,"description":"Salario","category_id":null,"date":"\$today"},"transaction_id":null}
{"intent":"list_transactions","confidence":0.93,"data":{},"missing_fields":[],"needs_confirmation":false,"user_friendly_summary":"Lista das ultimas transacoes","reply":"Seus ultimos gastos:\\n- 02/04 - Uber: R$ 18,00\\n- 01/04 - Supermercado: R$ 45,00","action":"query_transactions","transaction_data":null,"transaction_id":null}
PROMPT;
    }

    /**
     * Compact user message wrapper used by the LLM.
     */
    private function buildUserMessage(string $message): string
    {
        return 'Mensagem do usuário: "' . $message . "\"\nRESPONDA APENAS JSON:";
    }
}
