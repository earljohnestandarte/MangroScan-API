<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ConfidenceFlag extends Model
{
    use HasUuids;

    protected $primaryKey = 'confidence_flag_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
