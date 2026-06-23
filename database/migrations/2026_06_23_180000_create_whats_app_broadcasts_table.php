<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whats_app_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('whats_app_contact_id')->nullable()->constrained('whats_app_contacts')->nullOnDelete();
            $table->string('recipient_name')->nullable();
            $table->string('phone_number', 32);
            $table->string('audience', 40)->default('selected');
            $table->text('message');
            $table->string('status', 24)->default('pending');
            $table->unsignedSmallInteger('provider_status')->nullable();
            $table->json('provider_response')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['audience', 'status']);
            $table->index(['phone_number', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whats_app_broadcasts');
    }
};
