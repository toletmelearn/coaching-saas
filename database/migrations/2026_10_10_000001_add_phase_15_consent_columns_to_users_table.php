<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 15 — guardian contact details on the student record.
 *
 * All four columns are nullable: a student created before Phase 15 (or imported
 * from a CSV that carries no guardian data) is perfectly valid, and the consent
 * notice on the creation form is what fills these in from then on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('guardian_name')->nullable()->after('phone');
            $table->string('guardian_relationship', 100)->nullable()->after('guardian_name');
            $table->string('guardian_phone', 20)->nullable()->after('guardian_relationship');
            $table->string('guardian_email')->nullable()->after('guardian_phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'guardian_name',
                'guardian_relationship',
                'guardian_phone',
                'guardian_email',
            ]);
        });
    }
};
