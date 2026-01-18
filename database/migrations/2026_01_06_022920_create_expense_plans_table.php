<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('planned_amount', 15, 2);
            $table->decimal('spent_amount', 15, 2)->default(0);
            $table->date('start_date');
            $table->date('end_date');
            $table->json('categories')->nullable(); // IDs das categorias relacionadas
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_plans');
    }
};
