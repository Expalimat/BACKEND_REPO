<?php

namespace App\Models;

class AppointmentSlot extends OessModel
{
    protected $table = 'appointment_slots';

    public function service()
    {
        return $this->belongsTo(AppointmentService::class, 'serviceId', 'id');
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class, 'slotId', 'id');
    }
}
