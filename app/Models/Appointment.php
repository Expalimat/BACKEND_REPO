<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Appointment extends OessModel
{
    protected $casts = [
        'queueNumber' => 'integer',
        'applicantEmail' => 'string',
        'applicantPhone' => 'string',
    ];

    public function queueEntry(): HasOne
    {
        return $this->hasOne(AppointmentQueueEntry::class, 'appointmentId', 'id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(AppointmentService::class, 'serviceId', 'id');
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(AppointmentSlot::class, 'slotId', 'id');
    }

    public function documentRequest(): BelongsTo
    {
        return $this->belongsTo(DocumentRequest::class, 'documentRequestId', 'id');
    }
}
