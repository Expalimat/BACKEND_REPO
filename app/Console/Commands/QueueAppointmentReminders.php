<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Support\AppointmentNotificationDispatcher;
use Illuminate\Console\Command;

class QueueAppointmentReminders extends Command
{
    protected $signature = 'appointments:queue-reminders';

    protected $description = 'Queue in-app reminders for appointments scheduled for tomorrow.';

    public function handle(): int
    {
        $tomorrow = now('Asia/Manila')->addDay()->toDateString();
        $count = 0;

        Appointment::where('appointmentDate', $tomorrow)
            ->where('status', 'CONFIRMED')
            ->orderBy('seq')
            ->chunk(100, function ($appointments) use (&$count) {
                foreach ($appointments as $appointment) {
                    AppointmentNotificationDispatcher::queue(
                        $appointment,
                        'REMINDER_1_DAY',
                        'Appointment Reminder',
                        "Reminder: {$appointment->appointmentNumber} for {$appointment->serviceNameSnapshot} is scheduled for {$appointment->appointmentDate} at ".substr($appointment->startTimeSnapshot, 0, 5).'.'
                    );
                    $count++;
                }
            });

        $this->info("Queued {$count} appointment reminder(s).");

        return self::SUCCESS;
    }
}
