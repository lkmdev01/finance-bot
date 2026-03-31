<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_transactions', function (Blueprint $table) {
            $table->foreignId('bank_account_id')->nullable()->after('category_id')->constrained()->nullOnDelete();
            $table->foreignId('credit_card_id')->nullable()->after('bank_account_id')->constrained()->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->after('credit_card_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('recurring_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subscription_id');
            $table->dropConstrainedForeignId('credit_card_id');
            $table->dropConstrainedForeignId('bank_account_id');
        });
    }
};
