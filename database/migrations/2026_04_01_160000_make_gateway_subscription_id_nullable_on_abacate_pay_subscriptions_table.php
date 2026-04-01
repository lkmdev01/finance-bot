<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('abacate_pay_subscriptions') || ! Schema::hasColumn('abacate_pay_subscriptions', 'gateway_subscription_id')) {
            return;
        }

        if (! $this->usesMysql()) {
            return;
        }

        DB::statement('ALTER TABLE `abacate_pay_subscriptions` MODIFY `gateway_subscription_id` VARCHAR(255) NULL');
    }

    public function down(): void
    {
        if (! Schema::hasTable('abacate_pay_subscriptions') || ! Schema::hasColumn('abacate_pay_subscriptions', 'gateway_subscription_id')) {
            return;
        }

        if (! $this->usesMysql()) {
            return;
        }

        DB::statement('ALTER TABLE `abacate_pay_subscriptions` MODIFY `gateway_subscription_id` VARCHAR(255) NOT NULL');
    }

    private function usesMysql(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
