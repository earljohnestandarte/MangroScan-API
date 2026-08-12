<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    use HasUuids;

    protected $primaryKey = 'personal_access_token_id';

    public $incrementing = false;

    protected $keyType = 'string';
}
