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
