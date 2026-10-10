<?php

namespace App\Models;

class Clearance extends OessModel
{
    protected $casts = ['signoffs' => 'array'];
}
