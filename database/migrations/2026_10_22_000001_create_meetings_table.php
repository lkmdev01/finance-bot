<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whats_app_contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('platform', 32);
            $table->string('native_meeting_id_hash', 64);
            $table->text('native_meeting_id');
            $table->text('meeting_url');
            $table->text('vexa_meeting_id')->nullable();
            $table->char('vexa_meeting_id_hash', 64)->nullable()->unique();
            $table->boolean('participant_notice_attested')->default(false);
            $table->timestamp('participant_notice_attested_at')->nullable();
            $table->string('status', 32)->default('queued');
            $table->longText('transcript')->nullable();
            $table->longText('summary')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'updated_at']);
            $table->index(['user_id', 'platform', 'native_meeting_id_hash', 'status'], 'meetings_dedupe_idx');
        });

        Schema::create('meeting_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->char('event_hash', 64)->unique();
            $table->foreignId('meeting_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 64);
            $table->longText('payload');
            $table->timestamp('received_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_webhook_events');
        Schema::dropIfExists('meetings');
    }
};
