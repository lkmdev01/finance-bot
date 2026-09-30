<?php

namespace App\Services;

use App\Models\ProductActivityDay;
use App\Models\ProductEvent;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;

class ProductEventService
{
    public const REGISTERED = 'registered';

    public const WHATSAPP_ACTIVATED = 'whatsapp_activated';

    public const FIRST_WHATSAPP_MESSAGE = 'first_whatsapp_message';

    public const FIRST_TRANSACTION = 'first_transaction';

    public const FIRST_BUDGET = 'first_budget';

    public const RETAINED_D1 = 'retained_d1';

    public const RETAINED_D7 = 'retained_d7';

    public const CHECKOUT_STARTED = 'checkout_started';

    public const SUBSCRIPTION_ACTIVATED = 'subscription_activated';

    /**
     * Product analytics must never interrupt the user's primary action.
     * Metadata must be aggregate/non-sensitive; never pass messages or PII here.
     */
    public function recordOnce(
        User|int $user,
        string $eventName,
        ?string $source = null,
        array $metadata = [],
        ?CarbonInterface $occurredAt = null,
    ): ?ProductEvent {
        $userId = $user instanceof User ? $user->getKey() : $user;

        try {
            if (! Schema::hasTable('product_events')) {
                return null;
            }

            return ProductEvent::query()->firstOrCreate(
                [
                    'user_id' => $userId,
                    'event_name' => $eventName,
                ],
                [
                    'source' => $source,
                    'metadata' => $metadata ?: null,
                    'occurred_at' => $occurredAt ?? now(),
                ],
            );
        } catch (\Throwable $exception) {
            report($exception);

            try {
                return ProductEvent::query()
                    ->where('user_id', $userId)
                    ->where('event_name', $eventName)
                    ->first();
            } catch (\Throwable) {
                return null;
            }
        }
    }

    public function recordWhatsAppActivity(User $user): void
    {
        if (Schema::hasTable('product_activity_days')) {
            $activity = ProductActivityDay::query()->firstOrCreate([
                'user_id' => $user->id,
                'activity_date' => today()->startOfDay(),
                'source' => 'whatsapp',
            ]);
            $activity->increment('interaction_count');
        }

        $this->recordOnce($user, self::FIRST_WHATSAPP_MESSAGE, 'whatsapp');

        if ($user->created_at?->lte(now()->subDay())) {
            $this->recordOnce($user, self::RETAINED_D1, 'whatsapp');
        }

        if ($user->created_at?->lte(now()->subDays(7))) {
            $this->recordOnce($user, self::RETAINED_D7, 'whatsapp');
        }
    }
}
