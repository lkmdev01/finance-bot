<?php

namespace App\Services\WhatsApp;

class ConversationContextResolver
{
    public function recentEntityName(array $state, string $topic, string $field): ?string
    {
        if (($state['last_entities']['topic'] ?? null) === $topic && ! empty($state['last_entities'][$field])) {
            return (string) $state['last_entities'][$field];
        }

        foreach (($state['recent_contexts'] ?? []) as $context) {
            if (($context['entities']['topic'] ?? null) === $topic && ! empty($context['entities'][$field])) {
                return (string) $context['entities'][$field];
            }
        }

        return null;
    }

    public function recentEntityIds(array $state, string $topic, string $field): array
    {
        if (($state['last_entities']['topic'] ?? null) === $topic && ! empty($state['last_entities'][$field]) && is_array($state['last_entities'][$field])) {
            return array_values($state['last_entities'][$field]);
        }

        foreach (($state['recent_contexts'] ?? []) as $context) {
            if (($context['entities']['topic'] ?? null) === $topic && ! empty($context['entities'][$field]) && is_array($context['entities'][$field])) {
                return array_values($context['entities'][$field]);
            }
        }

        return [];
    }

    public function recentBudgetPeriod(array $state): array
    {
        $entities = ($state['last_entities']['topic'] ?? null) === 'budget'
            ? ($state['last_entities'] ?? [])
            : [];

        if ($entities === []) {
            foreach (($state['recent_contexts'] ?? []) as $context) {
                $candidate = $context['entities'] ?? [];
                if (($candidate['topic'] ?? null) === 'budget') {
                    $entities = $candidate;
                    break;
                }
            }
        }

        return [
            'period' => ($entities['month'] ?? null) ? 'monthly' : 'yearly',
            'year' => $entities['year'] ?? now()->year,
            'month' => $entities['month'] ?? now()->month,
        ];
    }
}
