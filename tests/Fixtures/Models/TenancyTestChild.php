<?php

namespace Tests\Fixtures\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Tests\Fixtures\Factories\TenancyTestChildFactory;

class TenancyTestChild extends Model
{
    use BelongsToTenant, HasFactory;

    protected static function newFactory()
    {
        return TenancyTestChildFactory::new();
    }

    protected $table = 'tenancy_test_children';

    protected $fillable = ['name'];

    protected $guarded = ['tenant_id', 'parent_id'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(TenancyTestParent::class, 'parent_id');
    }
}
