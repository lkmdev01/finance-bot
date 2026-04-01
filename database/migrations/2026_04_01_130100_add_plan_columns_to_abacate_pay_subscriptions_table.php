<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('abacate_pay_subscriptions', function (Blueprint $table) {
            $table->string('plan_code')->nullable()->after('user_id');
            $table->string('gateway_customer_id')->nullable()->after('gateway_subscription_id');
            $table->text('checkout_url')->nullable()->after('gateway_checkout_id');
        });
    }

    public function down(): void
    {
        Schema::table('abacate_pay_subscriptions', function (Blueprint $table) {
            $table->dropColumn([
                'plan_code',
                'gateway_customer_id',
                'checkout_url',
            ]);
        });
    }
};
