<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'email_preferences')) {
            Schema::table('users', function (Blueprint $table) {
                $table->json('email_preferences')->nullable()->after('beta_invited_at');
            });
        }

        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('to_email')->index();
            $table->string('subject')->nullable();
            $table->string('notification_type')->nullable()->index();
            $table->string('mailer')->nullable();
            $table->string('status')->default('sent')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');

        if (Schema::hasColumn('users', 'email_preferences')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('email_preferences');
            });
        }
    }
};
