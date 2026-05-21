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
        $context = $this->buildContext($message, $state);
        $projections = $this->loadProjections($user, $context);

        if ($projections->isEmpty()) {
            return [
                'reply' => 'Nao encontrei projecoes suficientes ainda. Se quiser, posso gerar uma nova previsao para os proximos meses.',
                'entities' => $this->buildEntities($context),
            ];
        }

        if ($context['target_month'] !== null) {
            return [
                'reply' => $this->buildPointReply($projections, $context),
                'entities' => $this->buildEntities($context),
            ];
        }

        return [
            'reply' => $this->buildSummaryReply($projections, $context),
            'entities' => $this->buildEntities($context, [
                'projection_count' => $projections->count(),
            ]),
        ];
    }

    private function buildContext(string $message, array $state): array
    {
        $normalized = $this->normalize($message);
        $lastEntities = $state['last_entities'] ?? [];
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
        $lines = $projections->take(4)->map(fn (array $projection) => sprintf(
            '- %s: saldo R$ %s | entradas R$ %s | saidas R$ %s',
            $projection['label'],
            $this->formatMoney($projection['projected_balance']),
            $this->formatMoney($projection['projected_income']),
            $this->formatMoney($projection['projected_expenses'])
        ))->implode("\n");

        $lowest = $projections->sortBy('projected_balance')->first();
        $reply = "Suas projecoes financeiras:\n{$lines}";

        if (is_array($lowest)) {
            $reply .= sprintf(
                "\n\nO ponto mais sensivel aparece em %s, com saldo projetado de R$ %s.",
                $lowest['label'],
                $this->formatMoney($lowest['projected_balance'])
            );
        }

        if (($insight = app(FinancialConversationAdvisor::class)->projectionSummaryInsight($projections)) !== null) {
            $reply .= ' '.$insight;
        }

        $reply .= ' Se quiser, eu posso abrir um mes especifico, olhar daqui a 3 meses ou comparar com seu saldo atual.';

        return $reply;
    }

    private function buildPointReply(Collection $projections, array $context): string
    {
        if ($projections->isEmpty()) {
            return 'Nao encontrei essa projeção especifica ainda. Se quiser, posso te mostrar os proximos meses.';
        }

        $projection = $projections->first();
        $reply = sprintf(
            'Para %s, a projecao indica saldo de R$ %s, com entradas de R$ %s e saidas de R$ %s.',
            $projection['label'],
            $this->formatMoney($projection['projected_balance']),
            $this->formatMoney($projection['projected_income']),
            $this->formatMoney($projection['projected_expenses'])
        );

        if (($insight = app(FinancialConversationAdvisor::class)->projectionPointInsight($projection)) !== null) {
            $reply .= ' '.$insight;
        }

        $reply .= ' Se quiser, eu posso olhar o mes seguinte ou comparar com o horizonte atual.';

        return $reply;
    }

    private function buildEntities(array $context, array $extra = []): array
    {
        return array_filter(array_merge([
            'topic' => 'projections',
            'projection_month' => $context['target_month'],
        ], $extra), fn ($value) => $value !== null && $value !== [] && $value !== '');
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
}
