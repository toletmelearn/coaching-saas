<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chapters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('course_id');
            $table->string('title', 150);
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->tenantKeys();
            $table->index(['course_id', 'position']);

            $table->foreign(['tenant_id', 'course_id'])
                ->references(['tenant_id', 'id'])
                ->on('courses')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chapters');
    }
};
