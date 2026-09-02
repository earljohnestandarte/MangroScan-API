<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $primaryKey = 'setting_key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['setting_key', 'setting_group', 'setting_value', 'description'];
}
