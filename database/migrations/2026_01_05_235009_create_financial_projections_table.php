<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_projections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('projection_date');
            $table->decimal('projected_balance', 15, 2);
            $table->decimal('projected_income', 15, 2)->default(0);
            $table->decimal('projected_expenses', 15, 2)->default(0);
            $table->json('assumptions')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'projection_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_projections');
    }
};
