<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drive_files', function (Blueprint $table) {
            $table->string('metadata_status', 32)->default('unavailable')->after('metadata')->index();
            $table->text('metadata_error')->nullable()->after('metadata_status');
            $table->timestamp('metadata_analyzed_at')->nullable()->after('metadata_error');
        });
    }

    public function down(): void
    {
        Schema::table('drive_files', function (Blueprint $table) {
            $table->dropColumn([
                'metadata_status',
                'metadata_error',
                'metadata_analyzed_at',
            ]);
        });
    }
};
