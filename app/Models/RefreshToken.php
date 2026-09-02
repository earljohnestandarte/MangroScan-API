<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefreshToken extends Model
{
    use HasUuids;

    protected $primaryKey = 'refresh_token_id';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['user_id', 'token_hash', 'expires_at', 'revoked_at', 'replaced_by'];
    protected function casts(): array { return ['expires_at' => 'datetime', 'revoked_at' => 'datetime']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class, 'user_id', 'user_id'); }
}
