<?php

namespace App\Models;

use App\Enums\PlatformAdminStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class PlatformAdmin extends Authenticatable
{
    use HasFactory;

    protected $fillable = ['name', 'email', 'password', 'status', 'last_login_at'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'status' => PlatformAdminStatus::class,
            'last_login_at' => 'datetime',
        ];
    }
}
