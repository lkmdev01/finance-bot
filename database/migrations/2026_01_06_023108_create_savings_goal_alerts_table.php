<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('savings_goal_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('savings_goal_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // 'milestone', 'deadline', 'low_progress'
            $table->decimal('threshold_percentage', 5, 2)->nullable(); // Para milestones
            $table->integer('days_before_deadline')->nullable(); // Para deadline
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_triggered_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'savings_goal_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('savings_goal_alerts');
    }
};
