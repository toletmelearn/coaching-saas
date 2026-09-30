<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('user_id');
            $table->string('device_id', 64);
            $table->string('label', 80);
            $table->string('user_agent', 255)->nullable();
            // useCurrent(): MySQL strict mode rejects a non-nullable TIMESTAMP column
            // with no default at all once more than one TIMESTAMP column exists on the
            // table (only the first gets an implicit DEFAULT CURRENT_TIMESTAMP) —
            // SQLite tolerates it silently, which is why this only surfaced under
            // composer test:mysql. Both are always explicitly set by DeviceRegistrar
            // regardless; this is just a valid default for MySQL's DDL to accept.
            $table->timestamp('first_seen_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 20)->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'device_id']);
            $table->index(['tenant_id', 'user_id', 'revoked_at']);

            // Explicit FK (not the tenantForeign() macro, which hardcodes
            // restrictOnDelete()) — a deleted user takes their device rows with it.
            $table->foreign(['tenant_id', 'user_id'])
                ->references(['tenant_id', 'id'])
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_devices');
    }
};
