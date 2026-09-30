<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_activity_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('activity_date');
            $table->string('source', 30);
            $table->unsignedInteger('interaction_count')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'activity_date', 'source']);
            $table->index(['activity_date', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_activity_days');
    }
};
