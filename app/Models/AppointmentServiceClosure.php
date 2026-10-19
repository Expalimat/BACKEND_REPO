<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppointmentServiceClosure extends OessModel
{
    protected $table = 'appointment_service_closures';

    public function service(): BelongsTo
    {
        return $this->belongsTo(AppointmentService::class, 'serviceId', 'id');
    }
}
