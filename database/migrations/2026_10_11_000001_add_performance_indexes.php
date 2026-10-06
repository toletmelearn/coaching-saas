<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ops readiness — performance indexes for the most common tenant-scoped queries.
 *
 * Indexes added:
 *
 *  enrolments:
 *   - (tenant_id, user_id)   — "all enrolments for a student in this tenant"
 *   - (tenant_id, course_id) — "all enrolments for a course in this tenant"
 *                              (separate from the 3-col UNIQUE(tenant_id, course_id, user_id))
 *   - (tenant_id, ends_at)   — expiry scanning for renewal reminders
 *
 *  payments:
 *   - (tenant_id, enrolment_id) — payment lookup by enrolment; MySQL creates an
 *                                  implicit index for the composite FK, but adding
 *                                  it explicitly keeps the schema portable to SQLite
 *                                  and makes the intent clear
 *
 *  live_classes:
 *   - (course_id, starts_at) skipped — already covered by the existing 3-col index
 *     (tenant_id, course_id, starts_at), which is strictly better because tenant_id
 *     is always present in the WHERE clause via BelongsToTenant global scope
 *
 *  lesson_progress:
 *   - (tenant_id, user_id)   — student progress dashboard (all progress for a user)
 *
 * Column-name notes:
 *   - task spec says "student_id" for enrolments/lesson_progress; actual column is "user_id"
 *   - task spec says "expires_at" for enrolments; actual column is "ends_at"
 *   - task spec says "paid_at" for payments; that column does not exist (closest is
 *     reviewed_at) — index skipped; see summary in DEPLOY_RUNBOOK
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrolments', function (Blueprint $table) {
            $table->index(['tenant_id', 'user_id'],   'enrolments_tenant_id_user_id_index');
            $table->index(['tenant_id', 'course_id'], 'enrolments_tenant_id_course_id_index');
            $table->index(['tenant_id', 'ends_at'],   'enrolments_tenant_id_ends_at_index');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index(['tenant_id', 'enrolment_id'], 'payments_tenant_id_enrolment_id_index');
        });

        Schema::table('lesson_progress', function (Blueprint $table) {
            $table->index(['tenant_id', 'user_id'], 'lesson_progress_tenant_id_user_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('enrolments', function (Blueprint $table) {
            $table->dropIndex('enrolments_tenant_id_user_id_index');
            $table->dropIndex('enrolments_tenant_id_course_id_index');
            $table->dropIndex('enrolments_tenant_id_ends_at_index');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_tenant_id_enrolment_id_index');
        });

        Schema::table('lesson_progress', function (Blueprint $table) {
            $table->dropIndex('lesson_progress_tenant_id_user_id_index');
        });
    }
};
