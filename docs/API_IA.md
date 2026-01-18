# Documentação da API da IA

Esta documentação descreve como a IA processa mensagens e quais ações estão disponíveis.

## 📋 Visão Geral

A IA do FinanciApp processa mensagens em português do usuário via WhatsApp e retorna respostas estruturadas em JSON.

## 🔄 Fluxo de Processamento

1. **Recebimento**: Mensagem do usuário via WhatsApp
2. **Normalização**: Mensagem é normalizada e sanitizada
3. **Contexto**: Dados financeiros do usuário são coletados
4. **Prompt**: Prompt completo é construído com contexto
5. **IA**: Prompt é enviado para o provedor de IA (Groq, OpenAI, etc.)
6. **Parsing**: Resposta da IA é parseada
7. **Ação**: Ação é executada (criar transação, consultar, etc.)
8. **Resposta**: Resposta formatada é enviada ao usuário

## 📤 Formato de Resposta

A IA sempre retorna um JSON com a seguinte estrutura:

```json
{
  "reply": "Resposta amigável para o usuário",
  "action": "create_transaction" | "query_balance" | null,
  "transaction_data": {
    "type": "expense" | "income",
    "amount": 50.00,
    "description": "Supermercado",
    "category_id": 1,
    "date": "2026-01-08"
  }
}
```

## 🎯 Ações Disponíveis

### `create_transaction`

Cria uma nova transação (receita ou despesa).

**Reconhecimento**:
- Despesas: "gastei", "comprei", "paguei", "perdi"
- Receitas: "recebi", "ganhei", "entrou", "caiu na conta"

**Exemplo de Resposta**:
```json
{
  "reply": "✅ Registrei sua despesa!\n\n💰 R$ 50,00 - Supermercado\n📅 08/01/2026",
  "action": "create_transaction",
  "transaction_data": {
    "type": "expense",
    "amount": 50.00,
    "description": "Supermercado",
    "category_id": 1,
    "date": "2026-01-08"
  }
}
```

### `query_balance`

Consulta o saldo disponível do usuário.

**Reconhecimento**: "quanto tenho", "qual meu saldo", "quanto disponível"

**Exemplo de Resposta**:
```json
{
  "reply": "💰 Seu saldo disponível é R$ 1.234,56",
  "action": "query_balance"
}
```

### `query_expenses`

Consulta gastos/despesas.

**Reconhecimento**: "quanto gastei", "quanto saiu", "total de despesas"

**Exemplo de Resposta**:
```json
{
  "reply": "💸 Você gastou R$ 1.765,44 este mês",
  "action": "query_expenses"
}
```

### `query_income`

Consulta receitas/ganhos.

**Reconhecimento**: "quanto recebi", "quanto ganhei", "total de ganhos"

**Exemplo de Resposta**:
```json
{
  "reply": "💰 Você recebeu R$ 3.000,00 este mês",
  "action": "query_income"
}
```

### `query_transactions`

Lista transações recentes.

**Reconhecimento**: "transações", "extrato", "últimas transações"

**Exemplo de Resposta**:
```json
{
  "reply": "📝 Suas últimas transações:\n\n💰 08/01: R$ 50,00 - Supermercado\n💸 07/01: R$ 200,00 - Restaurante",
  "action": "query_transactions"
}
```

### `query_category`

Consulta gastos por categoria.

**Reconhecimento**: "quanto gastei em [categoria]", "gastos em [categoria]"

**Exemplo de Resposta**:
```json
{
  "reply": "💸 Você gastou R$ 450,00 em Supermercado este mês (25% do total)",
  "action": "query_category"
}
```

### `query_report`

Resumo financeiro completo.

**Reconhecimento**: "resumo", "relatório", "como está"

**Exemplo de Resposta**:
```json
{
  "reply": "📊 Resumo Financeiro:\n\n💰 Total de ganhos: R$ 5.000,00\n💸 Total de despesas: R$ 3.000,00\n💵 Saldo disponível: R$ 2.000,00",
  "action": "query_report"
}
```

### `query_savings`

Consulta metas de poupança.

**Reconhecimento**: "metas de poupança", "quanto tenho poupado"

### `query_budgets`

Consulta orçamentos excedidos.

**Reconhecimento**: "orçamentos excedidos", "orçamento estourado"

### `query_evolution`

Consulta evolução/tendências.

**Reconhecimento**: "evolução", "tendência", "comparação"

### `query_projections`

Consulta projeções financeiras.

**Reconhecimento**: "projeções financeiras", "projeções futuras"

### `query_income_source`

Consulta origem dos ganhos.

**Reconhecimento**: "de onde veio", "origem dos ganhos"

### `query_categories`

Lista categorias disponíveis.

**Reconhecimento**: "quais categorias", "lista de categorias"

## 📊 Contexto Fornecido

A IA recebe o seguinte contexto financeiro:

- **Transações recentes** (últimas 15)
- **Categorias disponíveis**
- **Dados financeiros**:
  - Saldo disponível
  - Receitas/despesas do mês
  - Totais de todos os tempos
  - Despesas por categoria
  - Comparação com mês anterior
  - Evolução mensal/anual
  - Orçamentos excedidos
  - Metas de poupança
  - Projeções financeiras
- **Histórico de conversa** (últimas 5 interações)

## ⚠️ Regras Importantes

1. **Nunca pedir confirmação**: A IA registra transações diretamente
2. **Respostas diretas**: Se tem os dados, responde imediatamente
3. **Uso de histórico**: Usa histórico para entender confirmações
4. **Formato brasileiro**: Valores sempre em R$ com vírgula decimal
5. **Emojis estratégicos**: Usa emojis para melhorar legibilidade

## 🔧 Configuração

A IA pode ser configurada via `.env`:

```env
AI_PROVIDER=groq  # groq, openai, gemini, ollama
AI_API_KEY=sua-chave
GROQ_MODEL=llama-3.1-8b-instant
```

## 📝 Exemplos de Uso

### Criar Despesa
```
Usuário: "Gastei 50 reais no supermercado"
IA: ✅ Registrei sua despesa!...
```

### Consultar Saldo
```
Usuário: "Qual é o meu saldo?"
IA: 💰 Seu saldo disponível é R$ 1.234,56
```

### Resumo Financeiro
```
Usuário: "Me dê um resumo"
IA: 📊 Resumo Financeiro:...
```

## 🐛 Troubleshooting

### IA não reconhece transação
- Verifique se a mensagem contém valor e descrição
- Verifique se há categorias cadastradas

### IA pede confirmação
- Verifique o prompt do sistema
- A IA deve ter acesso aos dados no contexto

### Resposta não formatada
- Verifique se `WhatsAppFormatter` está sendo usado
- Verifique se a resposta contém JSON válido
