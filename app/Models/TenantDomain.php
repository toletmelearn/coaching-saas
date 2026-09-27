<?php

namespace App\Models;

use App\Enums\DomainType;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read Tenant $tenant
 */
class TenantDomain extends Model
{
    use HasFactory;

    protected $fillable = ['tenant_id', 'domain', 'type', 'is_primary', 'verified_at'];

    protected function casts(): array
    {
        return [
            'type' => DomainType::class,
            'is_primary' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    protected function domain(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => rtrim(strtolower($value), '.'),
        );
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
