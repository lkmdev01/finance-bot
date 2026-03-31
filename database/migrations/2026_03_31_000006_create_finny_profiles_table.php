<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finny_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('score')->default(0);
            $table->unsignedInteger('xp')->default(0);
            $table->unsignedInteger('level')->default(1);
            $table->unsignedInteger('current_streak')->default(0);
            $table->unsignedInteger('best_streak')->default(0);
            $table->unsignedInteger('badges_unlocked')->default(0);
            $table->string('mood', 32)->default('steady');
            $table->date('last_activity_date')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finny_profiles');
    }
};
