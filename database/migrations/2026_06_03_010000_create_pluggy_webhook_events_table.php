<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pluggy_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 128)->unique();
            $table->string('event_name', 64);
            $table->string('item_id', 128)->nullable()->index();
            $table->string('client_user_id', 128)->nullable()->index();
            $table->string('status', 32)->default('received');
            $table->text('error_message')->nullable();
            $table->json('payload');
            $table->timestamp('received_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pluggy_webhook_events');
    }
};
