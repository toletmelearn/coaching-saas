<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenancy_test_children', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('parent_id');
            $table->string('name');
            $table->timestamps();

            $table->tenantForeign('parent_id', 'tenancy_test_parents');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenancy_test_children');
    }
};
