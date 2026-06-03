<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('open_finance_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32)->default('pluggy');
            $table->string('item_id', 128);
            $table->unsignedInteger('connector_id')->nullable();
            $table->string('connector_name')->nullable();
            $table->string('status', 64)->nullable();
            $table->string('execution_status', 64)->nullable();
            $table->json('last_sync_summary')->nullable();
            $table->text('sync_error')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'item_id']);
            $table->index(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('open_finance_connections');
    }
};
