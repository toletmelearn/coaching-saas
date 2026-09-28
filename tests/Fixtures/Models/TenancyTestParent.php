<?php

namespace Tests\Fixtures\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Tests\Fixtures\Factories\TenancyTestParentFactory;

class TenancyTestParent extends Model
{
    use BelongsToTenant, HasFactory;

    protected static function newFactory()
    {
        return TenancyTestParentFactory::new();
    }

    protected $table = 'tenancy_test_parents';

    protected $fillable = ['name', 'slug'];

    protected $guarded = ['tenant_id'];

    public function children(): HasMany
    {
        return $this->hasMany(TenancyTestChild::class, 'parent_id');
    }
}
