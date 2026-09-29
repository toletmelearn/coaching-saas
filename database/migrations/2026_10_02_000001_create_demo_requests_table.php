<?php

use App\Enums\DemoRequestStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-level table (no tenant_id — submitted before any tenant exists), so
 * BelongsToTenant/composite-FK rules don't apply. Visible only to platform admins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_requests', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('phone', 20);
            $table->string('email', 255)->nullable();
            $table->string('institute_name', 255);
            $table->string('city', 100);
            $table->text('message')->nullable();
            $table->string('status', 20)->default(DemoRequestStatus::New->value);
            $table->timestamp('contacted_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_requests');
    }
};
