<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->timestamp('active_since')->nullable();
            $table->timestamp('max_duration_warning_sent_at')->nullable();
            $table->timestamp('stop_requested_at')->nullable();
            $table->string('stop_reason', 32)->nullable();
            $table->index(['status', 'active_since'], 'meetings_reconcile_active_idx');
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropIndex('meetings_reconcile_active_idx');
            $table->dropColumn([
                'active_since',
                'max_duration_warning_sent_at',
                'stop_requested_at',
                'stop_reason',
            ]);
        });
    }
};
