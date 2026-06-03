<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->foreignId('open_finance_connection_id')->nullable()->after('user_id')->constrained('open_finance_connections')->nullOnDelete();
            $table->string('open_finance_provider', 32)->nullable()->after('type');
            $table->string('open_finance_account_id', 128)->nullable()->after('open_finance_provider');
            $table->decimal('open_finance_balance', 15, 2)->nullable()->after('opening_balance');
            $table->timestamp('open_finance_synced_at')->nullable()->after('open_finance_balance');

            $table->unique(['open_finance_provider', 'open_finance_account_id'], 'bank_accounts_open_finance_unique');
        });

        Schema::table('credit_cards', function (Blueprint $table) {
            $table->foreignId('open_finance_connection_id')->nullable()->after('user_id')->constrained('open_finance_connections')->nullOnDelete();
            $table->string('open_finance_provider', 32)->nullable()->after('brand');
            $table->string('open_finance_account_id', 128)->nullable()->after('open_finance_provider');
            $table->decimal('open_finance_balance', 15, 2)->nullable()->after('opening_balance');
            $table->decimal('open_finance_available_limit', 15, 2)->nullable()->after('open_finance_balance');
            $table->timestamp('open_finance_synced_at')->nullable()->after('open_finance_available_limit');

            $table->unique(['open_finance_provider', 'open_finance_account_id'], 'credit_cards_open_finance_unique');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('open_finance_connection_id')->nullable()->after('subscription_id')->constrained('open_finance_connections')->nullOnDelete();
            $table->string('open_finance_provider', 32)->nullable()->after('open_finance_connection_id');
            $table->string('open_finance_account_id', 128)->nullable()->after('open_finance_provider');
            $table->string('open_finance_transaction_id', 128)->nullable()->after('open_finance_account_id');

            $table->unique(['open_finance_provider', 'open_finance_transaction_id'], 'transactions_open_finance_unique');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique('transactions_open_finance_unique');
            $table->dropConstrainedForeignId('open_finance_connection_id');
            $table->dropColumn([
                'open_finance_provider',
                'open_finance_account_id',
                'open_finance_transaction_id',
            ]);
        });

        Schema::table('credit_cards', function (Blueprint $table) {
            $table->dropUnique('credit_cards_open_finance_unique');
            $table->dropConstrainedForeignId('open_finance_connection_id');
            $table->dropColumn([
                'open_finance_provider',
                'open_finance_account_id',
                'open_finance_balance',
                'open_finance_available_limit',
                'open_finance_synced_at',
            ]);
        });

        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropUnique('bank_accounts_open_finance_unique');
            $table->dropConstrainedForeignId('open_finance_connection_id');
            $table->dropColumn([
                'open_finance_provider',
                'open_finance_account_id',
                'open_finance_balance',
                'open_finance_synced_at',
            ]);
        });
    }
};
