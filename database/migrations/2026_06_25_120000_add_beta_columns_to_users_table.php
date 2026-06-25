<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'beta_status')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('beta_status')->nullable()->after('is_admin')->index();
            });
        }

        if (! Schema::hasColumn('users', 'beta_notes')) {
            Schema::table('users', function (Blueprint $table) {
                $table->text('beta_notes')->nullable()->after('beta_status');
            });
        }

        if (! Schema::hasColumn('users', 'beta_invited_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('beta_invited_at')->nullable()->after('beta_notes');
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'beta_invited_at')) {
                $table->dropColumn('beta_invited_at');
            }

            if (Schema::hasColumn('users', 'beta_notes')) {
                $table->dropColumn('beta_notes');
            }

            if (Schema::hasColumn('users', 'beta_status')) {
                $table->dropColumn('beta_status');
            }
        });
    }
};
