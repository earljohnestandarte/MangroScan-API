<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Battery extends Model
{
    use HasUuids, SoftDeletes;

    protected $primaryKey = 'battery_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'battery_code',
        'battery_type',
        'capacity_mah',
        'voltage',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'capacity_mah' => 'decimal:2',
            'voltage' => 'decimal:2',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id', 'organization_id');
    }
}