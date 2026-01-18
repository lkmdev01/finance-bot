<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_duplicates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('duplicate_transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->decimal('similarity_score', 5, 2)->default(0);
            $table->json('match_criteria')->nullable();
            $table->boolean('is_resolved')->default(false);
            $table->timestamps();

            $table->unique(['transaction_id', 'duplicate_transaction_id'], 'trans_dup_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_duplicates');
    }
};
