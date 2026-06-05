<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class AIResponseParser
{
    /**
     * Parseia a resposta da IA
     */
    public function parse(string $response): array
    {
        // Procura pelo primeiro '{' e o último '}' para extrair o objeto JSON
        $firstBrace = strpos($response, '{');
        $lastBrace = strrpos($response, '}');

        if ($firstBrace !== false && $lastBrace !== false) {
            $potentialJson = substr($response, $firstBrace, $lastBrace - $firstBrace + 1);

            // Remove quebras de linha reais que invalidam o JSON de strings da IA
            $sanitizedJson = preg_replace('/(?<!\\\\)\R/u', '\n', $potentialJson);
            $json = json_decode($sanitizedJson, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                // ... (existing nested JSON logic)
                if (isset($json['reply']) && is_string($json['reply']) && str_starts_with(trim($json['reply']), '{')) {
                    $innerJson = json_decode($json['reply'], true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        return $this->normalizeStructuredContract(array_merge($json, $innerJson));
                    }
                }
                // ...
                return $this->normalizeStructuredContract($json);
            }

            // --- NOVO: Fallback via Regex se o JSON for malformado ---
            Log::debug('JSON malformado detectado, tentando extração via regex', ['response' => $potentialJson]);
            
            $extracted = [];
            
            // Tenta extrair o 'reply' usando um regex que busca o valor entre aspas
            if (preg_match('/"reply"\s*:\s*"(.*?)(?:"\s*,\s*"action"|"\s*,\s*"transaction_data"|"\s*})/s', $potentialJson, $matches)) {
                $extracted['reply'] = $matches[1];
            } elseif (preg_match('/"reply"\s*:\s*"(.*)/s', $potentialJson, $matches)) {
                // Fallback extremo: pega tudo após "reply":" até o fim ou próxima chave
                $val = rtrim($matches[1], ' \t\n\r\0\x0B}');
                $extracted['reply'] = rtrim($val, '", ');
            }

            if (preg_match('/"action"\s*:\s*(?:"(.*?)"|null)/', $potentialJson, $matches)) {
                $extracted['action'] = $matches[1] ?? null;
            }

            if (!empty($extracted['reply'])) {
                // Tira escapes de barra pra exibir texto limpo
                $extracted['reply'] = stripslashes($extracted['reply']);
                return $this->normalizeStructuredContract(array_merge([
                    'action' => null,
                    'transaction_data' => null,
                ], $extracted));
            }
        }

        // Tenta remover escapes e parsear novamente (caso venha encasulado)
        $unquoted = stripslashes($response);
        if ($unquoted !== $response) {
            return $this->parse($unquoted);
        }

        // Se não conseguir parsear, retorna apenas a resposta
        return [
            'reply' => $response,
            'action' => null,
            'transaction_data' => null,
        ];
    }

    private function normalizeStructuredContract(array $payload): array
    {
        $intent = $payload['intent'] ?? null;
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        if (! isset($payload['reply']) && isset($payload['user_friendly_summary']) && is_string($payload['user_friendly_summary'])) {
            $payload['reply'] = $payload['user_friendly_summary'];
        }

        if (! isset($payload['missing_fields'])) {
            $payload['missing_fields'] = [];
        }

        if (! isset($payload['needs_confirmation'])) {
            $payload['needs_confirmation'] = false;
        }

        if (! isset($payload['confidence'])) {
            $payload['confidence'] = null;
        }

        if (! isset($payload['action']) && is_string($intent)) {
            $payload['action'] = $this->mapIntentToLegacyAction($intent);
        }

        if (($payload['action'] ?? null) === 'create_transaction' && ! isset($payload['transaction_data']) && $data !== []) {
            $payload['transaction_data'] = $data;
        }

        if (($payload['action'] ?? null) === 'create_budget' && ! isset($payload['budget_data']) && $data !== []) {
            $payload['budget_data'] = $data;
        }

        if (in_array(($payload['action'] ?? null), ['create_savings_goal', 'update_savings_goal'], true) && ! isset($payload['goal_data']) && $data !== []) {
            $payload['goal_data'] = $data;
        }

        if (in_array(($payload['action'] ?? null), ['create_subscription', 'update_subscription', 'cancel_subscription'], true) && ! isset($payload['subscription_data']) && $data !== []) {
            $payload['subscription_data'] = $data;
        }

        if (in_array(($payload['action'] ?? null), ['create_recurring_transaction', 'update_recurring_transaction', 'cancel_recurring_transaction'], true) && ! isset($payload['recurring_data']) && $data !== []) {
            $payload['recurring_data'] = $data;
        }

        if (in_array(($payload['action'] ?? null), ['create_note', 'query_notes'], true) && ! isset($payload['note_data']) && $data !== []) {
            $payload['note_data'] = $data;
        }

        if (($payload['action'] ?? null) === 'create_reminder' && ! isset($payload['reminder_data']) && $data !== []) {
            $payload['reminder_data'] = $data;
        }

        if (in_array(($payload['action'] ?? null), ['create_drive_file', 'query_drive_files'], true) && ! isset($payload['drive_data']) && $data !== []) {
            $payload['drive_data'] = $data;
        }

        return $payload;
    }

    private function mapIntentToLegacyAction(string $intent): ?string
    {
        return match ($intent) {
            'create_expense', 'create_income' => 'create_transaction',
            'query_balance' => 'query_balance',
            'query_category_spending' => 'query_category',
            'query_month_report' => 'query_expenses',
            'list_transactions' => 'query_transactions',
            'create_budget' => 'create_budget',
            'query_budgets' => 'query_budgets',
            'create_savings_goal' => 'create_savings_goal',
            'query_savings' => 'query_savings',
            'update_savings_goal' => 'update_savings_goal',
            'create_subscription' => 'create_subscription',
            'query_subscriptions' => 'query_subscriptions',
            'update_subscription' => 'update_subscription',
            'cancel_subscription' => 'cancel_subscription',
            'create_recurring_transaction' => 'create_recurring_transaction',
            'update_recurring_transaction' => 'update_recurring_transaction',
            'cancel_recurring_transaction' => 'cancel_recurring_transaction',
            'create_note' => 'create_note',
            'query_notes' => 'query_notes',
            'create_reminder' => 'create_reminder',
            'query_reminders' => 'query_reminders',
            'create_drive_file' => 'create_drive_file',
            'query_drive_files' => 'query_drive_files',
            'update_transaction' => 'edit_transaction',
            'delete_transaction' => 'delete_transaction',
            'help' => null,
            default => null,
        };
    }
}
