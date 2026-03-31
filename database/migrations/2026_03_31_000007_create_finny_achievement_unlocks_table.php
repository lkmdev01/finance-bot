<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finny_achievement_unlocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('achievement_key', 64);
            $table->timestamp('unlocked_at');
            $table->timestamp('seen_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'achievement_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finny_achievement_unlocks');
    }
};
