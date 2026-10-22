<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QueueTicket extends OessModel
{
    protected $table = 'queue_tickets';

    protected $casts = ['queueNumber' => 'integer'];

    public function service(): BelongsTo
    {
        return $this->belongsTo(QueueService::class, 'serviceId', 'id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'userId', 'id');
    }
}
