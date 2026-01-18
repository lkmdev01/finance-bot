<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('recurring_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type'); // 'income' ou 'expense'
            $table->decimal('amount', 15, 2);
            $table->text('description')->nullable();
            $table->string('frequency'); // 'daily', 'weekly', 'monthly', 'yearly'
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('last_processed_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('day_of_month')->nullable(); // Para frequência mensal
            $table->integer('day_of_week')->nullable(); // Para frequência semanal (0-6, domingo=0)
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recurring_transactions');
    }
};
