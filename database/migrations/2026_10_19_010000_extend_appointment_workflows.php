<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointment_services', function (Blueprint $table) {
            $table->text('instructions')->nullable();
            $table->json('requiredDocuments')->nullable();
            $table->unsignedSmallInteger('serviceDurationMinutes')->default(30);
            $table->unsignedSmallInteger('bookingWindowDays')->default(30);
            $table->unsignedSmallInteger('cancellationCutoffMinutes')->default(0);
            $table->unsignedSmallInteger('lateArrivalGraceMinutes')->default(0);
        });

        Schema::table('appointment_slots', function (Blueprint $table) {
            $table->string('updatedAt')->nullable();
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->string('serviceNameSnapshot')->nullable();
            $table->time('startTimeSnapshot')->nullable();
            $table->time('endTimeSnapshot')->nullable();
            $table->unsignedSmallInteger('serviceDurationMinutes')->default(30);
            $table->string('rescheduledFromId')->nullable()->index();
        });

        Schema::create('appointment_queue_entries', function (Blueprint $table) {
            $table->bigIncrements('seq');
            $table->string('id')->unique();
            $table->string('appointmentId')->unique();
            $table->string('serviceId');
            $table->date('queueDate');
            $table->unsignedInteger('queueNumber');
            $table->string('status');
            $table->string('checkedInAt')->nullable();
            $table->string('calledAt')->nullable();
            $table->string('serviceStartedAt')->nullable();
            $table->string('completedAt')->nullable();
            $table->string('createdAt');
            $table->string('updatedAt');
            $table->foreign('appointmentId')->references('id')->on('appointments')->cascadeOnDelete();
            $table->foreign('serviceId')->references('id')->on('appointment_services')->restrictOnDelete();
            $table->unique(['serviceId', 'queueDate', 'queueNumber']);
            $table->index(['serviceId', 'queueDate', 'status', 'queueNumber']);
        });

        Schema::create('appointment_status_history', function (Blueprint $table) {
            $table->bigIncrements('seq');
            $table->string('id')->unique();
            $table->string('appointmentId')->index();
            $table->string('queueEntryId')->nullable()->index();
            $table->string('previousStatus')->nullable();
            $table->string('newStatus')->nullable();
            $table->string('eventType');
            $table->string('actorUserId')->nullable();
            $table->string('occurredAt');
            $table->text('reason')->nullable();
            $table->foreign('appointmentId')->references('id')->on('appointments')->cascadeOnDelete();
            $table->foreign('queueEntryId')->references('id')->on('appointment_queue_entries')->nullOnDelete();
        });

        Schema::create('appointment_service_closures', function (Blueprint $table) {
            $table->bigIncrements('seq');
            $table->string('id')->unique();
            $table->string('serviceId');
            $table->date('closedDate');
            $table->string('reason')->nullable();
            $table->string('createdAt');
            $table->string('updatedAt');
            $table->foreign('serviceId')->references('id')->on('appointment_services')->cascadeOnDelete();
            $table->unique(['serviceId', 'closedDate']);
        });

        $nextQueueNumbers = [];
        foreach (DB::table('appointments')->orderBy('seq')->get() as $appointment) {
            $slot = DB::table('appointment_slots')->where('id', $appointment->slotId)->first();
            $service = DB::table('appointment_services')->where('id', $appointment->serviceId)->first();
            $status = match ($appointment->status) {
                'Confirmed' => 'CONFIRMED',
                'Checked In', 'Called' => 'WAITING',
                'Completed' => 'COMPLETED',
                'Cancelled' => 'CANCELLED',
                'No Show' => 'NO_SHOW',
                default => strtoupper((string) $appointment->status),
            };

            DB::table('appointments')->where('id', $appointment->id)->update([
                'status' => $status,
                'serviceNameSnapshot' => $service?->name ?? '',
                'startTimeSnapshot' => $slot?->startTime,
                'endTimeSnapshot' => $slot?->endTime,
                'serviceDurationMinutes' => $service?->serviceDurationMinutes ?? 30,
            ]);

            if (! $slot || ! $service || ! in_array($appointment->status, ['Checked In', 'Called', 'Completed', 'No Show'], true)) {
                continue;
            }

            $scope = $appointment->serviceId.'|'.$appointment->appointmentDate;
            $nextQueueNumbers[$scope] ??= (int) DB::table('appointment_queue_entries')
                ->where('serviceId', $appointment->serviceId)
                ->where('queueDate', $appointment->appointmentDate)
                ->max('queueNumber');
            $queueNumber = $appointment->queueNumber ?: ++$nextQueueNumbers[$scope];
            $nextQueueNumbers[$scope] = max($nextQueueNumbers[$scope], $queueNumber);
            $queueStatus = match ($appointment->status) {
                'Called' => 'CALLED',
                'Completed' => 'COMPLETED',
                'No Show' => 'NO_SHOW',
                default => 'WAITING',
            };
            $queueEntryId = (string) Str::uuid();
            $now = now('Asia/Manila')->format('Y-m-d h:i A');

            DB::table('appointment_queue_entries')->insert([
                'id' => $queueEntryId,
                'appointmentId' => $appointment->id,
                'serviceId' => $appointment->serviceId,
                'queueDate' => $appointment->appointmentDate,
                'queueNumber' => $queueNumber,
                'status' => $queueStatus,
                'checkedInAt' => $appointment->checkedInAt,
                'calledAt' => $appointment->calledAt,
                'serviceStartedAt' => null,
                'completedAt' => $appointment->completedAt,
                'createdAt' => $appointment->createdAt,
                'updatedAt' => $now,
            ]);
            DB::table('appointments')->where('id', $appointment->id)->update([
                'queueNumber' => $queueNumber,
                'queueTicket' => $service->code.'-'.str_pad((string) $queueNumber, 3, '0', STR_PAD_LEFT),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_service_closures');
        Schema::dropIfExists('appointment_status_history');
        Schema::dropIfExists('appointment_queue_entries');

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['rescheduledFromId']);
            $table->dropColumn([
                'serviceNameSnapshot',
                'startTimeSnapshot',
                'endTimeSnapshot',
                'serviceDurationMinutes',
                'rescheduledFromId',
            ]);
        });
        Schema::table('appointment_slots', function (Blueprint $table) {
            $table->dropColumn('updatedAt');
        });
        Schema::table('appointment_services', function (Blueprint $table) {
            $table->dropColumn([
                'instructions',
                'requiredDocuments',
                'serviceDurationMinutes',
                'bookingWindowDays',
                'cancellationCutoffMinutes',
                'lateArrivalGraceMinutes',
            ]);
        });
    }
};
