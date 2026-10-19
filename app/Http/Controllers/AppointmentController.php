<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\AppointmentQueueEntry;
use App\Models\AppointmentService;
use App\Models\AppointmentSlot;
use App\Support\Oess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AppointmentController extends Controller
{
    private const STAFF_ROLES = ['Registrar', 'Cashier', 'Department Staff', 'Administrator'];

    public function index(Request $request)
    {
        $q = Appointment::with(['service', 'slot', 'queueEntry'])->orderByDesc('seq');
        if ($request->user()->role === 'Student') {
            $q->where('studentNumber', $request->user()->studentNumber ?? '');
        }

        if ($request->filled('studentNumber')) {
            $q->where('studentNumber', $request->query('studentNumber'));
        }

        if ($request->filled('status')) {
            $q->where('status', $request->query('status'));
        }

        return Oess::paginateIfRequested(
            $q,
            $request,
            ['id', 'serviceId', 'slotId', 'studentNumber', 'studentName', 'status', 'referenceNumber']
        );
    }

    public function myAppointments(Request $request)
    {
        return Appointment::with(['service', 'slot', 'queueEntry'])
            ->where('studentNumber', $request->user()->studentNumber ?? '')
            ->orderByDesc('seq')
            ->get();
    }

    public function show(Request $request, string $id)
    {
        $appointment = Appointment::with(['service', 'slot', 'queueEntry'])->findOrFail($id);

        if ($request->user()->role === 'Student' && $appointment->studentNumber !== $request->user()->studentNumber) {
            abort(403, 'You do not have permission to view this appointment.');
        }

        return $appointment;
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user->role === 'Student', 403, 'Only students can book appointments.');
        abort_unless($user->studentNumber, 422, 'No student number is linked to this account.');

        $data = $request->validate([
            'serviceId' => ['required', 'string', 'exists:appointment_services,id'],
            'slotId' => ['required', 'string', 'exists:appointment_slots,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $service = AppointmentService::findOrFail($data['serviceId']);
        abort_unless($service->status === 'Active', 422, 'Selected service is not active.');

        $slot = AppointmentSlot::with('service')->findOrFail($data['slotId']);
        abort_unless($slot->serviceId === $data['serviceId'], 422, 'Selected slot does not belong to the chosen service.');
        abort_unless($slot->status === 'Open', 422, 'Selected slot is not available.');
        abort_if($slot->currentBookings >= $slot->maxCapacity, 422, 'This appointment slot is already full.');

        return DB::transaction(function () use ($request, $user, $service, $slot, $data) {
            $appointment = Appointment::create([
                'id' => Oess::randomId(Appointment::class, 'APT'),
                'serviceId' => $service->id,
                'slotId' => $slot->id,
                'studentNumber' => $user->studentNumber,
                'studentName' => $user->name,
                'referenceNumber' => $this->generateReferenceNumber(),
                'status' => 'CONFIRMED',
                'notes' => $data['notes'] ?? '',
                'rescheduledFromId' => null,
                'createdAt' => Oess::now(),
                'updatedAt' => Oess::now(),
            ]);

            $slot->currentBookings = (int) $slot->currentBookings + 1;
            if ($slot->currentBookings >= $slot->maxCapacity) {
                $slot->status = 'BookedOut';
            }
            $slot->updatedAt = Oess::now();
            $slot->save();

            $queueScope = 'service:' . $service->id;
            $queueEntry = AppointmentQueueEntry::create([
                'id' => Oess::randomId(AppointmentQueueEntry::class, 'Q'),
                'appointmentId' => $appointment->id,
                'queueNumber' => $this->nextQueueNumber($queueScope, $slot->appointmentDate),
                'queueDate' => $slot->appointmentDate,
                'queueScope' => $queueScope,
                'status' => 'WAITING',
                'checkInAt' => null,
                'calledAt' => null,
                'serviceStartAt' => null,
                'completionAt' => null,
                'createdAt' => Oess::now(),
                'updatedAt' => Oess::now(),
            ]);

            Oess::audit($user, 'Booked Appointment', 'Appointments',
                "Student {$user->studentNumber} booked {$service->serviceName} ({$appointment->referenceNumber})");
            Oess::notifyRole('Registrar', 'New Appointment Booking',
                "Student {$user->name} booked {$service->serviceName} on {$slot->appointmentDate}.", 'Appointments');

            return response()->json($appointment->fresh()->load(['service', 'slot', 'queueEntry']), 201);
        });
    }

    public function updateStatus(Request $request, string $id)
    {
        $appointment = Appointment::findOrFail($id);
        $user = $request->user();

        $allowed = $this->allowedStatusTransitions($appointment->status);
        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(array_keys($allowed))],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $nextStatus = $data['status'];
        if (! in_array($nextStatus, $allowed, true)) {
            abort(422, 'Invalid appointment status transition.');
        }

        if (! in_array($user->role, self::STAFF_ROLES, true) && $nextStatus !== $appointment->status) {
            abort(403, 'Only authorized staff can modify appointment status.');
        }

        $appointment->status = $nextStatus;
        if (! empty($data['remarks'])) {
            $appointment->notes = $data['remarks'];
        }
        $appointment->updatedAt = Oess::now();
        $appointment->save();

        Oess::audit($user, "Appointment {$nextStatus}", 'Appointments',
            "Appointment {$appointment->id} status changed to {$nextStatus} by {$user->name}");

        return $appointment->fresh()->load(['service', 'slot', 'queueEntry']);
    }

    public function cancel(Request $request, string $id)
    {
        $appointment = Appointment::findOrFail($id);
        $user = $request->user();

        if ($user->role === 'Student' && $appointment->studentNumber !== ($user->studentNumber ?? '')) {
            abort(403, 'You can only cancel your own appointment.');
        }

        if (! in_array($appointment->status, ['PENDING', 'CONFIRMED', 'CHECKED_IN', 'WAITING', 'SERVING'], true)) {
            abort(422, 'This appointment cannot be cancelled in its current status.');
        }

        $appointment->status = 'CANCELLED';
        $appointment->updatedAt = Oess::now();
        $appointment->save();

        if ($appointment->queueEntry) {
            $appointment->queueEntry->status = 'CANCELLED';
            $appointment->queueEntry->updatedAt = Oess::now();
            $appointment->queueEntry->save();
        }

        Oess::audit($user, 'Cancelled Appointment', 'Appointments',
            "Appointment {$appointment->id} cancelled by {$user->name}");

        return $appointment->fresh()->load(['service', 'slot', 'queueEntry']);
    }

    public function reschedule(Request $request, string $id)
    {
        $source = Appointment::with(['service', 'slot'])->findOrFail($id);
        $user = $request->user();

        if ($user->role === 'Student' && $source->studentNumber !== ($user->studentNumber ?? '')) {
            abort(403, 'You can only reschedule your own appointment.');
        }

        $data = $request->validate([
            'serviceId' => ['sometimes', 'required', 'string', 'exists:appointment_services,id'],
            'slotId' => ['required', 'string', 'exists:appointment_slots,id'],
        ]);

        $targetServiceId = $data['serviceId'] ?? $source->serviceId;
        $targetSlot = AppointmentSlot::with('service')->findOrFail($data['slotId']);
        abort_unless($targetSlot->serviceId === $targetServiceId, 422, 'Selected slot does not match the selected service.');
        abort_unless($targetSlot->status === 'Open', 422, 'Selected reschedule slot is not available.');

        $source->status = 'RESCHEDULED';
        $source->updatedAt = Oess::now();
        $source->save();

        $newAppointment = Appointment::create([
            'id' => Oess::randomId(Appointment::class, 'APT'),
            'serviceId' => $targetServiceId,
            'slotId' => $targetSlot->id,
            'studentNumber' => $source->studentNumber,
            'studentName' => $source->studentName,
            'referenceNumber' => $this->generateReferenceNumber(),
            'status' => 'CONFIRMED',
            'notes' => 'Rescheduled from ' . $source->id,
            'rescheduledFromId' => $source->id,
            'createdAt' => Oess::now(),
            'updatedAt' => Oess::now(),
        ]);

        $targetSlot->currentBookings = (int) $targetSlot->currentBookings + 1;
        if ($targetSlot->currentBookings >= $targetSlot->maxCapacity) {
            $targetSlot->status = 'BookedOut';
        }
        $targetSlot->updatedAt = Oess::now();
        $targetSlot->save();

        $queueScope = 'service:' . $targetServiceId;
        AppointmentQueueEntry::create([
            'id' => Oess::randomId(AppointmentQueueEntry::class, 'Q'),
            'appointmentId' => $newAppointment->id,
            'queueNumber' => $this->nextQueueNumber($queueScope, $targetSlot->appointmentDate),
            'queueDate' => $targetSlot->appointmentDate,
            'queueScope' => $queueScope,
            'status' => 'WAITING',
            'createdAt' => Oess::now(),
            'updatedAt' => Oess::now(),
        ]);

        Oess::audit($user, 'Rescheduled Appointment', 'Appointments',
            "Appointment {$source->id} rescheduled to {$newAppointment->id}");

        return $newAppointment->fresh()->load(['service', 'slot', 'queueEntry']);
    }

    protected function allowedStatusTransitions(string $current): array
    {
        return match ($current) {
            'PENDING' => ['CONFIRMED' => 'CONFIRMED', 'CANCELLED' => 'CANCELLED'],
            'CONFIRMED' => ['CHECKED_IN' => 'CHECKED_IN', 'WAITING' => 'WAITING', 'CANCELLED' => 'CANCELLED', 'RESCHEDULED' => 'RESCHEDULED'],
            'CHECKED_IN' => ['WAITING' => 'WAITING', 'SERVING' => 'SERVING', 'NO_SHOW' => 'NO_SHOW', 'CANCELLED' => 'CANCELLED'],
            'WAITING' => ['SERVING' => 'SERVING', 'NO_SHOW' => 'NO_SHOW', 'CANCELLED' => 'CANCELLED'],
            'SERVING' => ['COMPLETED' => 'COMPLETED', 'CANCELLED' => 'CANCELLED'],
            default => [],
        };
    }

    protected function generateReferenceNumber(): string
    {
        do {
            $date = now('Asia/Manila')->format('Ymd');
            $suffix = str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
            $candidate = "PNC-{$date}-{$suffix}";
        } while (Appointment::where('referenceNumber', $candidate)->exists());

        return $candidate;
    }

    protected function nextQueueNumber(string $queueScope, string $queueDate): int
    {
        $max = AppointmentQueueEntry::where('queueScope', $queueScope)
            ->where('queueDate', $queueDate)
            ->max('queueNumber');

        return (int) ($max ?? 0) + 1;
    }
}
