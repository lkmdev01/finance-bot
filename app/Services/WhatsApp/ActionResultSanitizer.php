<?php

namespace App\Services\WhatsApp;

final class ActionResultSanitizer
{
    /**
     * Map action -> expected payload key.
     * Queries do not require a payload.
     */
    private const ACTION_PAYLOAD_KEY = [
        'create_transaction' => 'transaction_data',
        'confirm_large_transaction' => 'transaction_data',
        'edit_transaction' => 'transaction_data',
        'delete_transaction' => 'transaction_data',
        'split_transaction' => 'transaction_data',

        'create_installment_transaction' => 'installment_data',
        'create_recurring_transaction' => 'recurring_data',
        'update_recurring_transaction' => 'recurring_data',
        'cancel_recurring_transaction' => 'recurring_data',

        'create_budget' => 'budget_data',
        'update_budget' => 'budget_data',
        'delete_budget' => 'budget_data',

        'create_savings_goal' => 'goal_data',
        'update_savings_goal' => 'goal_data',

        'create_subscription' => 'subscription_data',
        'update_subscription' => 'subscription_data',
        'cancel_subscription' => 'subscription_data',

        'create_credit_card' => 'credit_card_data',

        'create_reminder' => 'reminder_data',
        'edit_reminder' => 'reminder_data',
        'delete_reminder' => 'reminder_data',

        'create_note' => 'note_data',
        'edit_note' => 'note_data',
        'delete_note' => 'note_data',
    ];

    private const KNOWN_PAYLOAD_KEYS = [
        'transaction_data',
        'budget_data',
        'goal_data',
        'subscription_data',
        'credit_card_data',
        'reminder_data',
        'note_data',
        'recurring_data',
        'installment_data',
    ];

    /**
     * @return array{0: array, 1: array} [sanitizedResult, meta]
     */
    public function sanitize(array $result): array
    {
        $action = $result['action'] ?? null;
        if (! is_string($action) || $action === '') {
            return [$result, ['payload_key' => null, 'dropped_payload_keys' => []]];
        }

        if (str_starts_with($action, 'query_')) {
            // Queries must not carry action payload to avoid leaking stale context.
            $dropped = [];
            foreach (self::KNOWN_PAYLOAD_KEYS as $key) {
                if (array_key_exists($key, $result) && $result[$key] !== null) {
                    $dropped[] = $key;
                }
                unset($result[$key]);
            }

            return [$result, ['payload_key' => null, 'dropped_payload_keys' => $dropped]];
        }

        $payloadKey = self::ACTION_PAYLOAD_KEY[$action] ?? null;
        if ($payloadKey === null) {
            return [$result, ['payload_key' => null, 'dropped_payload_keys' => []]];
        }

        // Ensure expected payload is an array.
        if (! isset($result[$payloadKey]) || ! is_array($result[$payloadKey])) {
            $result[$payloadKey] = is_array($result[$payloadKey] ?? null) ? $result[$payloadKey] : [];
        }

        $dropped = [];
        foreach (self::KNOWN_PAYLOAD_KEYS as $key) {
            if ($key === $payloadKey) {
                continue;
            }

            if (array_key_exists($key, $result) && $result[$key] !== null) {
                $dropped[] = $key;
            }
            unset($result[$key]);
        }

        return [$result, ['payload_key' => $payloadKey, 'dropped_payload_keys' => $dropped]];
    }
}
