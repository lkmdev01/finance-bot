<?php

namespace App\Console\Commands;

use App\Models\AbacatePaySubscription;
use App\Models\Budget;
use App\Models\ProductActivityDay;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WhatsAppConversationLog;
use App\Services\ProductEventService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class BackfillProductFunnelCommand extends Command
{
    protected $signature = 'product:funnel-backfill';

    protected $description = 'Reconstrói os primeiros marcos do funil para usuários existentes';

    public function handle(ProductEventService $events): int
    {
        $processed = 0;

        User::query()->orderBy('id')->chunkById(100, function ($users) use ($events, &$processed) {
            foreach ($users as $user) {
                $events->recordOnce($user, ProductEventService::REGISTERED, 'backfill', occurredAt: $user->created_at);

                if ($user->whatsapp_verified_at) {
                    $events->recordOnce($user, ProductEventService::WHATSAPP_ACTIVATED, 'backfill', occurredAt: $user->whatsapp_verified_at);
                }

                $firstMessageAt = WhatsAppConversationLog::query()
                    ->where('user_id', $user->id)
                    ->oldest('created_at')
                    ->value('created_at');

                if ($firstMessageAt) {
                    $firstMessageAt = Carbon::parse($firstMessageAt);
                    $events->recordOnce($user, ProductEventService::FIRST_WHATSAPP_MESSAGE, 'backfill', occurredAt: $firstMessageAt);

                    $this->recordRetentionEvent($events, $user, ProductEventService::RETAINED_D1, 1);
                    $this->recordRetentionEvent($events, $user, ProductEventService::RETAINED_D7, 7);

                    WhatsAppConversationLog::query()
                        ->where('user_id', $user->id)
                        ->selectRaw('DATE(created_at) as activity_date, count(*) as interactions')
                        ->groupByRaw('DATE(created_at)')
                        ->get()
                        ->each(function ($activity) use ($user) {
                            ProductActivityDay::query()->updateOrCreate(
                                [
                                    'user_id' => $user->id,
                                    'activity_date' => Carbon::parse($activity->activity_date)->startOfDay(),
                                    'source' => 'whatsapp',
                                ],
                                ['interaction_count' => (int) $activity->interactions],
                            );
                        });
                }

                $this->recordFirstModelEvent($events, $user, Transaction::class, ProductEventService::FIRST_TRANSACTION);
                $this->recordFirstModelEvent($events, $user, Budget::class, ProductEventService::FIRST_BUDGET);

                $firstCheckoutAt = AbacatePaySubscription::query()
                    ->where('user_id', $user->id)
                    ->oldest('created_at')
                    ->value('created_at');

                if ($firstCheckoutAt) {
                    $events->recordOnce(
                        $user,
                        ProductEventService::CHECKOUT_STARTED,
                        'backfill',
                        occurredAt: Carbon::parse($firstCheckoutAt),
                    );
                }

                if ($user->hasActivePaidPlan()) {
                    $events->recordOnce(
                        $user,
                        ProductEventService::SUBSCRIPTION_ACTIVATED,
                        'backfill',
                        occurredAt: $user->updated_at,
                    );
                }

                $processed++;
            }
        });

        $this->info("Funil reconstruído para {$processed} usuários.");

        return self::SUCCESS;
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     */
    private function recordFirstModelEvent(
        ProductEventService $events,
        User $user,
        string $model,
        string $eventName,
    ): void {
        $occurredAt = $model::query()
            ->where('user_id', $user->id)
            ->oldest('created_at')
            ->value('created_at');

        if ($occurredAt) {
            $events->recordOnce($user, $eventName, 'backfill', occurredAt: Carbon::parse($occurredAt));
        }
    }

    private function recordRetentionEvent(
        ProductEventService $events,
        User $user,
        string $eventName,
        int $days,
    ): void {
        $returnedAt = WhatsAppConversationLog::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $user->created_at->copy()->addDays($days))
            ->oldest('created_at')
            ->value('created_at');

        if ($returnedAt) {
            $events->recordOnce($user, $eventName, 'backfill', occurredAt: Carbon::parse($returnedAt));
        }
    }
}
