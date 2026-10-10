<?php

namespace App\Models;

class Term extends OessModel
{
    protected $casts = ['isCurrent' => 'boolean'];
}
