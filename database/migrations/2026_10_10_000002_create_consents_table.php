<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 15 — DPDP consent records.
 *
 * Every foreign key is composite (tenant_id, <column>) → parent(tenant_id, id),
 * exactly as TENANCY.md requires for tenant-owned parent/child pairs: a consent
 * can never name a student, guardian or recorder that lives in another tenant,
 * even when the numeric id happens to exist there. The nullable guardian_id
 * follows the same shape (MATCH SIMPLE lets it stay null).
 *
 * The unique key is (tenant_id, user_id, purpose, granted_at): the same purpose
 * may be granted again at a later date — that second grant is a different,
 * legitimate consent — while the exact same instant is a duplicate submission
 * rather than a second consent.
 *
 * withdrawal columns are deliberately separate from the grant: withdrawing never
 * deletes the grant it cancels, so both sides of the history stay readable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('guardian_id')->nullable();
            $table->string('purpose', 50);
            $table->string('notice_version', 50);
            $table->string('method', 50);
            $table->timestamp('granted_at');
            $table->timestamp('withdrawn_at')->nullable();
            $table->string('withdrawn_reason', 500)->nullable();
            $table->unsignedBigInteger('recorded_by');
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'user_id', 'purpose', 'granted_at'],
                'consents_tenant_user_purpose_granted_unique'
            );
            $table->index(['tenant_id', 'purpose']);
            $table->index(['tenant_id', 'user_id', 'purpose']);

            $table->foreign('tenant_id')
                ->references('id')->on('tenants')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'user_id'])
                ->references(['tenant_id', 'id'])->on('users')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'guardian_id'])
                ->references(['tenant_id', 'id'])->on('users')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'recorded_by'])
                ->references(['tenant_id', 'id'])->on('users')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consents');
    }
};
