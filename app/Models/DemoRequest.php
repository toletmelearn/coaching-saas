<?php

namespace App\Models;

use App\Enums\DemoRequestStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Platform-level model — deliberately does NOT use BelongsToTenant. A demo request is
 * submitted by a prospect before any tenant exists, and is visible only to platform
 * admins (never a tenant-scoped user), so it has no tenant_id at all.
 */
class DemoRequest extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'phone', 'email', 'institute_name', 'city', 'message'];

    protected $attributes = [
        'status' => 'new',
    ];

    protected function casts(): array
    {
        return [
            'status' => DemoRequestStatus::class,
            'contacted_at' => 'datetime',
        ];
    }
}
