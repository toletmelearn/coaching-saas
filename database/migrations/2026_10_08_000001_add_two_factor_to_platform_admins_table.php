<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_admins', function (Blueprint $table) {
            $table->text('totp_secret')->nullable();
            $table->timestamp('totp_enabled_at')->nullable();
            $table->unsignedBigInteger('totp_last_step')->nullable();
            $table->text('recovery_codes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('platform_admins', function (Blueprint $table) {
            $table->dropColumn(['totp_secret', 'totp_enabled_at', 'totp_last_step', 'recovery_codes']);
        });
    }
};
