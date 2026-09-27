<?php

namespace App\Models;

use App\Enums\TenantStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'status', 'timezone', 'currency'];

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
        ];
    }
}
