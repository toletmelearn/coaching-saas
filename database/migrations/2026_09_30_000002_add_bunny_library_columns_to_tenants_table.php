<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `tenants` is not a tenant-owned table (it has no tenant_id of its own — it IS the
 * tenant), so BelongsToTenant / composite-FK rules don't apply here. Each tenant's Bunny
 * Stream library is provisioned lazily on that tenant's first video upload (see
 * docs/specs/phase-5-video.md); the api key and token key are Laravel `encrypted` casts on
 * the model (never plaintext columns) and are additionally in Tenant::$hidden.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedBigInteger('bunny_library_id')->nullable()->after('currency');
            $table->text('bunny_library_api_key')->nullable()->after('bunny_library_id');
            $table->text('bunny_library_token_key')->nullable()->after('bunny_library_api_key');
            $table->timestamp('bunny_library_created_at')->nullable()->after('bunny_library_token_key');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'bunny_library_id',
                'bunny_library_api_key',
                'bunny_library_token_key',
                'bunny_library_created_at',
            ]);
        });
    }
};
