<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('abacate_pay_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->unique();
            $table->string('event_name');
            $table->unsignedInteger('api_version')->nullable();
            $table->boolean('dev_mode')->default(false);
            $table->string('status')->default('processed');
            $table->json('payload');
            $table->timestamp('received_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abacate_pay_webhook_events');
    }
};
