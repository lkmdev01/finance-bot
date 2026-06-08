<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('weekly_goal_review_runs')->default(1);
            $table->unsignedInteger('weekly_goal_item_approvals')->default(10);
            $table->unsignedInteger('weekly_goal_sync_runs')->default(1);
            $table->string('admin_whatsapp_number', 32)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_settings');
    }
};
