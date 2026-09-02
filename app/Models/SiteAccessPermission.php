<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteAccessPermission extends Model
{
    use HasUuids;

    protected $primaryKey = 'access_permission_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'site_id',
        'permit_title',
        'issuing_agency',
        'permit_number',
        'valid_from',
        'valid_until',
        'document_path',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_until' => 'date',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(SurveySite::class, 'site_id', 'site_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }
}
