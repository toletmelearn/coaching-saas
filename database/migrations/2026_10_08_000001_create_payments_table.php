<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 — manual UPI payments.
 *
 * Both foreign keys are composite (tenant_id, <column>) so that a row can never
 * reference a parent in a different tenant, even if the numeric id happens to
 * exist there. That is the same convention as tenantForeign(), except that
 * enrolment_id cascades (deleting an enrolment takes its payment history with
 * it) while reviewed_by restricts (a reviewer's audit trail is not silently
 * dropped).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('enrolment_id');
            $table->unsignedInteger('amount_paise');
            $table->string('upi_reference', 64)->nullable();
            $table->string('screenshot_path', 255);
            $table->string('status', 20)->default('pending');
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamp('submitted_at')->useCurrent();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamps();

            $table->tenantKeys();
            $table->index(['tenant_id', 'status', 'submitted_at']);

            $table->foreign(['tenant_id', 'enrolment_id'])
                ->references(['tenant_id', 'id'])
                ->on('enrolments')
                ->cascadeOnDelete();

            $table->tenantForeign('reviewed_by', 'users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
