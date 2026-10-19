<?php

namespace App\Models;

class AppointmentBusinessRule extends OessModel
{
    protected $table = 'appointment_business_rules';

    public function service()
    {
        return $this->belongsTo(AppointmentService::class, 'serviceId', 'id');
    }
}
