<?php

namespace App\Console\Commands;

use App\Models\EmailLog;
use App\Models\User;
use App\Notifications\BillingPlanExpiringNotification;
use Illuminate\Console\Command;

class SendExpiringPlanEmailsCommand extends Command
{
    protected $signature = 'billing:send-expiring-emails {--days=3 : Janela de dias para avisar vencimento}';

    protected $description = 'Envia avisos de plano proximo de vencer para usuarios premium.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $sent = 0;

        User::query()
            ->whereNotNull('billing_plan_code')
            ->whereIn('billing_plan_status', ['active', 'renewed', 'cancelled'])
            ->whereBetween('billing_access_ends_at', [now(), now()->addDays($days)])
            ->orderBy('billing_access_ends_at')
            ->chunkById(100, function ($users) use (&$sent) {
                foreach ($users as $user) {
                    if (! $user->wantsEmail('billing') || ! $user->billing_access_ends_at) {
                        continue;
                    }

                    $alreadySent = EmailLog::query()
                        ->where('user_id', $user->id)
                        ->where('notification_type', BillingPlanExpiringNotification::class)
                        ->where('created_at', '>=', now()->subDays(7))
                        ->exists();

                    if ($alreadySent) {
                        continue;
                    }

                    $user->notify(new BillingPlanExpiringNotification(
                        planCode: $user->billing_plan_code,
                        accessEndsAt: $user->billing_access_ends_at,
                    ));

                    $sent++;
                }
            });

        $this->info("Avisos enviados: {$sent}");

        return self::SUCCESS;
    }
}
