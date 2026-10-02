<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 — the columns either side of the payments table.
 *
 * Runs after create_payments_table because enrolments.approved_payment_id is a
 * composite foreign key INTO payments.
 *
 * approved_payment_id uses restrictOnDelete() rather than the SET NULL the spec
 * originally asked for: a composite foreign key whose other half (tenant_id) is
 * NOT NULL cannot be SET NULL — MySQL refuses the statement outright (errno 150),
 * and SQLite would try to NULL the NOT NULL tenant_id when the payment went away.
 * RESTRICT keeps the cross-tenant guarantee the schema test asserts, and nothing
 * in the application deletes a payment, so the restriction never fires in practice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('upi_id', 100)->nullable();
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->unsignedInteger('fee_paise')->nullable();
        });

        Schema::table('enrolments', function (Blueprint $table) {
            $table->unsignedBigInteger('approved_payment_id')->nullable();

            $table->foreign(['tenant_id', 'approved_payment_id'])
                ->references(['tenant_id', 'id'])
                ->on('payments')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('enrolments', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'approved_payment_id']);
            $table->dropColumn('approved_payment_id');
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('fee_paise');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('upi_id');
        });
    }
};
