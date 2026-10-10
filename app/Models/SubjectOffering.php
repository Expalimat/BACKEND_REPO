<?php

namespace App\Models;

class SubjectOffering extends OessModel
{
    protected $casts = [
        'units' => 'integer',
        'totalSlots' => 'integer',
        'availableSlots' => 'integer',
        'isPrerequisiteSatisfied' => 'boolean',
    ];
}
