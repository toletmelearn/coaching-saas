<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12 — one row per stay in a live class ("session").
 *
 * written only through forceFill() by the join/heartbeat path: joined_at,
 * last_seen_at, left_at and duration_seconds are all server-derived (SECURITY.md
 * "Client input never reaches attendance columns" — the heartbeat body is
 * ignored outright). The unique (tenant_id, live_class_id, user_id, joined_at)
 * key lets the same student rejoin after a gap (a second row at a second
 * instant) while making an accidental double-write at the same instant loud.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_class_attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('live_class_id');
            $table->foreignId('user_id');
            $table->dateTime('joined_at');
            $table->dateTime('last_seen_at');
            $table->dateTime('left_at')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->timestamps();

            $table->tenantKeys();
            // Explicit name: MySQL caps identifiers at 64 chars, and the
            // auto-generated one (table + all four columns + _unique) is 74.
            $table->unique(
                ['tenant_id', 'live_class_id', 'user_id', 'joined_at'],
                'live_class_attendance_stay_unique',
            );
            $table->index(['tenant_id', 'live_class_id', 'user_id']);

            // Explicit FK (not the tenantForeign() macro, which hardcodes
            // restrictOnDelete()) — a deleted live class takes its attendance
            // with it: nothing else references these rows, and a teacher's
            // delete must not 500 on the FK because somebody once joined.
            $table->foreign(['tenant_id', 'live_class_id'])
                ->references(['tenant_id', 'id'])
                ->on('live_classes')
                ->cascadeOnDelete();

            $table->tenantForeign('user_id', 'users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_class_attendance');
    }
};
