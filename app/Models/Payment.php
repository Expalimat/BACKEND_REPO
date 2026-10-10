<?php

namespace App\Models;

class Payment extends OessModel
{
    protected $casts = ['amount' => 'float'];
}
