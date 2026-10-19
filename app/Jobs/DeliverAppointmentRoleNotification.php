<?php

namespace App\Jobs;

use App\Models\User;
use App\Support\Oess;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class DeliverAppointmentRoleNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public string $role,
        public string $title,
        public string $message,
    ) {
        $this->afterCommit = true;
    }

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(): void
    {
        if (! User::where('role', $this->role)->where('status', 'Active')->exists()) {
            Log::warning('Appointment staff notification has no active recipients.', [
                'role' => $this->role,
                'title' => $this->title,
            ]);

            return;
        }

        Oess::notifyRole($this->role, $this->title, $this->message, 'Appointments');
    }
}
