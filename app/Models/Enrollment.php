<?php

namespace App\Models;

class Enrollment extends OessModel
{
    protected $casts = ['totalUnits' => 'integer', 'items' => 'array', 'fees' => 'array'];
}
