<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('lesson_id');
            $table->string('disk', 30);
            $table->string('path', 255);
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->tenantKeys();

            $table->foreign(['tenant_id', 'lesson_id'])
                ->references(['tenant_id', 'id'])
                ->on('lessons')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_attachments');
    }
};
