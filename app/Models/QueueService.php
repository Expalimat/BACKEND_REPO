<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class QueueService extends OessModel
{
    protected $table = 'queue_services';

    protected $casts = [
        'isActive' => 'boolean',
        'publicBookable' => 'boolean',
        'queueEnabled' => 'boolean',
        'queuePaused' => 'boolean',
        'queueCapacity' => 'integer',
        'serviceDurationMinutes' => 'integer',
    ];

    public function tickets(): HasMany
    {
        return $this->hasMany(QueueTicket::class, 'serviceId', 'id');
    }
}
