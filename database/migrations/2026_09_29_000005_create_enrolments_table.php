<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrolments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('course_id');
            $table->foreignId('user_id');
            $table->string('status')->default('active');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->string('payment_note', 255)->nullable();
            $table->foreignId('enrolled_by')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable();
            $table->timestamps();

            $table->tenantKeys();
            $table->unique(['tenant_id', 'course_id', 'user_id']);

            $table->tenantForeign('course_id', 'courses');
            $table->tenantForeign('user_id', 'users');

            $table->foreign(['tenant_id', 'enrolled_by'])
                ->references(['tenant_id', 'id'])
                ->on('users')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'revoked_by'])
                ->references(['tenant_id', 'id'])
                ->on('users')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrolments');
    }
};
