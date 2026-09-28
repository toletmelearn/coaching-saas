<?php

namespace App\Macros;

use Illuminate\Database\Schema\Blueprint;

class BlueprintTenancyMacro
{
    public static function register(): void
    {
        Blueprint::macro('tenantKeys', function () {
            $this->unique(['tenant_id', 'id']);
        });

        Blueprint::macro('tenantForeign', function (string $column, string $table) {
            $this->foreign(['tenant_id', $column])
                ->references(['tenant_id', 'id'])
                ->on($table)
                ->restrictOnDelete();
        });
    }
}
