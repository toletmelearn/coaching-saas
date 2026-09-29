<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verified against https://bunny.net/docs/stream/mobile-sdk-token-authentication
 * ("the token security key is your Video Library API Key"): embed view tokens are signed
 * with the tenant's own library API key, not a separate value. The Create Video Library
 * API response never returned one anyway (docs/specs/phase-5-video.md's former "Open
 * gap"), so this column was always going to be either wrong or unusable — removed rather
 * than left dead. A new migration per CLAUDE.md ("Do not change database schema
 * casually" / never edit an already-applied migration), not an edit to
 * 2026_09_30_000002.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('bunny_library_token_key');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->text('bunny_library_token_key')->nullable()->after('bunny_library_api_key');
        });
    }
};
