<?php

namespace App\Services;

use App\Models\ProductActivityDay;
use App\Models\ProductEvent;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class ProductFunnelService
{
    public function weeklyActiveUsers(?CarbonInterface $at = null): int
    {
        $at = ($at ?? now())->copy()->endOfDay();

        return ProductActivityDay::query()
            ->whereBetween('activity_date', [
                $at->copy()->subDays(6)->toDateString(),
                $at->toDateString(),
            ])
            ->distinct('user_id')
            ->count('user_id');
    }

    /**
     * @return array<int, array{event: string, label: string, count: int, conversion: float|null, denominator: int|null, basis: string}>
     */
    public function summary(?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $from = ($from ?? now()->subDays(29))->copy()->startOfDay();
        $to = ($to ?? now())->copy()->endOfDay();

        $cohort = User::query()->whereBetween('created_at', [$from, $to]);
        $registered = (clone $cohort)->count();
        $cohortIds = (clone $cohort)->pluck('id');

        $eventCount = fn (string $event, $userIds = null) => ProductEvent::query()
            ->whereIn('user_id', $userIds ?? $cohortIds)
            ->where('event_name', $event)
            ->where('occurred_at', '<=', $to)
            ->count();

        $activationQuery = ProductEvent::query()
            ->join('users', 'users.id', '=', 'product_events.user_id')
            ->whereIn('product_events.user_id', $cohortIds)
            ->where('product_events.event_name', ProductEventService::FIRST_TRANSACTION)
            ->where('product_events.occurred_at', '<=', $to);

        $activated24h = DB::getDriverName() === 'sqlite'
            ? $activationQuery->whereRaw("product_events.occurred_at <= datetime(users.created_at, '+24 hours')")->count()
            : $activationQuery->whereRaw('product_events.occurred_at <= DATE_ADD(users.created_at, INTERVAL 24 HOUR)')->count();

        $eligibleD1Ids = (clone $cohort)->where('created_at', '<=', $to->copy()->subDay())->pluck('id');
        $eligibleD7Ids = (clone $cohort)->where('created_at', '<=', $to->copy()->subDays(7))->pluck('id');
        $eligibleD1 = $eligibleD1Ids->count();
        $eligibleD7 = $eligibleD7Ids->count();
        $secondInteraction = ProductActivityDay::query()
            ->whereIn('user_id', $cohortIds)
            ->whereBetween('activity_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('user_id, sum(interaction_count) as interactions')
            ->groupBy('user_id')
            ->havingRaw('sum(interaction_count) >= 2')
            ->get()
            ->count();

        return [
            $this->metric('registered', 'Cadastros', $registered, null, 'Base da coorte'),
            $this->metric('whatsapp_activated', 'WhatsApp ativado', $eventCount(ProductEventService::WHATSAPP_ACTIVATED), $registered, 'dos cadastros'),
            $this->metric('activated_24h', '1º registro em 24h', $activated24h, $registered, 'dos cadastros'),
            $this->metric('second_interaction', '2ª interação útil', $secondInteraction, $registered, 'dos cadastros'),
            $this->metric('retained_d1', 'Retenção D1', $eventCount(ProductEventService::RETAINED_D1, $eligibleD1Ids), $eligibleD1, 'dos elegíveis'),
            $this->metric('retained_d7', 'Retenção D7', $eventCount(ProductEventService::RETAINED_D7, $eligibleD7Ids), $eligibleD7, 'dos elegíveis'),
            $this->metric('checkout_started', 'Checkout iniciado', $eventCount(ProductEventService::CHECKOUT_STARTED), $registered, 'dos cadastros'),
            $this->metric('subscription_activated', 'Assinatura ativa', $eventCount(ProductEventService::SUBSCRIPTION_ACTIVATED), $registered, 'dos cadastros'),
        ];
    }

    private function metric(string $event, string $label, int $count, ?int $denominator, string $basis): array
    {
        return [
            'event' => $event,
            'label' => $label,
            'count' => $count,
            'conversion' => $denominator === null ? null : ($denominator > 0 ? round($count / $denominator * 100, 1) : 0.0),
            'denominator' => $denominator,
            'basis' => $basis,
        ];
    }
}
