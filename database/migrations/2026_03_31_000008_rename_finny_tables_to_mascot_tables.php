<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('finny_profiles') && ! Schema::hasTable('mascot_profiles')) {
            Schema::rename('finny_profiles', 'mascot_profiles');
        }

        if (Schema::hasTable('finny_achievement_unlocks') && ! Schema::hasTable('mascot_achievement_unlocks')) {
            Schema::rename('finny_achievement_unlocks', 'mascot_achievement_unlocks');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('mascot_profiles') && ! Schema::hasTable('finny_profiles')) {
            Schema::rename('mascot_profiles', 'finny_profiles');
        }

        if (Schema::hasTable('mascot_achievement_unlocks') && ! Schema::hasTable('finny_achievement_unlocks')) {
            Schema::rename('mascot_achievement_unlocks', 'finny_achievement_unlocks');
        }
    }
};
