<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drive_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_incoming_media_id')->nullable()->constrained('whatsapp_incoming_media')->nullOnDelete();
            $table->string('source', 32)->nullable()->index(); // whatsapp|web

            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 191)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('sha256', 64)->nullable()->index();

            $table->string('drive_file_id', 128)->nullable()->unique();
            $table->string('drive_parent_id', 128)->nullable()->index();
            $table->string('drive_path', 512)->nullable();

            $table->string('title', 200)->nullable()->index();
            $table->text('description')->nullable();
            $table->json('tags')->nullable();
            $table->longText('extracted_text')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drive_files');
    }
};

