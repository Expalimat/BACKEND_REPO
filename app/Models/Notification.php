<?php

namespace App\Models;

class Notification extends OessModel
{
    protected $casts = ['isRead' => 'boolean'];
}
