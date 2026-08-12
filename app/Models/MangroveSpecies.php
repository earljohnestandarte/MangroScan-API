<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MangroveSpecies extends Model
{
    use HasUuids;

    protected $primaryKey = 'species_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'scientific_name',
        'common_name',
        'local_name',
        'description',
        'typical_growth_rate_cm_per_year',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'typical_growth_rate_cm_per_year' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function treeObservations(): HasMany
    {
        return $this->hasMany(TreeObservation::class, 'final_species_id', 'species_id');
    }
}
