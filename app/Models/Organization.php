<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use HasUuids, SoftDeletes;

    protected $primaryKey = 'organization_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'organization_id', 'organization_id');
    }

    public function surveySites(): HasMany
    {
        return $this->hasMany(SurveySite::class, 'organization_id', 'organization_id');
    }
}
