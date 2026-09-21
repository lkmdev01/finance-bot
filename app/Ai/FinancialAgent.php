<?php

namespace App\Ai;

use App\Models\User;
use App\Models\WhatsAppContact;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

#[Provider(Lab::Groq)]
class FinancialAgent implements Agent, Conversational, HasStructuredOutput
{
    use Promptable, RemembersConversations;

    public function __construct(
        private readonly User $user,
        private readonly WhatsAppContact $contact,
        private readonly AgentContextInjector $contextInjector
    ) {}

    public function instructions(): Stringable|string
    {
        $context = $this->contextInjector->getContextString($this->user, $this->contact);

        return <<<TEXT
Você é InovaFinance, um assistente financeiro humanizado e inteligente.
Sua missão é classificar a intenção do usuário e extrair os dados estruturados da mensagem, além de gerar uma resposta amigável.

$context

Regras de Resposta:
- Sempre forneça uma resposta amigável no campo 'reply'.
- Não use formatação markdown complexa no 'reply', apenas negrito (*texto*) e itálico (_texto_) suportados pelo WhatsApp.
- Use somente ações aceitas pelos handlers: create_transaction, confirm_large_transaction, create_installment_transaction, split_transaction, edit_transaction, delete_transaction, create_budget, update_budget, delete_budget, create_savings_goal, update_savings_goal, create_subscription, update_subscription, cancel_subscription, create_recurring_transaction, update_recurring_transaction, cancel_recurring_transaction, create_reminder, edit_reminder, delete_reminder, create_note, edit_note, delete_note, create_drive_file, create_credit_card, undo_last_action, query_balance, query_expenses, query_income, query_transactions, query_category, query_savings, query_budgets, query_evolution, query_projections, query_subscriptions, query_recurring_transactions, query_credit_cards, query_reminders, query_notes, query_drive_files, query_income_source, query_categories, query_report, query_report_pdf, query_report_csv ou query_report_excel.
- Preencha o payload correspondente quando a ação exigir dados: transaction_data, installment_data, budget_data, goal_data, subscription_data, recurring_data, reminder_data, note_data, drive_data ou credit_card_data.
- Se não conseguir identificar a ação ou for uma pergunta genérica, use 'action' null e responda em 'reply'.
TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            // Groq/OpenAI strict structured outputs require every property declared
            // in an object to also be listed in that object's `required` array.
            // Optional values therefore remain required at the JSON level and use
            // `null` to represent absence.
            'action' => $schema->string()->nullable()->required()->description('Ação aceita pelos handlers, ou null para conversa genérica.'),
            'reply' => $schema->string()->required()->description('A resposta sugerida que será enviada ao usuário.'),

            'transaction_data' => $schema->object([
                'type' => $schema->string()->nullable()->required()->description('expense ou income'),
                'amount' => $schema->number()->nullable()->required()->description('Valor da transação, ex: 50.00'),
                'description' => $schema->string()->nullable()->required()->description('Descrição, ex: Uber, Mercado'),
                'category_id' => $schema->integer()->nullable()->required()->description('ID da categoria correspondente'),
                'category_name' => $schema->string()->nullable()->required()->description('Nome da categoria se não souber o ID'),
                'is_installment' => $schema->boolean()->nullable()->required(),
                'installments_count' => $schema->integer()->nullable()->required(),
                'credit_card_id' => $schema->integer()->nullable()->required(),
            ])->nullable()->required()->description('Preencher se action for create_transaction'),

            'budget_data' => $schema->object([
                'amount' => $schema->number()->nullable()->required(),
                'category_id' => $schema->integer()->nullable()->required(),
                'category_name' => $schema->string()->nullable()->required(),
                'period' => $schema->string()->nullable()->required()->description('monthly ou yearly'),
                'month' => $schema->integer()->nullable()->required(),
                'year' => $schema->integer()->nullable()->required(),
            ])->nullable()->required()->description('Preencher se action for create_budget'),

            'goal_data' => $schema->object([
                'name' => $schema->string()->nullable()->required(),
                'target_amount' => $schema->number()->nullable()->required(),
                'target_date' => $schema->string()->nullable()->required()->description('Data limite no formato YYYY-MM-DD'),
                'description' => $schema->string()->nullable()->required(),
            ])->nullable()->required()->description('Preencher se action for create_savings_goal'),

            'subscription_data' => $schema->object([
                'name' => $schema->string()->nullable()->required(),
                'amount' => $schema->number()->nullable()->required(),
                'billing_cycle' => $schema->string()->nullable()->required()->description('monthly ou yearly'),
                'due_day' => $schema->integer()->nullable()->required()->description('Dia do vencimento entre 1 e 31'),
                'frequency' => $schema->string()->nullable()->required()->description('monthly ou yearly'),
                'bank_account_name' => $schema->string()->nullable()->required(),
                'credit_card_name' => $schema->string()->nullable()->required(),
            ])->nullable()->required()->description('Preencher se action for create_subscription'),

            'installment_data' => $schema->object([
                'type' => $schema->string()->nullable()->required(),
                'amount' => $schema->number()->nullable()->required(),
                'description' => $schema->string()->nullable()->required(),
                'installments_count' => $schema->integer()->nullable()->required(),
                'category_name' => $schema->string()->nullable()->required(),
            ])->nullable()->required(),

            'recurring_data' => $schema->object([
                'type' => $schema->string()->nullable()->required(),
                'amount' => $schema->number()->nullable()->required(),
                'description' => $schema->string()->nullable()->required(),
                'frequency' => $schema->string()->nullable()->required(),
                'day_of_month' => $schema->integer()->nullable()->required(),
                'day_of_week' => $schema->integer()->nullable()->required(),
                'category_name' => $schema->string()->nullable()->required(),
            ])->nullable()->required(),

            'reminder_data' => $schema->object([
                'title' => $schema->string()->nullable()->required(),
                'description' => $schema->string()->nullable()->required(),
                'schedule' => $schema->string()->nullable()->required(),
                'frequency' => $schema->string()->nullable()->required(),
                'day_of_month' => $schema->integer()->nullable()->required(),
                'day_of_week' => $schema->integer()->nullable()->required(),
                'month_of_year' => $schema->integer()->nullable()->required(),
            ])->nullable()->required(),

            'note_data' => $schema->object([
                'title' => $schema->string()->nullable()->required(),
                'content' => $schema->string()->nullable()->required(),
                'target' => $schema->string()->nullable()->required(),
            ])->nullable()->required(),

            'drive_data' => $schema->object([
                'folder' => $schema->string()->nullable()->required(),
                'file_name' => $schema->string()->nullable()->required(),
                'description' => $schema->string()->nullable()->required(),
            ])->nullable()->required(),

            'credit_card_data' => $schema->object([
                'name' => $schema->string()->nullable()->required(),
                'credit_limit' => $schema->number()->nullable()->required(),
                'is_active' => $schema->boolean()->nullable()->required(),
            ])->nullable()->required(),

            'conversation_metadata' => $schema->object([
                'reply_kind' => $schema->string()->nullable()->required(),
                'pending_intent' => $schema->string()->nullable()->required(),
                'pending_payload' => $schema->object([])->nullable()->required(),
                'clear_pending' => $schema->boolean()->nullable()->required(),
                'entities' => $schema->object([])->nullable()->required(),
            ])->nullable()->required()->description('Metadados de estado para esclarecimentos e confirmacoes.'),
        ];
    }
}
