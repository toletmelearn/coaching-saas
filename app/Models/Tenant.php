<?php

namespace App\Models;

use App\Enums\TenantStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'status', 'timezone', 'currency'];

    /**
     * Bunny library credentials are never mass-assignable (set only via forceFill from
     * BunnyVideoProvider::ensureLibraryProvisioned) and never serialized to an array/JSON
     * response — defence in depth alongside the encrypted cast below.
     */
    protected $hidden = ['bunny_library_api_key', 'bunny_library_token_key'];

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'bunny_library_api_key' => 'encrypted',
            'bunny_library_token_key' => 'encrypted',
            'bunny_library_created_at' => 'datetime',
        ];
    }

    public function domains(): HasMany
    {
        return $this->hasMany(TenantDomain::class);
    }
}
