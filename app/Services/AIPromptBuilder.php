<?php

namespace App\Services;

class AIPromptBuilder
{
    /**
     * Constrói o contexto completo para a IA
     */
    public function build(string $message, array $context): string
    {
        $formattedContext = $this->formatContextForAI($context);
        $userName = $context['user_name'] ?? 'Usuário';

        return $this->buildSystemPrompt($userName)."\n\n".$formattedContext."\n\n".$this->buildUserMessage($message);
    }

    /**
     * Constrói o contexto de forma mais legível e compacta para a IA
     */
    private function formatContextForAI(array $context): string
    {
        $output = [];

        // Dados financeiros principais
        $financial = $context['financial_data'] ?? [];
        if (! empty($financial)) {
            $output[] = '💰 DADOS FINANCEIROS:';
            $output[] = 'Ganhos Totais: R$ '.number_format($financial['total_income_all_time'] ?? 0, 2, ',', '.');
            $output[] = 'Gastos Totais: R$ '.number_format($financial['total_expenses_all_time'] ?? 0, 2, ',', '.');
            $output[] = 'Poupado: R$ '.number_format($financial['total_savings'] ?? 0, 2, ',', '.');
            $output[] = 'Saldo Atual: R$ '.number_format($financial['available_balance'] ?? 0, 2, ',', '.');
            
            $output[] = "📅 MÊS ATUAL ({$financial['current_month']}): Rec: R$ ".number_format($financial['monthly_income'] ?? 0, 2, ',', '.').
                        ' | Desp: R$ '.number_format($financial['monthly_expenses'] ?? 0, 2, ',', '.').
                        ' | Saldo: R$ '.number_format($financial['monthly_balance'] ?? 0, 2, ',', '.');

            if (! empty($financial['expenses_by_category_this_month'])) {
                $output[] = '📋 CATEGORIAS (Mês): ' . implode(', ', array_map(fn($cat) => "{$cat['category_name']}: R$ ".number_format($cat['total'], 0, ',', '.'), array_slice($financial['expenses_by_category_this_month'], 0, 5)));
            }

            // Evolução mensal (últimos 3 meses)
            if (! empty($financial['monthly_evolution'])) {
                $output[] = '📈 EVOLUÇÃO: ' . implode(' | ', array_map(fn($m) => "{$m['month']}: Bal. R$ ".number_format($m['balance'], 0, ',', '.'), array_slice($financial['monthly_evolution'], -3)));
            }

            // Orçamentos excedidos
            if (! empty($financial['exceeded_budgets'])) {
                $output[] = '⚠️ EXCEDEU: ' . implode(', ', array_map(fn($b) => "{$b['category_name']} (R$ ".number_format($b['exceeded_by'], 0, ',', '.').")", $financial['exceeded_budgets']));
            }

            // Metas de poupança (ativas)
            if (! empty($financial['savings_goals'])) {
                $output[] = '🎯 METAS: ' . implode(' | ', array_map(fn($g) => "{$g['name']}: {$g['progress_percentage']}% (Falta R$ ".number_format($g['remaining_amount'], 0, ',', '.').")", array_filter($financial['savings_goals'], fn($g) => !$g['is_completed'])));
            }
        }

        // Transações recentes
        $transactions = $context['recent_transactions'] ?? [];
        if (! empty($transactions)) {
            $output[] = '📝 ÚLTIMAS TRANSAÇÕES:';
            foreach (array_slice($transactions, 0, 10) as $t) {
                $type = $t['type'] === 'income' ? '💰' : '💸';
                $output[] = "ID {$t['id']}: {$type} {$t['date']} - {$t['description']} - R$ ".number_format($t['amount'], 2, ',', '.').($t['category'] ? " ({$t['category']})" : '');
            }
        }

        // Categorias disponíveis
        $categories = $context['categories'] ?? [];
        if (! empty($categories)) {
            $output[] = '📁 CATEGORIAS: ' . implode(', ', array_map(fn($cat) => "ID {$cat['id']}: {$cat['name']}", $categories));
        }

        // Contexto de conversa recente (últimas 2 interações)
        $conversation = $context['contact_context'] ?? [];
        if (! empty($conversation)) {
            $output[] = '💬 HISTÓRICO RECENTE:';
            foreach (array_slice($conversation, -2) as $msg) {
                $output[] = "U: \"{$msg['message']}\" | V: \"{$msg['reply']}\"";
            }
        }

        return implode("\n", $output);
    }

    /**
     * Constrói o prompt do sistema completo e otimizado
     */
    private function buildSystemPrompt(string $userName = 'Usuário'): string
    {
        $today = now()->format('Y-m-d');
        $todayFormatted = now()->format('d/m/Y');
        
        return <<<PROMPT
Você é o FinanciBot, assistente financeiro amigável para o usuário {$userName}.

**DATA ATUAL: {$todayFormatted}** (use {$today} no campo "date")

REGRAS CRÍTICAS:
1. Responda DIRETAMENTE usando os dados do contexto. NUNCA diga "Como posso ajudar?" ou "Preciso verificar" se os dados já estão no contexto.
2. Seja NATURAL e use emojis.
3. Se o usuário confirmar algo ("sim", "pode"), execute a ação baseada no histórico.
4. Gere relatórios (PDF/CSV/Excel) imediatamente quando solicitado.
5. Para DELETAR/EDITAR: Use o ID da transação que combina com a descrição do usuário.
6. **IMPORTANTE:** Se o usuário NÃO mencionar uma data específica, use SEMPRE a data de hoje: {$today}

AÇÕES: create_transaction, edit_transaction, delete_transaction, query_balance, query_expenses, query_income, query_transactions, query_category, query_report, query_report_pdf, query_report_csv, query_report_excel, query_savings, query_budgets, query_evolution, query_projections.

### REGRAS TÉCNICAS (JSON VÁLIDO - CRÍTICO)
1. Responda **APENAS** o objeto JSON em uma **ÚNICA LINHA**.
2. **PROIBIDO:** Pressionar a tecla Enter dentro das aspas do campo "reply".
3. Use `\n` literal para quebras de linha no texto.

### CATEGORIZAÇÃO INTELIGENTE (OBRIGATÓRIO)
1. **PREFERÊNCIA:** Se a descrição condiz com uma categoria em 📁 CATEGORIAS, você **DEVE** usar o ID correspondente.
2. **NÃO ENCONTROU?** Se não houver correspondência exata, sugira uma nova:
   - Defina `category_id` como `null`.
   - Preencha `"category_name": "Nome"` e `"category_icon": "Emoji"`.
3. **PROIBIDO:** Usar "Sem categoria", "Outros" ou "Nenhum" se puder sugerir algo específico (ex: use "Lanche" ou "Alimentação" para uma Pizza).

### TEMPLATE DE ESTÉTICA (COPIE EXATAMENTE)
{"reply": "[Emoji] [Ação]\n*Valor:* R$ [valor]\n*Categoria:* [Nome]\n*Data:* [Data]", "action": "create_transaction", "transaction_data": {"type": "expense", "amount": 0.0, "description": "...", "category_id": 1, "date": "2026-01-17"}, "transaction_id": null}

Exemplo de Nova Categoria (ID NULL):
{"reply": "Gasto registrado🐶\n*Valor:* R$ 150,00\n*Categoria:* Pets (Nova)\n*Data:* 17/01/2026", "action": "create_transaction", "transaction_data": {"type": "expense", "amount": 150.0, "description": "Veterinario", "category_id": null, "category_name": "Pets", "category_icon": "🐶", "date": "2026-01-17"}, "transaction_id": null}

### FORMATO DE RESPOSTA (JSON APENAS)
Responda APENAS o JSON em uma única linha sem quebras de linha reais.
PROMPT;
    }

    /**
     * Constrói a mensagem do usuário (compacta)
     */
    private function buildUserMessage(string $message): string
    {
        return "Mensagem do usuário: \"{$message}\"\nRESPONDA APENAS JSON:";
    }
}
