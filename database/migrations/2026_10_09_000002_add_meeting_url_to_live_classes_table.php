<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 2.1 — pasted-link fallback for live classes.
 *
 * meeting_url is optional (nullable): an owner may supply an external meeting
 * link (Google Meet / Zoom / Teams) that appears on the student show page as a
 * "Join class" button when the class is live or within the pre-join window.
 * The column is validated at write time (MeetingUrl rule: https-only, host
 * allow-list); the URL leaves the server only as a rendered <a> href in a
 * page the student has already been authorised to view — no new policy surface.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_classes', function (Blueprint $table) {
            $table->string('meeting_url', 2048)->nullable()->after('jitsi_room_name');
        });
    }

    public function down(): void
    {
        Schema::table('live_classes', function (Blueprint $table) {
            $table->dropColumn('meeting_url');
        });
    }
};
