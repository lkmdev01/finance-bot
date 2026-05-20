<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whats_app_activation_codes', function (Blueprint $table) {
            $table->id();
            $table->string('client_key')->unique();
            $table->string('code')->unique();
            $table->string('verified_phone_number')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whats_app_activation_codes');
    }
};
