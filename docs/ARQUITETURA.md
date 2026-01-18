# Documentação de Arquitetura

Este documento descreve a arquitetura do FinanciApp.

## 🏗️ Visão Geral

FinanciApp é uma aplicação Laravel que gerencia finanças pessoais com integração WhatsApp e IA.

## 📐 Arquitetura do Sistema

```
┌─────────────────┐
│   WhatsApp      │
│   (Baileys)     │
└────────┬────────┘
         │
         │ Webhook
         ▼
┌─────────────────┐
│  Laravel App    │
│  (Backend)      │
└────────┬────────┘
         │
    ┌────┴────┐
    │         │
    ▼         ▼
┌──────┐  ┌────────┐
│ MySQL│  │   IA   │
│      │  │ (Groq) │
└──────┘  └────────┘
```

## 🔄 Fluxo de Mensagens WhatsApp

```
1. Usuário envia mensagem via WhatsApp
   ↓
2. Baileys recebe mensagem
   ↓
3. Baileys envia webhook para Laravel
   ↓
4. WhatsAppWebhookController valida e processa
   ↓
5. ProcessWhatsAppMessage (Job) é despachado
   ↓
6. WhatsAppMessageProcessor processa mensagem
   ↓
7. AIService processa com IA
   ↓
8. Ação é executada (criar transação, consultar, etc.)
   ↓
9. Resposta formatada é enviada via Baileys
```

## 📦 Componentes Principais

### Services

- **AIService**: Orquestra processamento de mensagens com IA
- **AIContextBuilder**: Constrói contexto para IA
- **AIPromptBuilder**: Constrói prompts para IA
- **AIResponseParser**: Parseia respostas da IA
- **FinancialDataCalculator**: Calcula dados financeiros
- **TransactionRepository**: Acesso otimizado a transações
- **WhatsAppMessageProcessor**: Processa mensagens WhatsApp
- **WhatsAppFormatter**: Formata mensagens WhatsApp
- **PhoneNumberService**: Normaliza números de telefone
- **PerformanceMetricsService**: Coleta métricas de performance

### Jobs

- **ProcessWhatsAppMessage**: Processa mensagens em fila

### Controllers

- **WhatsAppWebhookController**: Recebe webhooks do Baileys
- **TransactionExportController**: Exporta transações
- **ReportsExportController**: Exporta relatórios

### Models

- **User**: Usuário
- **Transaction**: Transação financeira
- **Category**: Categoria
- **Budget**: Orçamento
- **SavingsGoal**: Meta de poupança
- **WhatsAppContact**: Contato WhatsApp
- **AuditLog**: Log de auditoria

## 🗄️ Banco de Dados

### Tabelas Principais

- `users`: Usuários
- `transactions`: Transações financeiras
- `categories`: Categorias
- `budgets`: Orçamentos
- `savings_goals`: Metas de poupança
- `whats_app_contacts`: Contatos WhatsApp
- `audit_logs`: Logs de auditoria

### Relacionamentos

```
User
├── transactions (1:N)
├── categories (1:N)
├── budgets (1:N)
├── savings_goals (1:N)
└── whatsAppContacts (1:N)

Transaction
├── user (N:1)
├── category (N:1)
└── whatsappContact (N:1)

Category
└── user (N:1)

Budget
├── user (N:1)
└── category (N:1)

SavingsGoal
└── user (N:1)
```

## 🔐 Segurança

- **Autenticação**: Laravel Fortify
- **Autorização**: Policies
- **Validação**: Form Requests
- **Sanitização**: Input sanitizado antes de processar
- **HMAC**: Validação de webhooks
- **Criptografia**: Dados sensíveis criptografados

## ⚡ Performance

- **Cache**: Dados financeiros em cache (5 minutos)
- **Eager Loading**: Evita N+1 queries
- **Agregações SQL**: Cálculos otimizados
- **Queue**: Processamento assíncrono
- **Índices**: Índices em colunas frequentes

## 📊 Monitoramento

- **Logging**: Logs estruturados
- **Métricas**: PerformanceMetricsService
- **Health Check**: Endpoint `/health`
- **Audit Log**: Rastreamento de ações

## 🧪 Testes

- **Pest**: Framework de testes
- **Feature Tests**: Testes de integração
- **Unit Tests**: Testes unitários
- **Coverage**: Code coverage configurado

## 🚀 Deploy

- **Servidor**: Laravel Herd (desenvolvimento)
- **Queue**: Processamento em background
- **Scheduler**: Tarefas agendadas

## 📝 Padrões Utilizados

- **Repository Pattern**: TransactionRepository
- **Service Layer**: Services para lógica de negócio
- **DTOs**: Data Transfer Objects
- **Value Objects**: Money
- **Jobs**: Processamento assíncrono
- **Form Requests**: Validação

## 🔄 Fluxo de Dados

```
Frontend (Livewire/Volt)
    ↕
Controllers
    ↕
Services
    ↕
Repository
    ↕
Models
    ↕
Database
```

## 📚 Dependências Principais

- **Laravel 12**: Framework PHP
- **Livewire 3**: Componentes reativos
- **Volt**: Single-file components
- **Pest**: Testes
- **Maatwebsite Excel**: Exportação
- **DomPDF**: Geração de PDFs
- **Baileys**: WhatsApp client

## 🎯 Próximos Passos

- [ ] Refatorar AIService completamente
- [ ] Adicionar mais testes
- [ ] Melhorar documentação
- [ ] Otimizar queries
- [ ] Adicionar mais funcionalidades
