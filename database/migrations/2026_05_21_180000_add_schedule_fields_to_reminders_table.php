<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reminders')) {
            return;
        }

        $hasDayOfWeek = Schema::hasColumn('reminders', 'day_of_week');
        $hasTriggerTime = Schema::hasColumn('reminders', 'trigger_time');

        Schema::table('reminders', function (Blueprint $table) use ($hasDayOfWeek, $hasTriggerTime) {
            if (! $hasDayOfWeek) {
                $table->unsignedTinyInteger('day_of_week')->nullable()->after('next_trigger_at');
            }

            if (! $hasTriggerTime) {
                $table->time('trigger_time')->nullable()->after('month_of_year');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('reminders')) {
            return;
        }

        $hasDayOfWeek = Schema::hasColumn('reminders', 'day_of_week');
        $hasTriggerTime = Schema::hasColumn('reminders', 'trigger_time');

        Schema::table('reminders', function (Blueprint $table) use ($hasDayOfWeek, $hasTriggerTime) {
            if ($hasDayOfWeek) {
                $table->dropColumn('day_of_week');
            }

            if ($hasTriggerTime) {
                $table->dropColumn('trigger_time');
            }
        });
    }
};
