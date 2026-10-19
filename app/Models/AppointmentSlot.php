<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppointmentSlot extends OessModel
{
    protected $table = 'appointment_slots';

    public function service(): BelongsTo
    {
        return $this->belongsTo(AppointmentService::class, 'serviceId', 'id');
    }
}
