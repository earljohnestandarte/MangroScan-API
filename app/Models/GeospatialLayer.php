<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class GeospatialLayer extends Model
{
    use HasUuids;

    protected $primaryKey = 'layer_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['style_config' => 'array', 'is_visible_default' => 'boolean'];
    }
}
