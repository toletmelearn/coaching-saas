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
     * The Bunny library credential is never mass-assignable (set only via forceFill from
     * BunnyVideoProvider::ensureLibraryProvisioned) and never serialized to an array/JSON
     * response — defence in depth alongside the encrypted cast below. It doubles as the
     * embed-token signing key (verified against
     * https://bunny.net/docs/stream/mobile-sdk-token-authentication: "the token security
     * key is your Video Library API Key") — there is no separate token key.
     */
    protected $hidden = ['bunny_library_api_key'];

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'bunny_library_api_key' => 'encrypted',
            'bunny_library_created_at' => 'datetime',
        ];
    }

    public function domains(): HasMany
    {
        return $this->hasMany(TenantDomain::class);
    }
}
