<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Models\User;
use App\Support\Oess;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class DeliverAppointmentNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public string $studentNumber,
        public string $title,
        public string $message,
        public ?string $deliveryId = null,
    ) {
        $this->afterCommit = true;
    }

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(): void
    {
        $user = User::where('studentNumber', $this->studentNumber)->first();
        if (! $user) {
            Log::warning('Appointment notification was not queued for a student account.', [
                'studentNumber' => $this->studentNumber,
                'title' => $this->title,
            ]);
            throw new RuntimeException('No active OESS user account is linked to the appointment student number.');
        }

        if ($this->deliveryId) {
            DB::transaction(function () use ($user) {
                $delivery = DB::table('appointment_notification_deliveries')
                    ->where('id', $this->deliveryId)
                    ->lockForUpdate()
                    ->first();
                if (! $delivery) {
                    throw new RuntimeException('Appointment notification delivery tracking record was not found.');
                }
                if ($delivery->status === 'SENT') {
                    return;
                }

                Notification::create([
                    'id' => 'NOTIF-APT-'.substr(hash('sha256', $this->deliveryId), 0, 32),
                    'userId' => $user->id,
                    'title' => $this->title,
                    'message' => $this->message,
                    'date' => Oess::now(),
                    'isRead' => false,
                    'relatedModule' => 'Appointments',
                ]);
                DB::table('appointment_notification_deliveries')->where('id', $this->deliveryId)->update([
                    'status' => 'SENT',
                    'deliveredAt' => Oess::now(),
                    'failureMessage' => null,
                ]);
            });

            return;
        }

        Oess::notifyStudent($this->studentNumber, $this->title, $this->message, 'Appointments');
    }

    public function failed(Throwable $exception): void
    {
        if ($this->deliveryId) {
            DB::table('appointment_notification_deliveries')->where('id', $this->deliveryId)->update([
                'status' => 'FAILED',
                'failureMessage' => $exception->getMessage(),
            ]);
        }
        Log::error('Appointment inbox notification delivery failed.', [
            'studentNumber' => $this->studentNumber,
            'title' => $this->title,
            'exception' => $exception->getMessage(),
        ]);
    }
}
