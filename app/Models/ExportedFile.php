<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ExportedFile extends Model
{
    use HasUuids;

    protected $primaryKey = 'export_file_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['file_size_bytes' => 'integer', 'exported_at' => 'datetime'];
    }
}
