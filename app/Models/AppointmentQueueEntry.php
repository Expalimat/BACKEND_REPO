<?php

namespace App\Models;

class AppointmentQueueEntry extends OessModel
{
    protected $table = 'appointment_queue_entries';

    public function appointment()
    {
        return $this->belongsTo(Appointment::class, 'appointmentId', 'id');
    }
}
