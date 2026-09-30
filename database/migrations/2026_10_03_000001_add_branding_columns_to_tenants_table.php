<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('logo_path', 255)->nullable();
            $table->string('theme_color', 7)->default('#4f46e5');
            $table->string('contact_phone', 20)->nullable();
            $table->string('contact_email', 255)->nullable();
            $table->date('academic_year_end')->nullable();
            $table->unsignedInteger('branding_version')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'logo_path',
                'theme_color',
                'contact_phone',
                'contact_email',
                'academic_year_end',
                'branding_version',
            ]);
        });
    }
};
