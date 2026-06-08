<?php

namespace App\Services\WhatsApp;

use App\Models\SavingsGoal;
use App\Models\User;
use Illuminate\Support\Collection;

class SavingsConversationService
{
    public function buildReply(User $user, string $message, array $state = []): array
    {
        $normalized = $this->normalize($message);

        if (($followUpReply = $this->buildFollowUpReply($user, $normalized, $state)) !== null) {
            return $followUpReply;
        }

        $context = $this->buildContext($user, $message, $state);
        $goals = $this->loadGoals($user, $context);

        if ($goals->isEmpty()) {
            return [
                'reply' => $this->buildEmptyReply($context),
                'entities' => $this->buildEntities($context),
            ];
        }

        if (! empty($context['goal_names'])) {
            return [
                'reply' => $this->buildGoalReply($goals, $context),
                'entities' => $this->buildEntities($context, [
                    'goal_name' => $goals->count() === 1 ? $goals->first()->name : null,
                    'goal_names' => $goals->pluck('name')->values()->all(),
                ]),
            ];
        }

        return [
            'reply' => $this->buildSummaryReply($goals, $context),
            'entities' => $this->buildEntities($context, [
                'goal_count' => $goals->count(),
                'recent_goal_ids' => $goals->pluck('id')->values()->all(),
            ]),
        ];
    }

    private function buildContext(User $user, string $message, array $state): array
    {
        $normalized = $this->normalize($message);
        $availableGoals = $this->buildGoalIndex($user);
        $lastEntities = $this->resolveRelevantEntities($state);

        return [
            'normalized_message' => $normalized,
            'goal_names' => $this->resolveGoals($normalized, $availableGoals, $lastEntities),
            'include_completed' => $this->containsAny($normalized, ['concluida', 'concluidas', 'finalizada', 'finalizadas', 'completa', 'completas']),
        ];
    }

    private function buildGoalIndex(User $user): Collection
    {
        return $user->savingsGoals()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (SavingsGoal $goal) => [
                'name' => $goal->name,
                'normalized' => $this->normalize($goal->name),
            ]);
    }

    private function resolveGoals(string $message, Collection $availableGoals, array $lastEntities): array
    {
        $matches = [];

        foreach ($availableGoals as $goal) {
            if ($goal['normalized'] !== '' && str_contains($message, $goal['normalized'])) {
                $matches[] = $goal['name'];
            }
        }

        $matches = array_values(array_unique($matches));

        if ($matches !== []) {
            return $matches;
        }

        if (($lastEntities['topic'] ?? null) === 'savings' && ! empty($lastEntities['goal_name']) && $this->looksLikeGoalFollowUp($message)) {
            return [(string) $lastEntities['goal_name']];
        }

        return [];
    }

    private function loadGoals(User $user, array $context): Collection
    {
        $query = $user->savingsGoals()->with('deposits')->orderBy('target_date')->orderBy('name');

        if (! $context['include_completed']) {
            $query->where('is_completed', false);
        }

        $goals = $query->get();

        if (! empty($context['goal_names'])) {
            $targets = array_map(fn (string $name) => $this->normalize($name), $context['goal_names']);
            $goals = $goals->filter(fn (SavingsGoal $goal) => in_array($this->normalize($goal->name), $targets, true))->values();
        }

        return $goals->values();
    }

    private function buildEmptyReply(array $context): string
    {
        if (! empty($context['goal_names'])) {
            return 'Nao encontrei essa meta por aqui. Se quiser, eu posso listar suas metas ativas ou voce pode me dizer o nome exato.';
        }

        return 'Voce ainda nao tem metas ativas para eu acompanhar. Se quiser, posso te ajudar a criar uma meta nova.';
    }

    private function buildSummaryReply(Collection $goals, array $context): string
    {
        $lines = $goals->take(5)->map(function (SavingsGoal $goal) {
            $targetDate = $goal->target_date?->format('d/m/Y') ?? 'sem data';

            return sprintf(
                '- %s: R$ %s de R$ %s (%s) | falta R$ %s | alvo %s',
                $goal->name,
                $this->formatMoney($goal->current_amount),
                $this->formatMoney($goal->target_amount),
                $this->formatPercentage((float) $goal->progress_percentage),
                $this->formatMoney($goal->remaining_amount),
                $targetDate
            );
        })->implode("\n");

        $closest = $goals->sortByDesc(fn (SavingsGoal $goal) => $goal->progress_percentage)->first();
        $advisor = app(FinancialConversationAdvisor::class);
        $reply = "Suas metas atuais:\n{$lines}";

        if ($closest instanceof SavingsGoal && (float) $closest->progress_percentage > 0) {
            $reply .= sprintf(
                "\n\nHoje, %s e a meta mais avancada, com %s de progresso.",
                $closest->name,
                $this->formatPercentage((float) $closest->progress_percentage)
            );
        }

        if (($insight = $advisor->savingsSummaryInsight($goals)) !== null) {
            $reply .= ' '.$insight;
        }

        $reply .= ' Se quiser, eu posso abrir uma meta especifica, comparar o andamento ou olhar o que falta para concluir.';

        return $reply;
    }

    private function buildGoalReply(Collection $goals, array $context): string
    {
        if ($goals->count() > 1) {
            $lines = $goals->map(fn (SavingsGoal $goal) => sprintf(
                '- %s: R$ %s de R$ %s (%s)',
                $goal->name,
                $this->formatMoney($goal->current_amount),
                $this->formatMoney($goal->target_amount),
                $this->formatPercentage((float) $goal->progress_percentage)
            ))->implode("\n");

            return "Encontrei estas metas:\n{$lines}\n\nSe quiser, eu posso detalhar uma delas ou comparar qual esta mais perto do objetivo.";
        }

        /** @var SavingsGoal $goal */
        $goal = $goals->first();
        $advisor = app(FinancialConversationAdvisor::class);
        $targetDate = $goal->target_date?->format('d/m/Y') ?? 'sem data definida';

        $reply = sprintf(
            '%s esta com R$ %s de R$ %s, o que representa %s de progresso. Ainda faltam R$ %s e a data alvo e %s.',
            $goal->name,
            $this->formatMoney($goal->current_amount),
            $this->formatMoney($goal->target_amount),
            $this->formatPercentage((float) $goal->progress_percentage),
            $this->formatMoney($goal->remaining_amount),
            $targetDate
        );

        if (($insight = $advisor->savingsGoalInsight($goal)) !== null) {
            $reply .= ' '.$insight;
        }

        $reply .= ' Se quiser, eu posso te mostrar quanto falta, comparar com outra meta ou sugerir quanto precisaria guardar por mes.';

        return $reply;
    }

    private function buildEntities(array $context, array $extra = []): array
    {
        return array_filter(array_merge([
            'topic' => 'savings',
            'goal_name' => count($context['goal_names']) === 1 ? $context['goal_names'][0] : null,
            'goal_names' => count($context['goal_names']) > 1 ? $context['goal_names'] : null,
        ], $extra), fn ($value) => $value !== null && $value !== [] && $value !== '');
    }

    private function buildFollowUpReply(User $user, string $normalizedMessage, array $state): ?array
    {
        $entities = $this->resolveRelevantEntities($state);
        if (($entities['topic'] ?? null) !== 'savings') {
            return null;
        }

        $goal = $this->resolveRecentGoal($user, $entities);
        $count = (int) ($entities['goal_count'] ?? 0);

        if ($this->containsAny($normalizedMessage, ['me mostra essa meta', 'me mostra ela', 'mostra essa meta', 'abre essa meta'])) {
            if (! $goal) {
                return null;
            }

            return [
                'reply' => $this->buildGoalReply(collect([$goal]), ['goal_names' => [$goal->name]]),
                'entities' => array_filter([
                    'topic' => 'savings',
                    'goal_name' => $goal->name,
                    'goal_count' => max(1, $count),
                    'recent_goal_ids' => $entities['recent_goal_ids'] ?? [],
                ], fn ($value) => $value !== null && $value !== [] && $value !== ''),
            ];
        }

        if ($this->containsAny($normalizedMessage, ['so essa', 'só essa', 'apenas essa'])) {
            $reply = $count <= 1
                ? 'Por enquanto, sim. '
                : "Nao. Encontrei {$count} metas nesse filtro. ";
            $reply .= 'Essa e a meta mais relevante da lista atual.';

            return [
                'reply' => trim($reply),
                'entities' => array_filter([
                    'topic' => 'savings',
                    'goal_name' => $goal?->name,
                    'goal_count' => max(1, $count),
                    'recent_goal_ids' => $entities['recent_goal_ids'] ?? [],
                ], fn ($value) => $value !== null && $value !== [] && $value !== ''),
            ];
        }

        if ($this->containsAny($normalizedMessage, ['tem mais meta', 'tem mais metas'])) {
            $reply = $count > 1
                ? "Sim. Eu encontrei {$count} metas nesse filtro."
                : 'Por enquanto, nao. So encontrei essa meta nesse filtro.';

            return [
                'reply' => $reply,
                'entities' => array_filter([
                    'topic' => 'savings',
                    'goal_name' => $goal?->name,
                    'goal_count' => max(1, $count),
                    'recent_goal_ids' => $entities['recent_goal_ids'] ?? [],
                ], fn ($value) => $value !== null && $value !== [] && $value !== ''),
            ];
        }

        return null;
    }

    private function looksLikeGoalFollowUp(string $message): bool
    {
        if (! preg_match('/^(?:e\s+)?(?:a|o|as|os)?\s*([\p{L}\p{N} _-]+)$/u', $message, $matches)) {
            return false;
        }

        $term = trim($matches[1] ?? '');
        if ($term === '') {
            return false;
        }

        $wordCount = count(array_filter(explode(' ', $term)));

        return $wordCount <= 4 && ! $this->containsAny($term, ['saldo', 'orcamento', 'projecao', 'assinatura']);
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $this->normalize($needle))) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return $converted !== false ? $converted : $value;
    }

    private function formatMoney(float|string $value): string
    {
        return number_format((float) $value, 2, ',', '.');
    }

    private function formatPercentage(float $value): string
    {
        return number_format($value, 0, ',', '.').'%';
    }

    private function resolveRelevantEntities(array $state): array
    {
        $lastEntities = $state['last_entities'] ?? [];

        if (($lastEntities['topic'] ?? null) === 'savings') {
            return $lastEntities;
        }

        foreach (($state['recent_contexts'] ?? []) as $context) {
            $entities = $context['entities'] ?? [];

            if (($entities['topic'] ?? null) === 'savings') {
                return $entities;
            }
        }

        return $lastEntities;
    }

    private function resolveRecentGoal(User $user, array $entities): ?SavingsGoal
    {
        $name = $entities['goal_name'] ?? null;
        if (is_string($name) && $name !== '') {
            return $user->savingsGoals()->where('name', $name)->first();
        }

        $recentIds = array_values(array_filter($entities['recent_goal_ids'] ?? [], fn ($id) => (int) $id > 0));
        if ($recentIds === []) {
            return null;
        }

        return $user->savingsGoals()->find((int) $recentIds[0]);
    }
}
