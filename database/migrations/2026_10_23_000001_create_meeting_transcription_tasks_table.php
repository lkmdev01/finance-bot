<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->longText('transcript_segments')->nullable();
            $table->timestamp('transcript_expires_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->text('native_meeting_id')->nullable()->change();
            $table->text('meeting_url')->nullable()->change();
        });

        Schema::create('meeting_transcription_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 24)->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(5);
            $table->timestamp('available_at')->index();
            $table->char('lease_token_hash', 64)->nullable()->index();
            $table->timestamp('lease_expires_at')->nullable()->index();
            $table->timestamp('heartbeat_at')->nullable();
            $table->text('recording_id')->nullable();
            $table->text('media_file_id')->nullable();
            $table->longText('processing_metadata')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'available_at', 'lease_expires_at'], 'meeting_transcription_claim_idx');
        });
    }

    public function down(): void
    {
        if (DB::table('meeting_transcription_tasks')->exists()
            || DB::table('meetings')
                ->whereNotNull('transcript_segments')
                ->orWhereNotNull('transcript_expires_at')
                ->orWhereNotNull('expires_at')
                ->orWhereNull('native_meeting_id')
                ->orWhereNull('meeting_url')
                ->exists()) {
            throw new RuntimeException('Refusing to drop meeting transcription or retention data during rollback. Preserve the schema or export and reconcile the data first.');
        }

        Schema::dropIfExists('meeting_transcription_tasks');
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropIndex('meetings_transcript_expires_at_index');
            $table->dropIndex('meetings_expires_at_index');
            $table->dropColumn(['transcript_segments', 'transcript_expires_at', 'expires_at']);
        });
        Schema::table('meetings', function (Blueprint $table) {
            $table->text('native_meeting_id')->nullable(false)->change();
            $table->text('meeting_url')->nullable(false)->change();
        });
    }
};
