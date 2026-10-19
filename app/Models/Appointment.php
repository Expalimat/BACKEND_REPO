<?php

namespace App\Models;

class Appointment extends OessModel
{
    protected $table = 'appointments';

    public function service()
    {
        return $this->belongsTo(AppointmentService::class, 'serviceId', 'id');
    }

    public function slot()
    {
        return $this->belongsTo(AppointmentSlot::class, 'slotId', 'id');
    }

    public function queueEntry()
    {
        return $this->hasOne(AppointmentQueueEntry::class, 'appointmentId', 'id');
    }
}
