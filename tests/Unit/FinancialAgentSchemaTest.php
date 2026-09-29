<?php

use App\Ai\FinancialAgent;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\ObjectSchema;

it('declares every structured output property as required for strict providers', function () {
    $agent = (new ReflectionClass(FinancialAgent::class))->newInstanceWithoutConstructor();
    $schema = (new ObjectSchema($agent->schema(new JsonSchemaTypeFactory)))->toSchema();

    $assertStrictObject = function (array $node, string $path = '$') use (&$assertStrictObject): void {
        $types = (array) ($node['type'] ?? []);

        if (in_array('object', $types, true)) {
            expect($node['additionalProperties'] ?? null)
                ->toBeFalse("{$path} must disable additional properties");

            $properties = $node['properties'] ?? [];

            if ($properties !== []) {
                expect($node['required'] ?? [])
                    ->toBe(array_keys($properties), "{$path} must require every declared property");
            }

            foreach ($properties as $name => $property) {
                $assertStrictObject($property, "{$path}.{$name}");
            }
        }

        if (isset($node['items']) && is_array($node['items'])) {
            $assertStrictObject($node['items'], "{$path}[]");
        }
    };

    $assertStrictObject($schema);
});

it('exposes the fields required by financial and content handlers', function () {
    $agent = (new ReflectionClass(FinancialAgent::class))->newInstanceWithoutConstructor();
    $schema = (new ObjectSchema($agent->schema(new JsonSchemaTypeFactory)))->toSchema();

    $contracts = [
        'transaction_data' => ['type', 'amount', 'description', 'date', 'payment_method', 'bank_account_name', 'credit_card_name', 'transaction_id', 'confirmed'],
        'installment_data' => ['description', 'total_amount', 'installment_count', 'per_installment_amount', 'date', 'category_name', 'bank_account_name', 'credit_card_name'],
        'budget_data' => ['amount', 'category_id', 'category_name', 'period', 'month', 'year', 'confirmed'],
        'goal_data' => ['name', 'target_amount', 'target_date', 'description'],
        'recurring_data' => ['type', 'amount', 'description', 'frequency', 'start_date', 'day_of_month', 'category_name', 'bank_account_name', 'credit_card_name'],
        'subscription_data' => ['name', 'amount', 'billing_cycle', 'due_day', 'start_date', 'bank_account_name', 'credit_card_name'],
        'reminder_data' => ['title', 'current_title', 'message', 'frequency', 'next_trigger_at', 'reminder_id'],
        'note_data' => ['title', 'current_title', 'body', 'note_id'],
        'drive_data' => ['incoming_media_id', 'folder_hint', 'auto_folder_key'],
        'credit_card_data' => ['name', 'credit_limit', 'is_active'],
    ];

    foreach ($contracts as $payload => $fields) {
        $actual = array_keys($schema['properties'][$payload]['properties']);
        expect(array_diff($fields, $actual))->toBe([], "{$payload} is missing handler inputs");
    }
});

it('defines every action payload expected by the sanitizer', function () {
    $agent = (new ReflectionClass(FinancialAgent::class))->newInstanceWithoutConstructor();
    $schema = (new ObjectSchema($agent->schema(new JsonSchemaTypeFactory)))->toSchema();
    $sanitizer = new ReflectionClass(\App\Services\WhatsApp\ActionResultSanitizer::class);

    foreach (array_unique($sanitizer->getConstant('ACTION_PAYLOAD_KEY')) as $payload) {
        expect($schema['properties'])->toHaveKey($payload);
    }
});

it('keeps edit delete and cancel actions aligned with their handler inputs', function (string $action, string $payload, array $fields) {
    $agent = (new ReflectionClass(FinancialAgent::class))->newInstanceWithoutConstructor();
    $schema = (new ObjectSchema($agent->schema(new JsonSchemaTypeFactory)))->toSchema();
    $sanitizer = new ReflectionClass(\App\Services\WhatsApp\ActionResultSanitizer::class);
    $actionPayloads = $sanitizer->getConstant('ACTION_PAYLOAD_KEY');

    expect($actionPayloads[$action] ?? null)->toBe($payload);

    $properties = array_keys($schema['properties'][$payload]['properties']);
    expect(array_diff($fields, $properties))->toBe([], "{$action} is missing handler inputs");
})->with([
    'edit transaction' => ['edit_transaction', 'transaction_data', ['transaction_id', 'target_description', 'reference', 'target_date_scope', 'amount', 'description']],
    'delete transaction' => ['delete_transaction', 'transaction_data', ['transaction_id', 'target_description', 'reference', 'target_date_scope', 'confirmed']],
    'update budget' => ['update_budget', 'budget_data', ['category_name', 'amount', 'period', 'year', 'month']],
    'delete budget' => ['delete_budget', 'budget_data', ['category_name', 'period', 'year', 'month', 'confirmed']],
    'update savings goal' => ['update_savings_goal', 'goal_data', ['name', 'target_amount', 'target_date']],
    'update subscription' => ['update_subscription', 'subscription_data', ['name', 'amount', 'billing_cycle', 'due_day', 'bank_account_name', 'credit_card_name']],
    'cancel subscription' => ['cancel_subscription', 'subscription_data', ['name']],
    'update recurring transaction' => ['update_recurring_transaction', 'recurring_data', ['description', 'amount', 'frequency', 'day_of_month', 'category_name']],
    'cancel recurring transaction' => ['cancel_recurring_transaction', 'recurring_data', ['description']],
    'edit reminder' => ['edit_reminder', 'reminder_data', ['reminder_id', 'current_title', 'title', 'frequency', 'next_trigger_at']],
    'delete reminder' => ['delete_reminder', 'reminder_data', ['reminder_id', 'current_title']],
    'edit note' => ['edit_note', 'note_data', ['note_id', 'current_title', 'body']],
    'delete note' => ['delete_note', 'note_data', ['note_id', 'current_title']],
]);
