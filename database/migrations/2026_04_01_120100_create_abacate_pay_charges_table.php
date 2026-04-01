<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('abacate_pay_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_id')->nullable()->index();
            $table->string('gateway_charge_id')->nullable()->unique();
            $table->string('charge_type')->default('transparent');
            $table->string('method')->nullable();
            $table->string('status')->default('PENDING');
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('paid_amount')->nullable();
            $table->text('payment_url')->nullable();
            $table->longText('br_code')->nullable();
            $table->longText('br_code_base64')->nullable();
            $table->text('receipt_url')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('dev_mode')->default(false);
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_tax_id')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abacate_pay_charges');
    }
};
