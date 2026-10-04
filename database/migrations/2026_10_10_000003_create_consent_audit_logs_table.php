<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 15 — the DPDP audit trail.
 *
 * One row per significant consent action (today: `student_erased`). `metadata`
 * is a JSON snapshot of what was deleted just before erasure ran — the
 * enrolment, live-class attendance and payment rows keyed field — so the
 * financial and participation facts survive the row they lived in without
 * carrying personal data (the user_id/actor_id columns point at the anonymised
 * student and the acting owner/staff member).
 *
 * Composite FKs, as everywhere: the log can only name a student and an actor
 * from its own tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consent_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('actor_id');
            $table->string('action', 50);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'action']);

            $table->foreign('tenant_id')
                ->references('id')->on('tenants')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'user_id'])
                ->references(['tenant_id', 'id'])->on('users')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'actor_id'])
                ->references(['tenant_id', 'id'])->on('users')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_audit_logs');
    }
};
