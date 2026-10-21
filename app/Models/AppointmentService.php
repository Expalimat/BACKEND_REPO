<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class AppointmentService extends OessModel
{
    protected $table = 'appointment_services';

    protected $casts = [
        'isActive' => 'boolean',
        'publicBookable' => 'boolean',
        'requiredDocuments' => 'array',
        'serviceDurationMinutes' => 'integer',
        'bookingWindowDays' => 'integer',
        'cancellationCutoffMinutes' => 'integer',
        'lateArrivalGraceMinutes' => 'integer',
        'earlyArrivalWindowMinutes' => 'integer',
    ];

    public function closures(): HasMany
    {
        return $this->hasMany(AppointmentServiceClosure::class, 'serviceId', 'id');
    }
}
