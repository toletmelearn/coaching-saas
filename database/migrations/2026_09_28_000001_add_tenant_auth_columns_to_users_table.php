<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_email_unique');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('tenant_id')->after('id')->constrained('tenants')->restrictOnDelete();
            $table->string('phone')->nullable()->after('email');
            $table->string('role')->default('student')->after('password');
            $table->string('status')->default('active')->after('role');
            $table->boolean('must_change_password')->default(false)->after('status');
            $table->timestamp('last_login_at')->nullable()->after('must_change_password');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->tenantKeys();
            $table->unique(['tenant_id', 'email']);
            $table->unique(['tenant_id', 'phone']);
        });

        $this->addContactRequiredConstraint();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->dropContactRequiredConstraint();

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'email']);
            $table->dropUnique(['tenant_id', 'phone']);
            $table->dropUnique(['tenant_id', 'id']);
            $table->dropConstrainedForeignId('tenant_id');
            $table->dropColumn(['phone', 'role', 'status', 'must_change_password', 'last_login_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unique('email');
        });
    }

    /**
     * At least one of email/phone must be present. Laravel's schema builder has no
     * cross-platform CHECK constraint API, so this is added per-driver: a native CHECK
     * on MySQL, and BEFORE INSERT/UPDATE triggers on SQLite (which cannot add a CHECK
     * via ALTER TABLE — only at CREATE TABLE time — but triggers can be added anytime).
     */
    private function addContactRequiredConstraint(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER users_require_contact_insert
                BEFORE INSERT ON users
                WHEN NEW.email IS NULL AND NEW.phone IS NULL
                BEGIN
                    SELECT RAISE(ABORT, 'CHECK constraint failed: users_email_or_phone');
                END;

                CREATE TRIGGER users_require_contact_update
                BEFORE UPDATE ON users
                WHEN NEW.email IS NULL AND NEW.phone IS NULL
                BEGIN
                    SELECT RAISE(ABORT, 'CHECK constraint failed: users_email_or_phone');
                END;
            SQL);

            return;
        }

        DB::statement(
            'ALTER TABLE users ADD CONSTRAINT users_email_or_phone_check CHECK (email IS NOT NULL OR phone IS NOT NULL)'
        );
    }

    private function dropContactRequiredConstraint(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS users_require_contact_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS users_require_contact_update');

            return;
        }

        DB::statement('ALTER TABLE users DROP CONSTRAINT users_email_or_phone_check');
    }
};
