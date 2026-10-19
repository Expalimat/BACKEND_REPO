<?php

namespace App\Support;

use App\Jobs\DeliverAppointmentNotification;
use App\Jobs\DeliverAppointmentRoleNotification;
use App\Models\Appointment;
use App\Models\AppointmentNotificationDelivery;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AppointmentNotificationDispatcher
{
    public static function queueRole(string $role, string $title, string $message): void
    {
        try {
            DeliverAppointmentRoleNotification::dispatch($role, $title, $message)->afterCommit();
        } catch (Throwable $exception) {
            Log::error('Unable to queue appointment staff notification.', [
                'role' => $role,
                'title' => $title,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    public static function queue(
        Appointment $appointment,
        string $notificationType,
        string $title,
        string $message,
    ): void {
        $delivery = null;
        try {
            $delivery = AppointmentNotificationDelivery::firstOrCreate(
                [
                    'appointmentId' => $appointment->id,
                    'notificationType' => $notificationType,
                ],
                [
                    'id' => (string) Str::uuid(),
                    'status' => 'QUEUED',
                    'queuedAt' => Oess::now(),
                ]
            );

            if ($delivery->status === 'SENT') {
                return;
            }
            if (! $delivery->wasRecentlyCreated && $delivery->status !== 'FAILED') {
                return;
            }
            if ($delivery->status === 'FAILED') {
                $delivery->status = 'QUEUED';
                $delivery->queuedAt = Oess::now();
                $delivery->failureMessage = null;
                $delivery->save();
            }

            DeliverAppointmentNotification::dispatch(
                $appointment->studentNumber,
                $title,
                $message,
                $delivery->id
            )->afterCommit();
        } catch (Throwable $exception) {
            Log::error('Unable to queue appointment inbox notification.', [
                'appointmentId' => $appointment->id,
                'notificationType' => $notificationType,
                'exception' => $exception->getMessage(),
            ]);
            if ($delivery) {
                try {
                    $delivery->status = 'FAILED';
                    $delivery->failureMessage = $exception->getMessage();
                    $delivery->save();
                } catch (Throwable $trackingException) {
                    Log::error('Unable to record appointment notification failure status.', [
                        'appointmentId' => $appointment->id,
                        'notificationType' => $notificationType,
                        'exception' => $trackingException->getMessage(),
                    ]);
                }
            }
        }
    }
}
