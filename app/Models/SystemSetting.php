<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['data' => 'array'];
}
