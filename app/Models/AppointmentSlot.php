<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AppointmentSlot extends OessModel
{
    protected $table = 'appointment_slots';

    public function service(): BelongsTo
    {
        return $this->belongsTo(AppointmentService::class, 'serviceId', 'id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'slotId', 'id');
    }
}
