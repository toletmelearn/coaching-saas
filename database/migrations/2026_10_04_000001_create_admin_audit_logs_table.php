<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 13 — platform admin audit trail (/admin/audit).
 *
 * Deliberately a *central* table: no tenant_id, no BelongsToTenant (mirrors
 * platform_admins / system_settings). Admin actions like login and settings
 * changes happen on the platform domain where no tenant is in context, and the
 * audit trail must keep working even when the target is a tenant-owned row —
 * the target is referenced only by (target_type, target_id), never by an FK
 * into a tenant table.
 *
 * No FK on admin_id either: audit history must survive the deletion of the
 * admin account that produced it (a restrict FK would make deleting an admin
 * impossible; a cascade would erase the trail — both wrong for an audit log).
 *
 * Exactly the columns the spec calls for: id, admin_id, action, target_type,
 * target_id, ip_address, created_at. updated_at is intentionally absent
 * (AdminAuditLog::UPDATED_AT = null) — audit rows are append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id');
            $table->string('action', 64);
            $table->string('target_type', 128)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->dateTime('created_at');

            // The /admin/audit page filters by action and sorts newest-first;
            // targets are looked up as (type, id) pairs.
            $table->index(['action', 'created_at']);
            $table->index('admin_id');
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_logs');
    }
};
