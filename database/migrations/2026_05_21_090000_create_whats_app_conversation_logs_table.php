<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whats_app_conversation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('whats_app_contact_id')->nullable()->constrained('whats_app_contacts')->nullOnDelete();
            $table->string('phone_number')->nullable()->index();
            $table->text('message');
            $table->string('classification')->nullable()->index();
            $table->string('action')->nullable()->index();
            $table->string('handler')->nullable();
            $table->boolean('used_ai')->default(false);
            $table->string('status')->default('processed')->index();
            $table->text('reply')->nullable();
            $table->string('error_type')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whats_app_conversation_logs');
    }
};
