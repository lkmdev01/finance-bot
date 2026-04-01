<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('abacatepay_customer_id')->nullable()->after('google_avatar');
            $table->string('billing_plan_code')->nullable()->after('abacatepay_customer_id');
            $table->string('billing_plan_status')->nullable()->after('billing_plan_code');
            $table->timestamp('billing_access_ends_at')->nullable()->after('billing_plan_status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'abacatepay_customer_id',
                'billing_plan_code',
                'billing_plan_status',
                'billing_access_ends_at',
            ]);
        });
    }
};
