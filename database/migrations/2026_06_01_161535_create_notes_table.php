<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->text('body');
            $table->string('source', 40)->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Fast enough for MVP; can be replaced by full-text / embeddings later.
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};

