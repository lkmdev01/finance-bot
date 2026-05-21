<?php

namespace App\Services;

class AIPromptBuilder
{
    /**
     * ConstrÃ³i o contexto completo para a IA
     */
    public function build(string $message, array $context): string
    {
        $formattedContext = $this->formatContextForAI($context);
        $userName = $context['user_name'] ?? 'UsuÃ¡rio';

        return $this->buildSystemPrompt($userName)."\n\n".$formattedContext."\n\n".$this->buildUserMessage($message);
    }

    /**
     * ConstrÃ³i o contexto de forma mais legÃ­vel e compacta para a IA
     */
    private function formatContextForAI(array $context): string
    {
        $output = [];

        // Dados financeiros principais
        $financial = $context['financial_data'] ?? [];
        if (! empty($financial)) {
            $output[] = 'ðŸ’° DADOS FINANCEIROS:';
            $output[] = 'Ganhos Totais: R$ '.number_format($financial['total_income_all_time'] ?? 0, 2, ',', '.');
            $output[] = 'Gastos Totais: R$ '.number_format($financial['total_expenses_all_time'] ?? 0, 2, ',', '.');
            $output[] = 'Poupado: R$ '.number_format($financial['total_savings'] ?? 0, 2, ',', '.');
            $output[] = 'Saldo Atual: R$ '.number_format($financial['available_balance'] ?? 0, 2, ',', '.');
            
            $output[] = "ðŸ“… MÃŠS ATUAL ({$financial['current_month']}): Rec: R$ ".number_format($financial['monthly_income'] ?? 0, 2, ',', '.').
                        ' | Desp: R$ '.number_format($financial['monthly_expenses'] ?? 0, 2, ',', '.').
                        ' | Saldo: R$ '.number_format($financial['monthly_balance'] ?? 0, 2, ',', '.');

            if (! empty($financial['expenses_by_category_this_month'])) {
                $output[] = 'ðŸ“‹ CATEGORIAS (MÃªs): ' . implode(', ', array_map(fn($cat) => "{$cat['category_name']}: R$ ".number_format($cat['total'], 0, ',', '.'), array_slice($financial['expenses_by_category_this_month'], 0, 5)));
            }

            // EvoluÃ§Ã£o mensal (Ãºltimos 3 meses)
            if (! empty($financial['monthly_evolution'])) {
                $output[] = 'ðŸ“ˆ EVOLUÃ‡ÃƒO: ' . implode(' | ', array_map(fn($m) => "{$m['month']}: Bal. R$ ".number_format($m['balance'], 0, ',', '.'), array_slice($financial['monthly_evolution'], -3)));
            }

            // OrÃ§amentos excedidos
            if (! empty($financial['exceeded_budgets'])) {
                $output[] = 'âš ï¸ EXCEDEU: ' . implode(', ', array_map(fn($b) => "{$b['category_name']} (R$ ".number_format($b['exceeded_by'], 0, ',', '.').")", $financial['exceeded_budgets']));
            }

            // Metas de poupanÃ§a (ativas)
            if (! empty($financial['savings_goals'])) {
                $output[] = 'ðŸŽ¯ METAS: ' . implode(' | ', array_map(fn($g) => "{$g['name']}: {$g['progress_percentage']}% (Falta R$ ".number_format($g['remaining_amount'], 0, ',', '.').")", array_filter($financial['savings_goals'], fn($g) => !$g['is_completed'])));
            }
        }

        // TransaÃ§Ãµes recentes
        $transactions = $context['recent_transactions'] ?? [];
        if (! empty($transactions)) {
            $output[] = 'ðŸ“ ÃšLTIMAS TRANSAÃ‡Ã•ES:';
            foreach (array_slice($transactions, 0, 10) as $t) {
                $type = $t['type'] === 'income' ? 'ðŸ’°' : 'ðŸ’¸';
                $output[] = "ID {$t['id']}: {$type} {$t['date']} - {$t['description']} - R$ ".number_format($t['amount'], 2, ',', '.').($t['category'] ? " ({$t['category']})" : '');
            }
        }

        // Categorias disponÃ­veis
        $categories = $context['categories'] ?? [];
        if (! empty($categories)) {
            $output[] = 'ðŸ“ CATEGORIAS: ' . implode(', ', array_map(fn($cat) => "ID {$cat['id']}: {$cat['name']}", $categories));
        }

        // Contexto de conversa recente (Ãºltimas 2 interaÃ§Ãµes)
        $conversation = $context['contact_context'] ?? [];
        if (! empty($conversation)) {
            $output[] = 'ðŸ’¬ HISTÃ“RICO RECENTE:';
            foreach (array_slice($conversation, -2) as $msg) {
                $output[] = "U: \"{$msg['message']}\" | V: \"{$msg['reply']}\"";
            }
        }

        return implode("\n", $output);
    }

    /**
     * ConstrÃ³i o prompt do sistema completo e otimizado
     */
    private function buildSystemPrompt(string $userName = 'UsuÃ¡rio'): string
    {
        $today = now()->format('Y-m-d');
        $todayFormatted = now()->format('d/m/Y');
        
        return <<<PROMPT
Voce e o InovaFinance, assistente financeiro amigavel para o usuario {$userName}.

**DATA ATUAL: {$todayFormatted}** (use {$today} no campo "date")

REGRAS CRÃTICAS:
1. Responda DIRETAMENTE usando os dados do contexto. NUNCA diga "Como posso ajudar?".
2. Seja NATURAL e use emojis.
3. Se o usuÃ¡rio confirmar algo ("sim", "pode"), execute a aÃ§Ã£o baseada no histÃ³rico.
4. Gere relatÃ³rios (PDF/CSV/Excel) imediatamente quando solicitado.
5. Em respostas de consulta, seja claro, objetivo e especifico. Evite frases vagas como "O valor foi registrado".
6. Quando o usuario pedir para gerar um relatorio sem formato especifico, prefira a acao `query_report_pdf`.
7. Para saudaÃ§Ãµes simples como "oi", responda de forma curta e acolhedora, sem se apresentar com outro nome.

### REGRAS DE CATEGORIZAÃ‡ÃƒO E DESCRIÃ‡ÃƒO (MUITO IMPORTANTE)
1. **CATEGORIAS OFICIAIS:** Priorize estas categorias: AlimentaÃ§Ã£o, Transporte, SaÃºde, EducaÃ§Ã£o, Lazer, Casa, Compras, SalÃ¡rio, Pets.
2. **EXTRAÃ‡ÃƒO DE DESCRIÃ‡ÃƒO:** 
   - Se o usuÃ¡rio disser "Gastei 50 no Burger King", a descriÃ§Ã£o Ã© "Burger King" e a categoria "AlimentaÃ§Ã£o".
   - Se o usuÃ¡rio disser "Recebi 1200 do serviÃ§o", a descriÃ§Ã£o Ã© "ServiÃ§o" e a categoria "SalÃ¡rio".
3. **MENSAGENS VAGAS:** 
   - Se o usuÃ¡rio disser APENAS o valor (ex: "Gastei 100" ou "Ganhei 200"), deixe `category_id: null` e `description: null`. 
   - NÃ£o invente categorias se nÃ£o houver pista na mensagem.
4. **PRIORIDADE DE ID:** Se o nome da categoria que vocÃª identificou estÃ¡ na lista ðŸ“ CATEGORIAS abaixo, vocÃª **DEVE** usar o `id` exato dessa categoria.
5. **CONSULTAS SOBRE GASTOS ESPECIFICOS:** Para perguntas como "Tenho gastos com uber?", responda com quantidade, total e contexto. Exemplo: "Encontrei 3 gastos com Uber, somando R$ 42,00. O mais recente foi em 02/04."
6. **ULTIMOS GASTOS:** Para pedidos como "quais foram meus Ãºltimos gastos?", responda com uma lista curta e Ãºtil, com data, descriÃ§Ã£o e valor.
7. **EXCLUSAO:** Quando apagar algo, prefira respostas curtas e concretas, como "Apaguei o gasto Supermercado de R$ 12,00."

AÃ‡Ã•ES: create_transaction, edit_transaction, delete_transaction, query_balance, query_expenses, query_income, query_transactions, query_category, query_report, query_report_pdf, query_report_csv, query_report_excel, query_savings, query_budgets, query_evolution, query_projections, query_subscriptions.

### REGRAS TÃ‰CNICAS (JSON VÃLIDO - CRÃTICO)
1. Responda **APENAS** o objeto JSON em uma **ÃšNICA LINHA**.
2. **PROIBIDO:** Pressionar a tecla Enter dentro das aspas do campo "reply".
3. Use `\n` literal para quebras de linha no texto.

### EXEMPLOS DE RESPOSTA (JSON APENAS):

1. Registro de Gasto com Detalhes:
{"reply": "âœ… Registrado: R$ 50,00 em *AlimentaÃ§Ã£o* (Burger King)", "action": "create_transaction", "transaction_data": {"type": "expense", "amount": 50.0, "description": "Burger King", "category_id": 1, "date": "$today"}, "transaction_id": null}

2. Registro de Ganho Vago:
{"reply": "âœ… Receita de R$ 1.200,00 registrada!", "action": "create_transaction", "transaction_data": {"type": "income", "amount": 1200.0, "description": null, "category_id": null, "date": "$today"}, "transaction_id": null}

3. Chat Geral / Ajuda:
{"reply": "Ola! Eu sou o InovaFinance. Posso te ajudar a registrar seus gastos, consultar seu saldo ou gerar relatorios.", "action": null, "transaction_data": null, "transaction_id": null}

4. Consulta de gastos especificos:
{"reply": "Encontrei 2 gastos com Uber, somando R$ 37,00. O mais recente foi em 02/04.", "action": "query_category", "transaction_data": null, "transaction_id": null}

5. Excluir ultima transacao:
{"reply": "Apaguei sua ultima transacao.", "action": "delete_transaction", "transaction_data": null, "transaction_id": "ultima"}

6. Gerar relatorio do mes:
{"reply": "Vou gerar o relatorio em PDF do mes atual para voce.", "action": "query_report_pdf", "transaction_data": null, "transaction_id": null}

7. Ultimos gastos:
{"reply": "Seus ultimos gastos:\nâ€¢ 02/04 - Uber: R$ 18,00\nâ€¢ 01/04 - Supermercado: R$ 45,00", "action": "query_transactions", "transaction_data": null, "transaction_id": null}

### FORMATO DE RESPOSTA (JSON APENAS)
Responda APENAS o JSON em uma Ãºnica linha sem quebras de linha reais.
PROMPT;
    }

    /**
     * ConstrÃ³i a mensagem do usuÃ¡rio (compacta)
     */
    private function buildUserMessage(string $message): string
    {
        return "Mensagem do usuÃ¡rio: \"{$message}\"\nRESPONDA APENAS JSON:";
    }
}


