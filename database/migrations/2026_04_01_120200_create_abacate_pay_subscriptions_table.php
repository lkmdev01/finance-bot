<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('abacate_pay_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_id')->nullable()->index();
            $table->string('gateway_subscription_id')->unique();
            $table->string('gateway_checkout_id')->nullable()->index();
            $table->string('gateway_payment_id')->nullable()->index();
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_tax_id')->nullable();
            $table->unsignedBigInteger('amount')->default(0);
            $table->string('currency')->default('BRL');
            $table->string('method')->nullable();
            $table->string('frequency')->nullable();
            $table->string('status')->default('PENDING');
            $table->boolean('dev_mode')->default(false);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('renewed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abacate_pay_subscriptions');
    }
};
