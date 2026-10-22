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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'userId', 'id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(AppointmentService::class, 'serviceId', 'id');
    }
}
