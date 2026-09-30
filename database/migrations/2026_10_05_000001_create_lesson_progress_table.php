<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('lesson_id');
            $table->foreignId('course_id');
            $table->foreignId('user_id');
            $table->unsignedInteger('watched_seconds')->default(0);
            $table->unsignedInteger('last_position_seconds')->default(0);
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->boolean('completed_manually')->default(false);
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->tenantKeys();
            $table->unique(['tenant_id', 'lesson_id', 'user_id']);
            $table->index(['tenant_id', 'course_id', 'user_id']);
            $table->index(['tenant_id', 'course_id', 'last_activity_at']);

            // Explicit FK (not the tenantForeign() macro, which hardcodes
            // restrictOnDelete()) — a deleted lesson takes its progress rows with it.
            $table->foreign(['tenant_id', 'lesson_id'])
                ->references(['tenant_id', 'id'])
                ->on('lessons')
                ->cascadeOnDelete();

            $table->tenantForeign('course_id', 'courses');
            $table->tenantForeign('user_id', 'users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_progress');
    }
};
