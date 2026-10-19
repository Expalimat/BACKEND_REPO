<?php

namespace App\Models;

class AppointmentService extends OessModel
{
    protected $table = 'appointment_services';

    public function slots()
    {
        return $this->hasMany(AppointmentSlot::class, 'serviceId', 'id');
    }
}
