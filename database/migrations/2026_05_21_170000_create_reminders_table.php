<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('message', 255);
            $table->string('frequency', 20);
            $table->string('timezone', 60)->default(config('app.timezone', 'America/Sao_Paulo'));
            $table->dateTime('next_trigger_at')->nullable()->index();
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->unsignedTinyInteger('month_of_year')->nullable();
            $table->dateTime('last_sent_at')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};
