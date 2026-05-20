<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('whats_app_contacts')) {
            return;
        }

        Schema::table('whats_app_contacts', function (Blueprint $table) {
            if (! Schema::hasColumn('whats_app_contacts', 'conversation_state')) {
                $table->json('conversation_state')->nullable()->after('context');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('whats_app_contacts')) {
            return;
        }

        Schema::table('whats_app_contacts', function (Blueprint $table) {
            if (Schema::hasColumn('whats_app_contacts', 'conversation_state')) {
                $table->dropColumn('conversation_state');
            }
        });
    }
};
