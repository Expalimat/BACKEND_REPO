<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppointmentQueueEntry extends OessModel
{
    protected $table = 'appointment_queue_entries';

    protected $casts = [
        'queueNumber' => 'integer',
    ];

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointmentId', 'id');
    }
}
