<?php

namespace Tests\Support;

use App\Ai\FinancialAgent;
use Laravel\Ai\Ai;

class SimulationSuiteAgentFake
{
    public static function install(): void
    {
        config(['ai.use_sdk' => true]);

        Ai::fakeAgent(FinancialAgent::class, fn (string $prompt): array => self::response($prompt))
            ->preventStrayPrompts();
    }

    /**
     * Deterministic agent responses keep the local simulation suite offline.
     * The suite validates handlers, state and replies—not a provider's availability.
     *
     * @return array<string, mixed>
     */
    private static function response(string $prompt): array
    {
        $message = mb_strtolower(trim($prompt));

        if (str_contains($message, 'gastei 50 no mercado')) {
            return self::action('create_transaction', 'transaction_data', [
                'type' => 'expense', 'amount' => 50, 'description' => 'Mercado', 'category_name' => 'Compras', 'date' => now()->toDateString(),
            ]);
        }

        if (str_contains($message, 'recebi 500 do cliente')) {
            return self::action('create_transaction', 'transaction_data', [
                'type' => 'income', 'amount' => 500, 'description' => 'Cliente Joao', 'category_name' => 'Salario', 'date' => now()->toDateString(),
            ]);
        }

        if (str_contains($message, 'qual e meu saldo')) {
            return self::action('query_balance');
        }

        if (str_contains($message, 'quanto gastei esse mes')) {
            return self::action('query_expenses');
        }

        if (str_contains($message, 'salvar nota:')) {
            return self::action('create_note', 'note_data', [
                'title' => 'Revisar contrato', 'body' => 'Revisar contrato com fornecedor', 'source' => 'whatsapp',
            ]);
        }

        if (str_contains($message, 'me lembra de pagar a internet')) {
            return self::action('create_reminder', 'reminder_data', [
                'title' => 'Pagar internet',
                'message' => 'Pagar a internet',
                'frequency' => 'once',
                'next_trigger_at' => now()->addDay()->setTime(9, 0)->toDateTimeString(),
            ]);
        }

        if ($message === 'criar meta viagem') {
            return [
                'action' => null,
                'reply' => 'Qual valor voce quer guardar?',
                'conversation_metadata' => [
                    'pending_intent' => 'create_savings_goal_details',
                    'pending_payload' => ['goal_data' => ['name' => 'Viagem']],
                    'clear_pending' => false,
                ],
            ];
        }

        if (str_contains($message, '5000 ate dezembro')) {
            return self::action('create_savings_goal', 'goal_data', [
                'name' => 'Viagem', 'target_amount' => 5000, 'target_date' => '2026-12-31',
            ]);
        }

        if ($message === 'criar assinatura netflix mensal') {
            return [
                'action' => null,
                'reply' => 'Qual valor e vencimento devo usar?',
                'conversation_metadata' => [
                    'pending_intent' => 'create_subscription_details',
                    'pending_payload' => ['subscription_data' => ['name' => 'Netflix', 'billing_cycle' => 'monthly']],
                    'clear_pending' => false,
                ],
            ];
        }

        if ($message === '39,90 dia 10') {
            return self::action('create_subscription', 'subscription_data', [
                'name' => 'Netflix', 'amount' => 39.90, 'billing_cycle' => 'monthly', 'due_day' => 10,
            ]);
        }

        if ($message === 'ajusta para 28') {
            return self::action('edit_transaction', 'transaction_data', ['amount' => 28]);
        }

        if (str_contains($message, 'ajusta esse no cartao nubank')) {
            return self::action('edit_transaction', 'transaction_data', ['credit_card_name' => 'Nubank']);
        }

        if (str_contains($message, 'cancela esse orcamento')) {
            return self::action('delete_budget', 'budget_data', [
                'category_name' => 'Compras', 'period' => 'monthly', 'month' => now()->month, 'year' => now()->year, 'confirmed' => false,
            ]);
        }

        if ($message === 'sim') {
            return self::action('delete_budget', 'budget_data', [
                'category_name' => 'Compras', 'period' => 'monthly', 'month' => now()->month, 'year' => now()->year, 'confirmed' => true,
            ]);
        }

        if ($message === 'cancela a recorrencia') {
            return [
                'action' => 'cancel_recurring_transaction',
                'reply' => '',
                'recurring_data' => [],
                'conversation_metadata' => [
                    'pending_intent' => 'cancel_recurring_transaction_target',
                    'pending_payload' => [],
                    'clear_pending' => false,
                ],
            ];
        }

        if ($message === 'de aluguel') {
            return self::action('cancel_recurring_transaction', 'recurring_data', ['description' => 'Aluguel']);
        }

        if (str_contains($message, 'nota')) {
            return self::action('query_notes');
        }

        if (str_contains($message, 'lembrete')) {
            return self::action('query_reminders');
        }

        if (str_contains($message, 'assinatura')) {
            return self::action('query_subscriptions');
        }

        if (str_contains($message, 'recorrencia')) {
            return self::action('query_recurring_transactions');
        }

        if (str_contains($message, 'meta')) {
            return self::action('query_savings');
        }

        if (self::containsAny($message, ['arquivo', 'drive', 'pasta', 'foto', 'comprovante', 'audio', 'so essa'])) {
            return self::action('query_drive_files');
        }

        return ['action' => null, 'reply' => 'Entendi.'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function action(?string $action, ?string $payloadKey = null, array $payload = []): array
    {
        $response = ['action' => $action, 'reply' => ''];

        if ($payloadKey !== null) {
            $response[$payloadKey] = $payload;
        }

        return $response;
    }

    /**
     * @param  array<int, string>  $needles
     */
    private static function containsAny(string $message, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
