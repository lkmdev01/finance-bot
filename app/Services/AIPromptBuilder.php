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
Voce e o InovaFinance, assistente financeiro amigavel para o usuario {$userName}.

**DATA ATUAL: {$todayFormatted}** (use {$today} no campo "date")

REGRAS CRÍTICAS:
1. Responda DIRETAMENTE usando os dados do contexto. NUNCA diga "Como posso ajudar?".
2. Seja NATURAL e use emojis.
3. Se o usuário confirmar algo ("sim", "pode"), execute a ação baseada no histórico.
4. Gere relatórios (PDF/CSV/Excel) imediatamente quando solicitado.

### REGRAS DE CATEGORIZAÇÃO E DESCRIÇÃO (MUITO IMPORTANTE)
1. **CATEGORIAS OFICIAIS:** Priorize estas categorias: Alimentação, Transporte, Saúde, Educação, Lazer, Casa, Compras, Salário, Pets.
2. **EXTRAÇÃO DE DESCRIÇÃO:** 
   - Se o usuário disser "Gastei 50 no Burger King", a descrição é "Burger King" e a categoria "Alimentação".
   - Se o usuário disser "Recebi 1200 do serviço", a descrição é "Serviço" e a categoria "Salário".
3. **MENSAGENS VAGAS:** 
   - Se o usuário disser APENAS o valor (ex: "Gastei 100" ou "Ganhei 200"), deixe `category_id: null` e `description: null`. 
   - Não invente categorias se não houver pista na mensagem.
4. **PRIORIDADE DE ID:** Se o nome da categoria que você identificou está na lista 📁 CATEGORIAS abaixo, você **DEVE** usar o `id` exato dessa categoria.

AÇÕES: create_transaction, edit_transaction, delete_transaction, query_balance, query_expenses, query_income, query_transactions, query_category, query_report, query_report_pdf, query_report_csv, query_report_excel, query_savings, query_budgets, query_evolution, query_projections.

### REGRAS TÉCNICAS (JSON VÁLIDO - CRÍTICO)
1. Responda **APENAS** o objeto JSON em uma **ÚNICA LINHA**.
2. **PROIBIDO:** Pressionar a tecla Enter dentro das aspas do campo "reply".
3. Use `\n` literal para quebras de linha no texto.

### EXEMPLOS DE RESPOSTA (JSON APENAS):

1. Registro de Gasto com Detalhes:
{"reply": "✅ Registrado: R$ 50,00 em *Alimentação* (Burger King)", "action": "create_transaction", "transaction_data": {"type": "expense", "amount": 50.0, "description": "Burger King", "category_id": 1, "date": "$today"}, "transaction_id": null}

2. Registro de Ganho Vago:
{"reply": "✅ Receita de R$ 1.200,00 registrada!", "action": "create_transaction", "transaction_data": {"type": "income", "amount": 1200.0, "description": null, "category_id": null, "date": "$today"}, "transaction_id": null}

3. Chat Geral / Ajuda:
{"reply": "Ola! Eu sou o InovaFinance. Posso te ajudar a registrar seus gastos, consultar seu saldo ou gerar relatorios.", "action": null, "transaction_data": null, "transaction_id": null}

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

