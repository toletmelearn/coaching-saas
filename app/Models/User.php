<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Traits\BelongsToTenant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'phone', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToTenant, HasFactory, Notifiable;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'student',
        'status' => 'active',
        'must_change_password' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'status' => UserStatus::class,
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * An erased student keeps the reserved @removed.invalid sentinel email (see
     * StudentDataController::erase). Such a row must never be re-enabled or reset.
     */
    public function isErased(): bool
    {
        return str_ends_with((string) $this->email, '@removed.invalid');
    }

    /**
     * @return HasMany<UserDevice, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    protected static function booted(): void
    {
        static::saving(function (User $user) {
            if ($user->email !== null) {
                $user->email = strtolower($user->email);
            }

            if ($user->phone !== null) {
                $user->phone = static::normalizePhone($user->phone);
            }
        });
    }

    /**
     * The one login-identifier rule: an email (trimmed, lower-cased) or a phone (normalised as above).
     * Sign-in lookup and the login rate limiter both call this, so one account has one identity.
     */
    public static function normalizeLoginIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);

        return str_contains($identifier, '@')
            ? strtolower($identifier)
            : static::normalizePhone($identifier);
    }

    /**
     * Normalize an Indian phone number to its 10-digit canonical form,
     * stripping a leading +91/91 country code, a leading trunk 0, and spaces.
     */
    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return $digits;
    }
}
