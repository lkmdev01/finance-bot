<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_token_usage_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('agent_key', 60);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('total_tokens')->default(0);
            $table->string('measurement', 16)->default('reported');
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'agent_key', 'created_at'], 'ai_token_events_user_agent_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_token_usage_events');
    }
};
