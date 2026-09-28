<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('title', 150);
            $table->string('slug', 170);
            $table->text('description')->nullable();
            $table->string('class_level', 20)->nullable();
            $table->string('subject', 60)->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->timestamps();

            $table->tenantKeys();
            $table->unique(['tenant_id', 'slug']);
            $table->tenantForeign('created_by', 'users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
