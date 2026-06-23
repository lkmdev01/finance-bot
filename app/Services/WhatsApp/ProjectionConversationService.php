<?php

namespace App\Services\WhatsApp;

use App\Models\User;
use App\Services\FinancialProjectionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class ProjectionConversationService
{
    public function __construct(
        private readonly FinancialProjectionService $projectionService
    ) {}

    public function buildReply(User $user, string $message, array $state = []): array
    {
        $normalized = $this->normalize($message);

        if (($followUpReply = $this->buildFollowUpReply($user, $normalized, $state)) !== null) {
            return $followUpReply;
        }

        $context = $this->buildContext($message, $state);
        $projections = $this->loadProjections($user, $context);

        if ($projections->isEmpty()) {
            return [
                'reply' => app(WhatsAppResponseBuilder::class)->empty('Nao encontrei projecoes suficientes ainda.', ['gerar projecao para os proximos meses', 'mostrar meu saldo atual']),
                'entities' => $this->buildEntities($context),
            ];
        }

        if ($context['target_month'] !== null) {
            return [
                'reply' => $this->buildPointReply($projections, $context),
                'entities' => $this->buildEntities($context, [
                    'projection_count' => $projections->count(),
                    'recent_projection_months' => $projections->pluck('month')->values()->all(),
                ]),
            ];
        }

        return [
            'reply' => $this->buildSummaryReply($projections, $context),
            'entities' => $this->buildEntities($context, [
                'projection_count' => $projections->count(),
                'recent_projection_months' => $projections->pluck('month')->values()->all(),
                'projection_month' => $projections->first()['month'] ?? null,
            ]),
        ];
    }

    private function buildContext(string $message, array $state): array
    {
        $normalized = $this->normalize($message);
        $lastEntities = $this->resolveRelevantEntities($state);
        $targetMonth = $this->resolveTargetMonth($normalized, $lastEntities);

        return [
            'normalized_message' => $normalized,
            'target_month' => $targetMonth,
        ];
    }

    private function resolveTargetMonth(string $message, array $lastEntities): ?string
    {
        if (preg_match('/daqui a\s+(\d+)\s+mes/u', $message, $matches)) {
            return CarbonImmutable::now()->addMonths((int) $matches[1])->format('Y-m');
        }

        if ($this->containsAny($message, ['proximo mes', 'proximo m', 'mes que vem'])) {
            return CarbonImmutable::now()->addMonth()->format('Y-m');
        }

        if (($lastEntities['topic'] ?? null) === 'projections' && $this->containsAny($message, ['e depois', 'e no seguinte'])) {
            $base = CarbonImmutable::createFromFormat('Y-m', (string) ($lastEntities['projection_month'] ?? CarbonImmutable::now()->format('Y-m')));

            return $base->addMonth()->format('Y-m');
        }

        return null;
    }

    private function loadProjections(User $user, array $context): Collection
    {
        $records = $user->financialProjections()->orderBy('projection_date')->get();

        if ($records->isEmpty()) {
            $this->projectionService->generateProjections($user, 6);
            $records = $user->financialProjections()->orderBy('projection_date')->get();
        }

        $projections = $records->map(fn ($projection) => [
            'date' => $projection->projection_date->format('Y-m-d'),
            'month' => $projection->projection_date->format('Y-m'),
            'label' => $projection->projection_date->locale('pt_BR')->translatedFormat('F/Y'),
            'projected_balance' => (float) $projection->projected_balance,
            'projected_income' => (float) $projection->projected_income,
            'projected_expenses' => (float) $projection->projected_expenses,
        ])
            ->groupBy('month')
            ->map(function (Collection $items) {
                return $items->sortByDesc('date')->first();
            })
            ->values();

        if ($context['target_month'] !== null) {
            return $projections->filter(fn (array $projection) => $projection['month'] === $context['target_month'])->values();
        }

        return $projections->take(6)->values();
    }

    private function buildSummaryReply(Collection $projections, array $context): string
    {
        $items = $projections->take(4)->map(fn (array $projection) => sprintf(
            '%s: saldo R$ %s | entradas R$ %s | saidas R$ %s',
            $projection['label'],
            $this->formatMoney($projection['projected_balance']),
            $this->formatMoney($projection['projected_income']),
            $this->formatMoney($projection['projected_expenses'])
        ))->values()->all();

        $lowest = $projections->sortBy('projected_balance')->first();
        $reply = app(WhatsAppResponseBuilder::class)->list('Suas projecoes financeiras:', $items);

        if (is_array($lowest)) {
            $reply .= sprintf(
                "\n\nPonto mais sensivel: %s, com saldo projetado de R$ %s.",
                $lowest['label'],
                $this->formatMoney($lowest['projected_balance'])
            );
        }

        if (($insight = app(FinancialConversationAdvisor::class)->projectionSummaryInsight($projections)) !== null) {
            $reply .= ' '.$insight;
        }

        $reply .= "\n\n".app(WhatsAppResponseBuilder::class)->next([
            'abrir o proximo mes',
            'olhar daqui a 3 meses',
            'comparar com meu saldo atual',
        ]);

        return $reply;
    }

    private function buildPointReply(Collection $projections, array $context): string
    {
        if ($projections->isEmpty()) {
            return app(WhatsAppResponseBuilder::class)->empty(
                'Nao encontrei essa projecao especifica ainda.',
                ['mostrar os proximos meses', 'gerar nova projecao']
            );
        }

        $projection = $projections->first();
        $reply = app(WhatsAppResponseBuilder::class)->success(
            "Para {$projection['label']}, saldo de R$ ".$this->formatMoney($projection['projected_balance']).'.',
            [
                'Saldo projetado' => 'R$ '.$this->formatMoney($projection['projected_balance']),
                'Entradas' => 'R$ '.$this->formatMoney($projection['projected_income']),
                'Saidas' => 'R$ '.$this->formatMoney($projection['projected_expenses']),
            ]
        );

        if (($insight = app(FinancialConversationAdvisor::class)->projectionPointInsight($projection)) !== null) {
            $reply .= ' '.$insight;
        }

        $reply .= "\n\n".app(WhatsAppResponseBuilder::class)->next([
            'olhar o mes seguinte',
            'comparar com o horizonte atual',
        ]);

        return $reply;
    }

    private function buildEntities(array $context, array $extra = []): array
    {
        return array_filter(array_merge([
            'topic' => 'projections',
            'projection_month' => $context['target_month'],
        ], $extra), fn ($value) => $value !== null && $value !== [] && $value !== '');
    }

    private function buildFollowUpReply(User $user, string $normalizedMessage, array $state): ?array
    {
        $entities = $this->resolveRelevantEntities($state);
        if (($entities['topic'] ?? null) !== 'projections') {
            return null;
        }

        $projection = $this->resolveRecentProjection($user, $entities);
        $count = (int) ($entities['projection_count'] ?? 0);

        if ($this->containsAny($normalizedMessage, ['me mostra essa projecao', 'mostra essa projecao', 'me mostra ela', 'abre essa projecao'])) {
            if ($projection === null) {
                return null;
            }

            return [
                'reply' => $this->buildPointReply(collect([$projection]), ['target_month' => $projection['month']]),
                'entities' => array_filter([
                    'topic' => 'projections',
                    'projection_month' => $projection['month'],
                    'projection_count' => max(1, $count),
                    'recent_projection_months' => $entities['recent_projection_months'] ?? [],
                ], fn ($value) => $value !== null && $value !== [] && $value !== ''),
            ];
        }

        if ($this->containsAny($normalizedMessage, ['so essa', 'so essa projecao', 'tem mais projecoes', 'tem mais projecao'])) {
            if ($this->containsAny($normalizedMessage, ['tem mais projecoes', 'tem mais projecao'])) {
                $reply = $count <= 1
                    ? 'Por enquanto, nao. So encontrei 1 projecao nesse recorte.'
                    : "Sim. Encontrei {$count} projecoes nesse recorte.";
            } else {
                $reply = $count <= 1
                    ? 'Sim. Nesse recorte eu trouxe apenas 1 projecao.'
                    : "Nao. Nesse recorte eu trouxe {$count} projecoes.";
            }

            if ($projection !== null) {
                $reply .= ' A referencia atual esta em '.$projection['label'].'.';
            }

            return [
                'reply' => $reply,
                'entities' => array_filter([
                    'topic' => 'projections',
                    'projection_month' => $projection['month'] ?? ($entities['projection_month'] ?? null),
                    'projection_count' => max(1, $count),
                    'recent_projection_months' => $entities['recent_projection_months'] ?? [],
                ], fn ($value) => $value !== null && $value !== [] && $value !== ''),
            ];
        }

        return null;
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

    private function resolveRelevantEntities(array $state): array
    {
        $lastEntities = $state['last_entities'] ?? [];

        if (($lastEntities['topic'] ?? null) === 'projections') {
            return $lastEntities;
        }

        foreach (($state['recent_contexts'] ?? []) as $context) {
            $entities = $context['entities'] ?? [];

            if (($entities['topic'] ?? null) === 'projections') {
                return $entities;
            }
        }

        return $lastEntities;
    }

    private function resolveRecentProjection(User $user, array $entities): ?array
    {
        $month = collect($entities['recent_projection_months'] ?? [])
            ->filter(fn ($value) => is_string($value) && $value !== '')
            ->first();

        if (! $month && ! empty($entities['projection_month'])) {
            $month = (string) $entities['projection_month'];
        }

        if (! $month) {
            return null;
        }

        return $this->loadProjections($user, ['target_month' => $month])->first();
    }
}
