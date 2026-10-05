<?php

namespace App\Models;

use App\Enums\PlatformAdminStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class PlatformAdmin extends Authenticatable
{
    use HasFactory;

    protected $fillable = ['name', 'email', 'password', 'status', 'last_login_at'];

    public function hasTwoFactor(): bool
    {
        return $this->totp_secret !== null;
    }

    protected $hidden = ['password', 'remember_token', 'totp_secret', 'recovery_codes'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'status' => PlatformAdminStatus::class,
            'last_login_at' => 'datetime',
            // Encrypted at rest: a database dump alone must not yield a usable TOTP seed.
            'totp_secret' => 'encrypted',
            'totp_enabled_at' => 'datetime',
            // Bcrypt hashes of the unused recovery codes; each is removed once used.
            'recovery_codes' => 'array',
        ];
    }
}
