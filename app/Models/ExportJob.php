<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ExportJob extends Model
{
    use HasUuids;

    protected $primaryKey = 'export_job_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['filters' => 'array', 'options' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
