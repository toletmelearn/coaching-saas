<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('course_id');
            $table->foreignId('chapter_id');
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->unsignedInteger('position');
            $table->string('board_tag', 40)->nullable();
            $table->boolean('is_free_preview')->default(false);
            $table->string('youtube_video_id', 20)->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->tenantKeys();
            $table->index(['chapter_id', 'position']);

            $table->tenantForeign('course_id', 'courses');

            $table->foreign(['tenant_id', 'chapter_id'])
                ->references(['tenant_id', 'id'])
                ->on('chapters')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lessons');
    }
};
