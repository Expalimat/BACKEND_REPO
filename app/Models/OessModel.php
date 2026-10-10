<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Base model: string primary keys ("ENR-2026-001"), no timestamps,
// internal "seq" column is only used for ordering and never sent to the frontend.
abstract class OessModel extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $guarded = [];
    protected $hidden = ['seq'];
}
