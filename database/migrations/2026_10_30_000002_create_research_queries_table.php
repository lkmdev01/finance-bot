<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_queries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_contact_id')->nullable()->constrained('whats_app_contacts')->nullOnDelete();
            $table->text('query');
            $table->text('search_query');
            $table->longText('answer')->nullable();
            $table->json('sources')->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->string('provider', 32)->nullable();
            $table->string('failure_code', 48)->nullable();
            $table->timestamp('lease_expires_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_queries');
    }
};
