<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\AppointmentQueueEntry;
use App\Models\AppointmentService;
use App\Models\AppointmentSlot;
use App\Models\AppointmentStatusHistory;
use App\Models\DocumentRequest;
use App\Models\User;
use App\Support\AppointmentAccess;
use App\Support\AppointmentNotificationDispatcher;
use App\Support\AppointmentWaitEstimator;
use App\Support\Oess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AppointmentController extends Controller
{
    public function __construct(private readonly AppointmentWaitEstimator $waitEstimator)
    {
    }

    private const CAPACITY_STATUSES = ['CONFIRMED', 'WAITING', 'SERVING', 'COMPLETED'];

    private const ACTIVE_QUEUE_STATUSES = ['WAITING', 'CALLED', 'SERVING'];

    public function display()
    {
        $today = now('Asia/Manila')->toDateString();
        $services = AppointmentService::where('isActive', true)
            ->orWhereIn('id', AppointmentQueueEntry::where('queueDate', $today)
                ->whereIn('status', self::ACTIVE_QUEUE_STATUSES)
                ->select('serviceId'))
            ->orderBy('name')
            ->get();

        return response()->json([
            'services' => $services->map(function (AppointmentService $service) use ($today) {
                $current = AppointmentQueueEntry::where('serviceId', $service->id)
                    ->where('queueDate', $today)
                    ->whereIn('status', ['CALLED', 'SERVING'])
                    ->orderBy('calledAt')
                    ->first();
                $upcomingBookedSlots = AppointmentSlot::where('serviceId', $service->id)
                    ->where(function ($query) use ($today) {
                        $query->where('appointmentDate', '>', $today)
                            ->orWhere(function ($todayQuery) use ($today) {
                                $todayQuery->where('appointmentDate', $today)
                                    ->where('startTime', '>=', now('Asia/Manila')->format('H:i:s'));
                            });
                    })
                    ->whereHas('appointments', fn ($query) => $query->whereIn('status', self::CAPACITY_STATUSES))
                    ->withCount(['appointments as bookedCount' => fn ($query) => $query->whereIn('status', self::CAPACITY_STATUSES)])
                    ->orderBy('appointmentDate')
                    ->orderBy('startTime')
                    ->get();

                return [
                    'serviceCode' => $service->code,
                    'serviceName' => $service->name,
                    'nowServing' => $current
                        ? $service->code.'-'.str_pad((string) $current->queueNumber, 3, '0', STR_PAD_LEFT)
                        : null,
                    'waitingCount' => AppointmentQueueEntry::where('serviceId', $service->id)
                        ->where('queueDate', $today)
                        ->where('status', 'WAITING')
                        ->count(),
                    'upcomingBookedSlots' => $upcomingBookedSlots->map(fn (AppointmentSlot $slot) => [
                        'appointmentDate' => $slot->appointmentDate,
                        'startTime' => substr($slot->startTime, 0, 5),
                        'bookedCount' => $slot->bookedCount,
                        'capacity' => $slot->capacity,
                        'remainingCount' => max(0, $slot->capacity - $slot->bookedCount),
                    ])->values(),
                    'averageServiceDurationMinutes' => app(AppointmentWaitEstimator::class)
                        ->averageDuration($service->id),
                ];
            }),
            'updatedAt' => Oess::now(),
        ]);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $query = AppointmentAccess::appointments($user)->orderByDesc('appointmentDate')->orderByDesc('seq');
        if ($user->role !== 'Student') {
            $request->merge([
                'date' => $request->input('appointment_date', $request->input('date')),
                'serviceId' => $request->input('service_id', $request->input('serviceId')),
                'referenceNumber' => $request->input('reference_number'),
            ]);
            $data = $request->validate([
                'date' => 'nullable|date_format:Y-m-d',
                'status' => 'nullable|in:PENDING,CONFIRMED,CHECKED_IN,WAITING,SERVING,COMPLETED,CANCELLED,NO_SHOW,RESCHEDULED',
                'serviceId' => 'nullable|string|exists:appointment_services,id',
                'referenceNumber' => 'nullable|string|max:100',
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:1|max:100',
            ]);
            if (! empty($data['date'])) {
                $query->where('appointmentDate', $data['date']);
            }
            if (! empty($data['status'])) {
                $query->where('status', $data['status']);
            }
            if (! empty($data['serviceId'])) {
                AppointmentAccess::assertServiceAccess($user, $data['serviceId']);
                $query->where('serviceId', $data['serviceId']);
            }
            if (! empty($data['referenceNumber'])) {
                $query->where('appointmentNumber', $data['referenceNumber']);
            }
        }

        $payload = fn (Appointment $appointment) => $user->role === 'Student'
            ? $this->payloadWithQueueMetrics($appointment)
            : $this->payload($appointment);

        if ($user->role !== 'Student' &&
            ($request->boolean('paginate') || $request->filled('page') || $request->filled('per_page'))) {
            $appointments = $query->paginate((int) $request->input('per_page', 50));
            $appointments->setCollection($appointments->getCollection()->map($payload));

            return response()->json($appointments);
        }

        return $query->get()->map($payload);
    }

    public function virtualQueueServices(Request $request)
    {
        $user = $request->user();
        $this->ensureStudentAccount($user->studentNumber);
        $today = now('Asia/Manila')->toDateString();

        return AppointmentService::where('isActive', true)
            ->where('queueEnabled', true)
            ->where('code', '!=', 'FRESHMAN-ENROLLMENT')
            ->orderBy('name')
            ->get()
            ->map(function (AppointmentService $service) use ($today) {
                $activeCount = AppointmentQueueEntry::where('serviceId', $service->id)
                    ->where('queueDate', $today)
                    ->whereIn('status', self::ACTIVE_QUEUE_STATUSES)
                    ->count();
                $issuedCount = AppointmentQueueEntry::where('serviceId', $service->id)
                    ->where('queueDate', $today)
                    ->whereNull('appointmentId')
                    ->count();

                return [
                    'id' => $service->id,
                    'code' => $service->code,
                    'name' => $service->name,
                    'instructions' => $service->instructions,
                    'queuePaused' => $service->queuePaused,
                    'queueCapacity' => $service->queueCapacity,
                    'activeCount' => $activeCount,
                    'issuedCount' => $issuedCount,
                    'remainingCount' => max(0, $service->queueCapacity - $issuedCount),
                    'acceptingTickets' => ! $service->queuePaused && $issuedCount < $service->queueCapacity,
                ];
            });
    }

    public function myVirtualQueueTickets(Request $request)
    {
        $user = $request->user();
        $this->ensureStudentAccount($user->studentNumber);

        return AppointmentQueueEntry::with(['service', 'user'])
            ->where('userId', $user->id)
            ->where('queueDate', now('Asia/Manila')->toDateString())
            ->whereIn('status', self::ACTIVE_QUEUE_STATUSES)
            ->orderBy('queueNumber')
            ->get()
            ->map(fn (AppointmentQueueEntry $entry) => $this->queueEntryPayload($entry));
    }

    public function joinVirtualQueue(Request $request)
    {
        $user = $request->user();
        $this->ensureStudentAccount($user->studentNumber);
        $data = $request->validate([
            'serviceId' => 'required|string|exists:appointment_services,id',
        ]);
        $today = now('Asia/Manila')->toDateString();

        $entry = DB::transaction(function () use ($data, $today, $user) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $service = AppointmentService::whereKey($data['serviceId'])->lockForUpdate()->firstOrFail();
            if (! $service->isActive || ! $service->queueEnabled || $service->code === 'FRESHMAN-ENROLLMENT') {
                abort(422, 'Virtual queue tickets are not available for this service.');
            }
            if ($service->queuePaused) {
                abort(409, 'This virtual queue is temporarily paused. Please try again later.');
            }

            $this->lockQueueSequence($service->id, $today);
            if (AppointmentQueueEntry::where('queueDate', $today)
                ->whereIn('status', self::ACTIVE_QUEUE_STATUSES)
                ->where(function ($query) use ($user) {
                    $query->where('userId', $user->id)
                        ->orWhereHas('appointment', fn ($appointments) => $appointments->where('studentNumber', $user->studentNumber));
                })
                ->exists()) {
                abort(409, 'You already have an active queue ticket today.');
            }

            $issuedCount = AppointmentQueueEntry::where('serviceId', $service->id)
                ->where('queueDate', $today)
                ->whereNull('appointmentId')
                ->count();
            if ($issuedCount >= $service->queueCapacity) {
                abort(409, 'This virtual queue has reached its daily capacity.');
            }

            $sequence = DB::table('appointment_queue_sequences')
                ->where('serviceId', $service->id)
                ->where('appointmentDate', $today)
                ->lockForUpdate()
                ->first();
            $queueNumber = (int) $sequence->lastNumber + 1;
            DB::table('appointment_queue_sequences')
                ->where('serviceId', $service->id)
                ->where('appointmentDate', $today)
                ->update(['lastNumber' => $queueNumber]);

            $now = Oess::now();

            return AppointmentQueueEntry::create([
                'id' => (string) Str::uuid(),
                'appointmentId' => null,
                'userId' => $user->id,
                'serviceId' => $service->id,
                'queueDate' => $today,
                'queueNumber' => $queueNumber,
                'status' => 'WAITING',
                'checkedInAt' => $now,
                'createdAt' => $now,
                'updatedAt' => $now,
            ])->load(['service', 'user']);
        }, 5);

        Oess::audit($user, 'Joined Virtual Queue', 'Appointments',
            "Joined {$entry->service->code} virtual queue with ticket {$entry->service->code}-".str_pad((string) $entry->queueNumber, 3, '0', STR_PAD_LEFT));

        return response()->json($this->queueEntryPayload($entry), 201);
    }

    public function cancelVirtualQueueTicket(Request $request, string $queueEntryId)
    {
        $user = $request->user();
        $entry = DB::transaction(function () use ($queueEntryId, $user) {
            $entry = AppointmentQueueEntry::with(['service', 'user'])
                ->where('userId', $user->id)
                ->whereKey($queueEntryId)
                ->lockForUpdate()
                ->firstOrFail();
            if ($entry->queueDate !== now('Asia/Manila')->toDateString() || $entry->status !== 'WAITING') {
                abort(409, 'Only your waiting ticket for today can be cancelled.');
            }
            $entry->status = 'CANCELLED';
            $entry->updatedAt = Oess::now();
            $entry->save();

            return $entry;
        }, 5);

        Oess::audit($user, 'Cancelled Virtual Queue Ticket', 'Appointments',
            "Cancelled {$entry->service->code}-".str_pad((string) $entry->queueNumber, 3, '0', STR_PAD_LEFT));

        return response()->json($this->queueEntryPayload($entry));
    }

    public function staffAppointments(Request $request)
    {
        $request->merge(['paginate' => true]);

        return $this->index($request);
    }

    public function show(Request $request, string $id)
    {
        $user = $request->user();
        $appointment = AppointmentAccess::appointments($user)->whereKey($id)->firstOrFail();

        return response()->json($user->role === 'Student'
            ? $this->payloadWithQueueMetrics($appointment)
            : $this->payload($appointment));
    }

    public function history(Request $request, string $id)
    {
        $appointment = AppointmentAccess::appointments($request->user())->whereKey($id)->firstOrFail();

        return AppointmentStatusHistory::where('appointmentId', $appointment->id)
            ->orderBy('seq')
            ->get(['id', 'appointmentId', 'queueEntryId', 'previousStatus', 'newStatus', 'eventType', 'actorUserId', 'occurredAt', 'reason']);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $this->ensureStudentAccount($user->studentNumber);
        $request->merge([
            'slotId' => $request->input('appointment_slot_id', $request->input('slotId')),
        ]);
        $data = $request->validate([
            'slotId' => 'required|string|exists:appointment_slots,id',
            'purpose' => 'nullable|string|max:2000',
            'documentRequestId' => 'nullable|string|exists:document_requests,id',
        ]);

        $appointment = DB::transaction(function () use ($user, $data) {
            $slot = $this->lockSlot($data['slotId']);
            $service = AppointmentService::whereKey($slot->serviceId)->lockForUpdate()->firstOrFail();
            $this->assertBookableSlot($slot, $service);
            $this->assertDocumentRequest($data['documentRequestId'] ?? null, $user->studentNumber);
            $this->assertNoDuplicateBooking($slot, $user->studentNumber);
            $this->assertCapacity($slot);

            return $this->createAppointment($user, $slot, $service, $data);
        }, 5);

        Oess::audit($user, 'Booked Appointment', 'Appointments',
            "Booked appointment {$appointment->appointmentNumber} for {$appointment->serviceNameSnapshot} on {$appointment->appointmentDate} at {$appointment->startTimeSnapshot}");
        $this->notifyStudent($appointment, 'CONFIRMED', 'Appointment Confirmed',
            "Appointment {$appointment->appointmentNumber} for {$appointment->serviceNameSnapshot} is confirmed for {$appointment->appointmentDate} at ".substr($appointment->startTimeSnapshot, 0, 5).'.');
        AppointmentNotificationDispatcher::queueRole('Registrar', 'New Appointment',
            "{$appointment->studentName} booked {$appointment->serviceNameSnapshot} for {$appointment->appointmentDate} at ".substr($appointment->startTimeSnapshot, 0, 5).'.');

        return response()->json($this->payload($appointment), 201);
    }

    public function cancel(Request $request, string $id)
    {
        $user = $request->user();
        $staff = $user->role !== 'Student';
        $data = $request->validate([
            'reason' => $staff ? 'required|string|max:1000' : 'nullable|string|max:1000',
        ]);

        $appointment = DB::transaction(function () use ($id, $user, $staff, $data) {
            $appointment = AppointmentAccess::appointments($user)->whereKey($id)->lockForUpdate()->firstOrFail();

            if (! in_array($appointment->status, ['CONFIRMED', 'WAITING'], true)) {
                abort(409, 'Only confirmed or waiting appointments can be cancelled.');
            }
            if (! $staff && ! $this->cancellationIsPermitted($appointment)) {
                abort(409, 'The cancellation window for this appointment has passed.');
            }
            if ($appointment->status === 'WAITING' && ! $staff) {
                abort(409, 'Only staff can cancel an appointment after check-in.');
            }

            $previousStatus = $appointment->status;
            $appointment->status = 'CANCELLED';
            $appointment->updatedAt = Oess::now();
            $appointment->save();
            $queueEntry = AppointmentQueueEntry::where('appointmentId', $appointment->id)
                ->lockForUpdate()
                ->first();
            if ($queueEntry && in_array($queueEntry->status, ['WAITING', 'CALLED'], true)) {
                $queueEntry->status = 'CANCELLED';
                $queueEntry->updatedAt = Oess::now();
                $queueEntry->save();
            }
            $this->recordEvent($appointment, $previousStatus, 'CANCELLED', 'appointment_cancelled', $user->id, $data['reason'] ?? null, $queueEntry?->id);

            return $appointment;
        }, 5);

        Oess::audit($user, 'Cancelled Appointment', 'Appointments',
            "Cancelled appointment {$appointment->appointmentNumber}".(! empty($data['reason']) ? ": {$data['reason']}" : ''));
        $this->notifyStudent($appointment, 'CANCELLED', 'Appointment Cancelled',
            "Appointment {$appointment->appointmentNumber} has been cancelled.");
        if (! $staff) {
            AppointmentNotificationDispatcher::queueRole('Registrar', 'Appointment Cancelled',
                "{$appointment->appointmentNumber} for {$appointment->serviceNameSnapshot} was cancelled by the student.");
        }

        return response()->json($this->payload($appointment));
    }

    public function reschedule(Request $request, string $id)
    {
        $user = $request->user();
        $this->ensureStudentAccount($user->studentNumber);
        $request->merge([
            'slotId' => $request->input('appointment_slot_id', $request->input('slotId')),
        ]);
        $data = $request->validate([
            'slotId' => 'required|string|exists:appointment_slots,id',
        ]);

        [$old, $new] = DB::transaction(function () use ($id, $data, $user) {
            $slot = $this->lockSlot($data['slotId']);
            $old = Appointment::whereKey($id)
                ->where('studentNumber', $user->studentNumber)
                ->lockForUpdate()
                ->firstOrFail();
            if ($old->status !== 'CONFIRMED' || ! $this->cancellationIsPermitted($old)) {
                abort(409, 'Only eligible upcoming confirmed appointments can be rescheduled.');
            }
            if ($old->slotId === $data['slotId']) {
                abort(422, 'Choose a different appointment time.');
            }

            if ($slot->serviceId !== $old->serviceId) {
                abort(422, 'Choose a new time for the same appointment service.');
            }
            $service = AppointmentService::whereKey($slot->serviceId)->lockForUpdate()->firstOrFail();
            $this->assertBookableSlot($slot, $service);
            $this->assertNoDuplicateBooking($slot, $user->studentNumber);
            $this->assertCapacity($slot);

            $previousStatus = $old->status;
            $old->status = 'RESCHEDULED';
            $old->updatedAt = Oess::now();
            $old->save();
            $this->recordEvent($old, $previousStatus, 'RESCHEDULED', 'appointment_rescheduled', $user->id);

            $new = $this->createAppointment($user, $slot, $service, [
                'purpose' => $old->purpose,
                'documentRequestId' => $old->documentRequestId,
                'rescheduledFromId' => $old->id,
            ]);
            $old->rescheduledToId = $new->id;
            $old->save();

            return [$old, $new];
        }, 5);

        Oess::audit($user, 'Rescheduled Appointment', 'Appointments',
            "Rescheduled {$old->appointmentNumber} to {$new->appointmentNumber}");
        $this->notifyStudent($old, 'RESCHEDULED', 'Appointment Rescheduled',
            "Appointment {$old->appointmentNumber} was rescheduled to {$new->appointmentNumber} on {$new->appointmentDate} at ".substr($new->startTimeSnapshot, 0, 5).'.');

        return response()->json([
            'previousAppointment' => $this->payload($old),
            'appointment' => $this->payload($new),
        ], 201);
    }

    public function checkIn(Request $request, string $id)
    {
        $user = $request->user();
        $staff = $user->role !== 'Student';
        $data = $request->validate([
            'overrideReason' => $staff ? 'nullable|string|max:1000' : 'prohibited',
        ]);

        $appointment = DB::transaction(function () use ($id, $user, $staff, $data) {
            $appointment = AppointmentAccess::appointments($user)->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($appointment->status !== 'CONFIRMED') {
                abort(409, 'Only confirmed appointments can be checked in.');
            }
            if ($appointment->appointmentDate !== now('Asia/Manila')->toDateString()) {
                abort(409, 'Appointments can only be checked in on their scheduled date.');
            }

            $slotStart = Carbon::parse($appointment->appointmentDate.' '.$appointment->startTimeSnapshot, 'Asia/Manila')
                ->subMinutes($appointment->earlyArrivalWindowMinutes);
            $slotEnd = Carbon::parse($appointment->appointmentDate.' '.$appointment->endTimeSnapshot, 'Asia/Manila')
                ->addMinutes($appointment->lateArrivalGraceMinutes);
            $overrideReason = $data['overrideReason'] ?? null;
            if ((now('Asia/Manila')->greaterThan($slotEnd) || now('Asia/Manila')->lessThan($slotStart)) &&
                (! $staff || ! $overrideReason)) {
                abort(409, 'This check-in is outside the configured arrival window. Authorized staff may override with a reason.');
            }

            $this->lockQueueSequence($appointment->serviceId, $appointment->appointmentDate);
            $sequence = DB::table('appointment_queue_sequences')
                ->where('serviceId', $appointment->serviceId)
                ->where('appointmentDate', $appointment->appointmentDate)
                ->lockForUpdate()
                ->first();
            $queueNumber = $sequence->lastNumber + 1;
            DB::table('appointment_queue_sequences')->where('id', $sequence->id)->update(['lastNumber' => $queueNumber]);

            $now = Oess::now();
            $queueEntry = AppointmentQueueEntry::create([
                'id' => (string) Str::uuid(),
                'appointmentId' => $appointment->id,
                'serviceId' => $appointment->serviceId,
                'queueDate' => $appointment->appointmentDate,
                'queueNumber' => $queueNumber,
                'status' => 'WAITING',
                'checkedInAt' => $now,
                'createdAt' => $now,
                'updatedAt' => $now,
            ]);
            $previousStatus = $appointment->status;
            $appointment->status = 'WAITING';
            $appointment->queueNumber = $queueNumber;
            $appointment->queueTicket = $appointment->service->code.'-'.str_pad((string) $queueNumber, 3, '0', STR_PAD_LEFT);
            $appointment->checkedInAt = $now;
            $appointment->status = 'CHECKED_IN';
            $appointment->updatedAt = $now;
            $appointment->save();
            $this->recordEvent($appointment, $previousStatus, 'CHECKED_IN', 'appointment_checked_in', $user->id, $overrideReason, $queueEntry->id);
            $appointment->status = 'WAITING';
            $appointment->save();
            $this->recordEvent($appointment, 'CHECKED_IN', 'WAITING', 'queue_entry_assigned', $user->id, $overrideReason, $queueEntry->id);

            if ($overrideReason) {
                $this->recordEvent($appointment, 'CONFIRMED', 'WAITING', 'staff_override', $user->id, $overrideReason, $queueEntry->id);
                Oess::audit($user, 'Appointment Check-in Override', 'Appointments',
                    "Checked in {$appointment->appointmentNumber} after the check-in window: {$overrideReason}");
            }

            return $appointment;
        }, 5);

        return response()->json($this->payload($appointment));
    }

    public function callNext(Request $request, string $serviceId)
    {
        AppointmentAccess::assertServiceAccess($request->user(), $serviceId);
        $today = now('Asia/Manila')->toDateString();
        [$queueEntry, $appointment] = DB::transaction(function () use ($serviceId, $today, $request) {
            $this->lockQueueSequence($serviceId, $today);
            AppointmentAccess::services($request->user())->whereKey($serviceId)->firstOrFail();
            $current = AppointmentQueueEntry::where('serviceId', $serviceId)
                ->where('queueDate', $today)
                ->whereIn('status', ['CALLED', 'SERVING'])
                ->lockForUpdate()
                ->first();
            if ($current) {
                abort(409, 'Complete or mark the current service as no-show before calling the next student.');
            }

            $queueEntry = AppointmentQueueEntry::where('serviceId', $serviceId)
                ->where('queueDate', $today)
                ->where('status', 'WAITING')
                ->orderBy('queueNumber')
                ->lockForUpdate()
                ->first();
            if (! $queueEntry) {
                abort(404, 'There are no queue tickets waiting.');
            }

            $queueEntry->status = 'CALLED';
            $queueEntry->calledAt = Oess::now();
            $queueEntry->updatedAt = Oess::now();
            $queueEntry->save();
            $appointment = null;
            if ($queueEntry->appointmentId) {
                $appointment = AppointmentAccess::appointments($request->user())
                    ->whereKey($queueEntry->appointmentId)
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->recordEvent($appointment, $appointment->status, $appointment->status, 'queue_student_called', $request->user()->id, null, $queueEntry->id);
            }

            return [$queueEntry->load(['service', 'user', 'appointment']), $appointment];
        }, 5);

        if ($appointment) {
            $this->notifyStudent($appointment, 'CALLED', 'Your Appointment Is Being Served',
                "Please proceed. Queue ticket {$appointment->queueTicket} is now being called.");
        }

        return response()->json($this->queueEntryPayload($queueEntry));
    }

    public function overrideCall(Request $request, string $serviceId)
    {
        AppointmentAccess::assertServiceAccess($request->user(), $serviceId);
        $data = $request->validate([
            'appointmentId' => 'required|string|exists:appointments,id',
            'reason' => 'required|string|max:1000',
        ]);
        $user = $request->user();
        $today = now('Asia/Manila')->toDateString();

        $appointment = DB::transaction(function () use ($data, $serviceId, $today, $user) {
            $this->lockQueueSequence($serviceId, $today);
            $current = AppointmentQueueEntry::where('serviceId', $serviceId)
                ->where('queueDate', $today)
                ->whereIn('status', ['CALLED', 'SERVING'])
                ->lockForUpdate()
                ->first();
            if ($current) {
                abort(409, 'Complete or mark the current service as no-show before overriding the queue.');
            }
            $queueEntry = AppointmentQueueEntry::where('appointmentId', $data['appointmentId'])
                ->where('serviceId', $serviceId)
                ->where('queueDate', $today)
                ->where('status', 'WAITING')
                ->lockForUpdate()
                ->firstOrFail();
            $queueEntry->status = 'CALLED';
            $queueEntry->calledAt = Oess::now();
            $queueEntry->updatedAt = Oess::now();
            $queueEntry->save();
            $appointment = AppointmentAccess::appointments($user)
                ->whereKey($queueEntry->appointmentId)
                ->lockForUpdate()
                ->firstOrFail();
            $this->recordEvent($appointment, $appointment->status, $appointment->status, 'staff_override_call', $user->id, $data['reason'], $queueEntry->id);

            return $appointment;
        }, 5);

        Oess::audit($user, 'Appointment Queue Override', 'Appointments',
            "Called {$appointment->appointmentNumber} out of queue order: {$data['reason']}");
        $this->notifyStudent($appointment, 'OVERRIDE_CALLED', 'Your Appointment Is Being Served',
            "Please proceed. Queue ticket {$appointment->queueTicket} is now being called.");

        return response()->json($this->payload($appointment));
    }

    public function queue(Request $request, string $serviceId)
    {
        $service = AppointmentAccess::services($request->user())->whereKey($serviceId)->firstOrFail();
        $today = now('Asia/Manila')->toDateString();
        $entries = AppointmentQueueEntry::where('serviceId', $service->id)
            ->where('queueDate', $today)
            ->whereIn('status', self::ACTIVE_QUEUE_STATUSES)
            ->orderByRaw('CASE WHEN status = ? THEN 0 WHEN status = ? THEN 1 ELSE 2 END', ['SERVING', 'CALLED'])
            ->orderBy('queueNumber')
            ->get();
        $waitingPayload = $entries->where('status', 'WAITING')->values()->map(function (AppointmentQueueEntry $entry) {
            $payload = $this->queueEntryPayload($entry);
            $metrics = $this->waitEstimator->estimate($entry);
            return array_merge($payload, $metrics);
        });
        $activePayload = $entries->reject(fn (AppointmentQueueEntry $entry) => $entry->status === 'WAITING')
            ->map(fn (AppointmentQueueEntry $entry) => $this->queueEntryPayload($entry));
        $confirmedPayload = Appointment::where('serviceId', $service->id)
            ->where('appointmentDate', $today)
            ->where('status', 'CONFIRMED')
            ->orderBy('startTimeSnapshot')
            ->get()
            ->map(fn (Appointment $appointment) => $this->payload($appointment));

        return response()->json([
            'service' => ['id' => $service->id, 'code' => $service->code, 'name' => $service->name],
            'appointments' => $activePayload->concat($waitingPayload)->concat($confirmedPayload)->values(),
            'updatedAt' => Oess::now(),
        ]);
    }

    public function staffQueue(Request $request)
    {
        $request->merge([
            'serviceId' => $request->input('service_id'),
            'date' => $request->input('queue_date', now('Asia/Manila')->toDateString()),
        ]);
        $data = $request->validate([
            'serviceId' => 'nullable|string|exists:appointment_services,id',
            'date' => 'required|date_format:Y-m-d',
            'status' => 'nullable|in:WAITING,CALLED,SERVING',
        ]);
        if (! empty($data['serviceId'])) {
            AppointmentAccess::assertServiceAccess($request->user(), $data['serviceId']);
        }

        $query = AppointmentAccess::queueEntries($request->user())
            ->with(['appointment', 'appointment.service', 'user', 'service'])
            ->where('queueDate', $data['date'])
            ->whereIn('status', self::ACTIVE_QUEUE_STATUSES)
            ->orderBy('serviceId')
            ->orderByRaw('CASE WHEN status = ? THEN 0 WHEN status = ? THEN 1 ELSE 2 END', ['SERVING', 'CALLED'])
            ->orderBy('queueNumber');
        if (! empty($data['serviceId'])) {
            $query->where('serviceId', $data['serviceId']);
        }
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        $entries = $query->get();

        return $entries->map(function (AppointmentQueueEntry $entry) {
            $position = $entry->status === 'WAITING'
                ? $this->waitEstimator->estimate($entry)['queuePosition']
                : null;
            $appointment = $this->queueEntryPayload($entry);
            $appointment['queueEntryId'] = $entry->id;
            $appointment['queueStatus'] = $entry->status;
            $appointment['queueNumber'] = $entry->queueNumber;
            $appointment['queuePosition'] = $position;

            return $appointment;
        });
    }

    public function callQueueEntry(Request $request, string $queueEntryId)
    {
        $entry = AppointmentAccess::queueEntries($request->user())->whereKey($queueEntryId)->firstOrFail();
        if (! $entry->appointmentId) {
            return response()->json($this->callVirtualQueueEntry($request, $entry, false));
        }
        $appointment = DB::transaction(function () use ($request, $entry) {
            $this->lockQueueSequence($entry->serviceId, $entry->queueDate);
            $entry = AppointmentAccess::queueEntries($request->user())->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($entry->queueDate !== now('Asia/Manila')->toDateString() || $entry->status !== 'WAITING') {
                abort(409, 'Only a waiting entry for today can be called.');
            }
            $current = AppointmentQueueEntry::where('serviceId', $entry->serviceId)
                ->where('queueDate', $entry->queueDate)
                ->whereIn('status', ['CALLED', 'SERVING'])
                ->lockForUpdate()
                ->exists();
            if ($current) {
                abort(409, 'Complete or mark the current service as no-show before calling the next student.');
            }
            $nextEntryId = AppointmentQueueEntry::where('serviceId', $entry->serviceId)
                ->where('queueDate', $entry->queueDate)
                ->where('status', 'WAITING')
                ->orderBy('queueNumber')
                ->value('id');
            if ($nextEntryId !== $entry->id) {
                abort(409, 'Queue order can only be changed with an authorized override and reason.');
            }
            $appointment = AppointmentAccess::appointments($request->user())
                ->whereKey($entry->appointmentId)
                ->lockForUpdate()
                ->firstOrFail();
            if ($appointment->status !== 'WAITING') {
                abort(409, 'Only a waiting appointment can be called.');
            }

            $entry->status = 'CALLED';
            $entry->calledAt = Oess::now();
            $entry->updatedAt = Oess::now();
            $entry->save();
            $this->recordEvent($appointment, $appointment->status, $appointment->status, 'queue_student_called', $request->user()->id, null, $entry->id);

            return $appointment;
        }, 5);

        $this->notifyStudent($appointment, 'CALLED', 'Your Appointment Is Being Served',
            "Please proceed. Queue ticket {$appointment->queueTicket} is now being called.");

        return response()->json($this->payload($appointment));
    }

    public function startQueueEntry(Request $request, string $queueEntryId)
    {
        $entry = AppointmentAccess::queueEntries($request->user())->whereKey($queueEntryId)->firstOrFail();
        if (! $entry->appointmentId) {
            return response()->json($this->transitionQueueTicket($request, $entry, ['CALLED'], 'SERVING', 'queue_service_started'));
        }

        return $this->start($request, $entry->appointmentId);
    }

    public function recallQueueEntry(Request $request, string $queueEntryId)
    {
        $entry = AppointmentAccess::queueEntries($request->user())->whereKey($queueEntryId)->firstOrFail();
        if ($entry->appointmentId) {
            return $this->recall($request, $entry->appointmentId);
        }

        $entry = DB::transaction(function () use ($request, $entry) {
            $entry = AppointmentAccess::queueEntries($request->user())
                ->with(['service', 'user'])
                ->whereKey($entry->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($entry->queueDate !== now('Asia/Manila')->toDateString() || $entry->status !== 'CALLED') {
                abort(409, 'Only a virtual queue ticket that is currently called can be recalled.');
            }
            $entry->calledAt = Oess::now();
            $entry->updatedAt = Oess::now();
            $entry->save();

            return $entry->fresh(['service', 'user']);
        }, 5);

        Oess::audit($request->user(), 'Recalled Virtual Queue Ticket', 'Appointments',
            "Recalled {$entry->service->code}-".str_pad((string) $entry->queueNumber, 3, '0', STR_PAD_LEFT));

        return response()->json($this->queueEntryPayload($entry));
    }

    public function completeQueueEntry(Request $request, string $queueEntryId)
    {
        $entry = AppointmentAccess::queueEntries($request->user())->whereKey($queueEntryId)->firstOrFail();
        if (! $entry->appointmentId) {
            return response()->json($this->transitionQueueTicket($request, $entry, ['SERVING'], 'COMPLETED', 'queue_service_completed'));
        }
        $request->merge(['status' => 'COMPLETED']);

        return $this->finish($request, $entry->appointmentId);
    }

    public function noShowQueueEntry(Request $request, string $queueEntryId)
    {
        $entry = AppointmentAccess::queueEntries($request->user())->whereKey($queueEntryId)->firstOrFail();
        if (! $entry->appointmentId) {
            $data = $request->validate(['reason' => 'nullable|string|max:1000']);
            return response()->json($this->transitionQueueTicket($request, $entry, ['CALLED'], 'NO_SHOW', 'queue_ticket_no_show', $data['reason'] ?? null));
        }
        $request->merge(['status' => 'NO_SHOW']);

        return $this->finish($request, $entry->appointmentId);
    }

    public function overrideQueueEntry(Request $request, string $queueEntryId)
    {
        $entry = AppointmentAccess::queueEntries($request->user())->whereKey($queueEntryId)->firstOrFail();
        if (! $entry->appointmentId) {
            $data = $request->validate(['reason' => 'required|string|max:1000']);
            return response()->json($this->callVirtualQueueEntry($request, $entry, true, $data['reason']));
        }
        $request->merge(['appointmentId' => $entry->appointmentId]);

        return $this->overrideCall($request, $entry->serviceId);
    }

    public function cancelQueueEntry(Request $request, string $queueEntryId)
    {
        $entry = AppointmentAccess::queueEntries($request->user())->whereKey($queueEntryId)->firstOrFail();
        if (! $entry->appointmentId) {
            $data = $request->validate(['reason' => 'nullable|string|max:1000']);
            $payload = $this->transitionQueueTicket($request, $entry, ['WAITING', 'CALLED'], 'CANCELLED', 'queue_ticket_cancelled', $data['reason'] ?? null);

            return response()->json($payload);
        }
        $request->merge(['reason' => $request->input('reason', 'Cancelled by staff.')]);

        return $this->cancel($request, $entry->appointmentId);
    }

    public function appointmentReport(Request $request)
    {
        $request->merge([
            'from' => $request->input('date_from'),
            'to' => $request->input('date_to'),
            'serviceId' => $request->input('service_id'),
        ]);
        $filters = $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'serviceId' => 'nullable|string|exists:appointment_services,id',
        ]);

        $query = AppointmentAccess::appointments($request->user());
        if (! empty($filters['from'])) {
            $query->where('appointmentDate', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('appointmentDate', '<=', $filters['to']);
        }
        if (! empty($filters['serviceId'])) {
            AppointmentAccess::assertServiceAccess($request->user(), $filters['serviceId']);
            $query->where('serviceId', $filters['serviceId']);
        }

        $statusCounts = (clone $query)->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');
        $serviceCounts = (clone $query)->select('serviceId', DB::raw('COUNT(*) as total'))
            ->groupBy('serviceId')
            ->with('service:id,code,name')
            ->get()
            ->map(fn ($row) => [
                'service_id' => $row->serviceId,
                'service_code' => $row->service?->code,
                'service_name' => $row->service?->name,
                'total' => (int) $row->total,
            ]);

        return response()->json([
            'date_from' => $filters['from'] ?? null,
            'date_to' => $filters['to'] ?? null,
            'total' => (clone $query)->count(),
            'by_status' => $statusCounts,
            'by_service' => $serviceCounts,
        ]);
    }

    public function start(Request $request, string $id)
    {
        $appointment = DB::transaction(function () use ($request, $id) {
            $appointment = AppointmentAccess::appointments($request->user())->whereKey($id)->lockForUpdate()->firstOrFail();
            $entry = AppointmentQueueEntry::where('appointmentId', $id)->lockForUpdate()->firstOrFail();
            if ($appointment->status !== 'WAITING' || $entry->status !== 'CALLED') {
                abort(409, 'Only a called waiting appointment can start service.');
            }
            $previous = $appointment->status;
            $now = Oess::now();
            $appointment->status = 'SERVING';
            $appointment->updatedAt = $now;
            $appointment->save();
            $entry->status = 'SERVING';
            $entry->serviceStartedAt = $now;
            $entry->updatedAt = $now;
            $entry->save();
            $this->recordEvent($appointment, $previous, 'SERVING', 'service_started', $request->user()->id, null, $entry->id);

            return $appointment;
        }, 5);

        Oess::audit($request->user(), 'Appointment Service Started', 'Appointments',
            "Started service for {$appointment->appointmentNumber}");

        return response()->json($this->payload($appointment));
    }

    public function recall(Request $request, string $id)
    {
        $appointment = DB::transaction(function () use ($request, $id) {
            $appointment = AppointmentAccess::appointments($request->user())->whereKey($id)->lockForUpdate()->firstOrFail();
            $entry = AppointmentQueueEntry::where('appointmentId', $id)->lockForUpdate()->firstOrFail();
            if ($appointment->status !== 'WAITING' || $entry->status !== 'CALLED') {
                abort(409, 'Only a currently called appointment can be recalled.');
            }
            $entry->calledAt = Oess::now();
            $entry->updatedAt = Oess::now();
            $entry->save();
            $this->recordEvent($appointment, $appointment->status, $appointment->status, 'queue_student_recalled', $request->user()->id, null, $entry->id);

            return $appointment;
        }, 5);

        return response()->json($this->payload($appointment));
    }

    public function finish(Request $request, string $id)
    {
        $data = $request->validate([
            'status' => 'required|in:COMPLETED,NO_SHOW',
            'reason' => 'nullable|string|max:1000',
        ]);
        $user = $request->user();
        $appointment = DB::transaction(function () use ($id, $data, $user) {
            $appointment = AppointmentAccess::appointments($user)->whereKey($id)->lockForUpdate()->firstOrFail();
            $entry = AppointmentQueueEntry::where('appointmentId', $id)->lockForUpdate()->first();
            if ($data['status'] === 'COMPLETED') {
                if ($appointment->status !== 'SERVING' || ! $entry || $entry->status !== 'SERVING') {
                    abort(409, 'Only an appointment currently in service can be completed.');
                }
            } else {
                if (! in_array($appointment->status, ['CONFIRMED', 'WAITING'], true)) {
                    abort(409, 'Only an unserved appointment can be marked no-show.');
                }
                $end = Carbon::parse($appointment->appointmentDate.' '.$appointment->endTimeSnapshot, 'Asia/Manila')
                    ->addMinutes($appointment->lateArrivalGraceMinutes);
                if (now('Asia/Manila')->lessThanOrEqualTo($end)) {
                    abort(409, 'An appointment can be marked no-show only after its late-arrival grace period has ended.');
                }
            }

            $previousStatus = $appointment->status;
            $appointment->status = $data['status'];
            $appointment->staffNotes = $data['reason'] ?? $appointment->staffNotes;
            $appointment->updatedAt = Oess::now();
            if ($data['status'] === 'COMPLETED') {
                $appointment->completedAt = Oess::now();
            }
            $appointment->save();
            if ($entry) {
                $entry->status = $data['status'];
                if ($data['status'] === 'COMPLETED') {
                    $entry->completedAt = Oess::now();
                }
                $entry->updatedAt = Oess::now();
                $entry->save();
            }
            $this->recordEvent(
                $appointment,
                $previousStatus,
                $data['status'],
                $data['status'] === 'COMPLETED' ? 'service_completed' : 'appointment_marked_no_show',
                $user->id,
                $data['reason'] ?? null,
                $entry?->id
            );

            return $appointment;
        }, 5);

        Oess::audit($user, $data['status'] === 'COMPLETED' ? 'Completed Appointment' : 'Marked Appointment No-show', 'Appointments',
            "{$data['status']} appointment {$appointment->appointmentNumber}".(! empty($data['reason']) ? ": {$data['reason']}" : ''));
        if ($data['status'] === 'COMPLETED') {
            $this->notifyStudent($appointment, 'COMPLETED', 'Appointment Completed',
                "Appointment {$appointment->appointmentNumber} has been completed.");
        }

        return response()->json($this->payload($appointment));
    }

    private function createAppointment($user, AppointmentSlot $slot, AppointmentService $service, array $data): Appointment
    {
        $now = Oess::now();
        $appointment = Appointment::create([
            'id' => (string) Str::uuid(),
            'appointmentNumber' => $this->nextAppointmentReference($slot->appointmentDate),
            'serviceId' => $service->id,
            'slotId' => $slot->id,
            'documentRequestId' => $data['documentRequestId'] ?? null,
            'studentNumber' => $user->studentNumber,
            'studentName' => $user->name,
            'purpose' => $data['purpose'] ?? null,
            'appointmentDate' => $slot->appointmentDate,
            'serviceNameSnapshot' => $service->name,
            'startTimeSnapshot' => $slot->startTime,
            'endTimeSnapshot' => $slot->endTime,
            'serviceDurationMinutes' => $service->serviceDurationMinutes,
            'cancellationCutoffMinutes' => $service->cancellationCutoffMinutes,
            'lateArrivalGraceMinutes' => $service->lateArrivalGraceMinutes,
            'earlyArrivalWindowMinutes' => $service->earlyArrivalWindowMinutes,
            'rescheduledFromId' => $data['rescheduledFromId'] ?? null,
            'status' => 'CONFIRMED',
            'createdAt' => $now,
            'updatedAt' => $now,
        ]);
        $this->recordEvent($appointment, null, 'CONFIRMED', 'appointment_confirmed', $user->id,
            ! empty($data['rescheduledFromId']) ? "Rescheduled from {$data['rescheduledFromId']}" : null);

        if ($appointment->documentRequestId) {
            DocumentRequest::whereKey($appointment->documentRequestId)->lockForUpdate()->firstOrFail();
        }

        return $appointment;
    }

    private function nextAppointmentReference(string $date): string
    {
        DB::table('appointment_daily_sequences')->insertOrIgnore([
            'appointmentDate' => $date,
            'lastNumber' => 0,
        ]);
        $sequence = DB::table('appointment_daily_sequences')
            ->where('appointmentDate', $date)
            ->lockForUpdate()
            ->first();

        do {
            $number = $sequence->lastNumber + 1;
            DB::table('appointment_daily_sequences')
                ->where('id', $sequence->id)
                ->update(['lastNumber' => $number]);
            $sequence->lastNumber = $number;
            $reference = 'PNC-'.str_replace('-', '', $date).'-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
        } while (Appointment::where('appointmentNumber', $reference)->exists());

        return $reference;
    }

    private function assertBookableSlot(AppointmentSlot $slot, AppointmentService $service): void
    {
        $today = now('Asia/Manila')->toDateString();
        if (! $service->isActive || $slot->status !== 'Open' ||
            $service->closures()->where('closedDate', $slot->appointmentDate)->exists() ||
            $slot->appointmentDate < $today ||
            $slot->appointmentDate > now('Asia/Manila')->addDays($service->bookingWindowDays)->toDateString() ||
            ($slot->appointmentDate === $today && $slot->startTime <= now('Asia/Manila')->format('H:i:s'))) {
            abort(422, 'This appointment time is not available for booking.');
        }
    }

    private function assertDocumentRequest(?string $documentRequestId, string $studentNumber): void
    {
        if (! $documentRequestId) {
            return;
        }
        $documentRequest = DocumentRequest::whereKey($documentRequestId)
            ->where('studentNumber', $studentNumber)
            ->lockForUpdate()
            ->first();
        if (! $documentRequest || $documentRequest->status === 'Rejected') {
            abort(422, 'Choose one of your own active document requests.');
        }
    }

    private function assertNoDuplicateBooking(AppointmentSlot $slot, string $studentNumber): void
    {
        $duplicate = Appointment::where('slotId', $slot->id)
            ->where('studentNumber', $studentNumber)
            ->whereIn('status', self::CAPACITY_STATUSES)
            ->exists();
        if ($duplicate) {
            abort(409, 'You already have an appointment for this time.');
        }
    }

    private function assertCapacity(AppointmentSlot $slot): void
    {
        $booked = Appointment::where('slotId', $slot->id)
            ->whereIn('status', self::CAPACITY_STATUSES)
            ->count();
        if ($booked >= $slot->capacity) {
            abort(409, 'This appointment time has just filled. Choose another available time.');
        }
    }

    private function cancellationIsPermitted(Appointment $appointment): bool
    {
        $cutoff = Carbon::parse($appointment->appointmentDate.' '.$appointment->startTimeSnapshot, 'Asia/Manila')
            ->subMinutes($appointment->cancellationCutoffMinutes);

        return now('Asia/Manila')->lessThan($cutoff);
    }

    private function ensureStudentAccount(?string $studentNumber): void
    {
        if (! $studentNumber) {
            abort(422, 'No student number is linked to this account.');
        }
    }

    private function lockQueueSequence(string $serviceId, string $date): void
    {
        DB::table('appointment_queue_sequences')->insertOrIgnore([
            'serviceId' => $serviceId,
            'appointmentDate' => $date,
            'lastNumber' => 0,
        ]);
        DB::table('appointment_queue_sequences')
            ->where('serviceId', $serviceId)
            ->where('appointmentDate', $date)
            ->update(['lastNumber' => DB::raw('lastNumber')]);
        DB::table('appointment_queue_sequences')
            ->where('serviceId', $serviceId)
            ->where('appointmentDate', $date)
            ->lockForUpdate()
            ->first();
    }

    private function lockSlot(string $slotId): AppointmentSlot
    {
        DB::table('appointment_slots')
            ->where('id', $slotId)
            ->update(['updatedAt' => DB::raw('updatedAt')]);

        return AppointmentSlot::whereKey($slotId)->lockForUpdate()->firstOrFail();
    }

    private function recordEvent(
        Appointment $appointment,
        ?string $previousStatus,
        ?string $newStatus,
        string $eventType,
        ?string $actorUserId,
        ?string $reason = null,
        ?string $queueEntryId = null,
    ): void {
        AppointmentStatusHistory::create([
            'id' => (string) Str::uuid(),
            'appointmentId' => $appointment->id,
            'queueEntryId' => $queueEntryId,
            'previousStatus' => $previousStatus,
            'newStatus' => $newStatus,
            'eventType' => $eventType,
            'actorUserId' => $actorUserId,
            'occurredAt' => Oess::now(),
            'reason' => $reason,
        ]);
    }

    private function notifyStudent(Appointment $appointment, string $type, string $title, string $message): void
    {
        AppointmentNotificationDispatcher::queue($appointment, $type, $title, $message);
    }

    private function callVirtualQueueEntry(Request $request, AppointmentQueueEntry $entry, bool $override, ?string $reason = null): array
    {
        $entry = DB::transaction(function () use ($request, $entry, $override, $reason) {
            $this->lockQueueSequence($entry->serviceId, $entry->queueDate);
            $entry = AppointmentAccess::queueEntries($request->user())
                ->with(['service', 'user'])
                ->whereKey($entry->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($entry->appointmentId || $entry->queueDate !== now('Asia/Manila')->toDateString() || $entry->status !== 'WAITING') {
                abort(409, 'Only a waiting virtual queue ticket for today can be called.');
            }
            if (AppointmentQueueEntry::where('serviceId', $entry->serviceId)
                ->where('queueDate', $entry->queueDate)
                ->whereIn('status', ['CALLED', 'SERVING'])
                ->lockForUpdate()
                ->exists()) {
                abort(409, 'Complete or mark the current service as no-show before calling the next student.');
            }
            if (! $override) {
                $nextEntryId = AppointmentQueueEntry::where('serviceId', $entry->serviceId)
                    ->where('queueDate', $entry->queueDate)
                    ->where('status', 'WAITING')
                    ->orderBy('queueNumber')
                    ->value('id');
                if ($nextEntryId !== $entry->id) {
                    abort(409, 'Queue order can only be changed with an authorized override and reason.');
                }
            }

            $entry->status = 'CALLED';
            $entry->calledAt = Oess::now();
            $entry->updatedAt = Oess::now();
            $entry->save();

            return $entry->fresh(['service', 'user']);
        }, 5);

        Oess::audit($request->user(), $override ? 'Virtual Queue Override' : 'Called Virtual Queue Ticket', 'Appointments',
            "Called {$entry->service->code}-".str_pad((string) $entry->queueNumber, 3, '0', STR_PAD_LEFT).
            ($override ? " out of queue order: {$reason}" : ''));

        return $this->queueEntryPayload($entry);
    }

    private function transitionQueueTicket(
        Request $request,
        AppointmentQueueEntry $entry,
        array $allowedStatuses,
        string $newStatus,
        string $event,
        ?string $reason = null
    ): array {
        $entry = DB::transaction(function () use ($request, $entry, $allowedStatuses, $newStatus, $event) {
            $entry = AppointmentAccess::queueEntries($request->user())
                ->with(['service', 'user'])
                ->whereKey($entry->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($entry->appointmentId) {
                abort(409, 'This action is only available for virtual queue tickets.');
            }
            if ($entry->queueDate !== now('Asia/Manila')->toDateString() ||
                ! in_array($entry->status, $allowedStatuses, true)) {
                abort(409, 'This virtual queue ticket can no longer be changed.');
            }

            $now = Oess::now();
            $entry->status = $newStatus;
            $entry->updatedAt = $now;
            if ($newStatus === 'SERVING') {
                $entry->serviceStartedAt = $now;
            } elseif (in_array($newStatus, ['COMPLETED', 'NO_SHOW', 'CANCELLED'], true)) {
                $entry->completedAt = $newStatus === 'COMPLETED' ? $now : null;
            }
            $entry->save();

            return $entry->fresh(['service', 'user']);
        }, 5);

        Oess::audit($request->user(), ucfirst(str_replace('_', ' ', $event)), 'Appointments',
            "{$entry->service->code}-".str_pad((string) $entry->queueNumber, 3, '0', STR_PAD_LEFT).
            ($reason ? ": {$reason}" : ''));

        return $this->queueEntryPayload($entry);
    }

    private function queueEntryPayload(AppointmentQueueEntry $entry): array
    {
        $appointment = $entry->appointment;
        if ($appointment) {
            $payload = $this->payload($appointment);
            $payload['queueEntryId'] = $entry->id;
            $payload['walkIn'] = false;

            return $entry->status === 'WAITING'
                ? array_merge($payload, $this->waitEstimator->estimate($entry))
                : $payload;
        }

        $service = $entry->service;
        $user = $entry->user;
        $ticket = $service->code.'-'.str_pad((string) $entry->queueNumber, 3, '0', STR_PAD_LEFT);
        $payload = [
            'id' => $entry->id,
            'queueEntryId' => $entry->id,
            'appointmentNumber' => $ticket,
            'serviceId' => $service->id,
            'serviceCode' => $service->code,
            'serviceName' => $service->name,
            'serviceInstructions' => $service->instructions,
            'queuePaused' => $service->queuePaused,
            'serviceAvailable' => $service->isActive,
            'appointmentDate' => $entry->queueDate,
            'studentNumber' => $user?->studentNumber,
            'studentName' => $user?->name ?? $entry->guestName,
            'isGuest' => ! $user && (bool) $entry->guestAccessTokenHash,
            'status' => $entry->status,
            'queueNumber' => $entry->queueNumber,
            'queueTicket' => $ticket,
            'queueStatus' => $entry->status,
            'queuePosition' => null,
            'estimatedWaitMinutes' => null,
            'checkedInAt' => $entry->checkedInAt,
            'calledAt' => $entry->calledAt,
            'serviceStartedAt' => $entry->serviceStartedAt,
            'completedAt' => $entry->completedAt,
            'createdAt' => $entry->createdAt,
            'walkIn' => true,
        ];

        return $entry->status === 'WAITING'
            ? array_merge($payload, $this->waitEstimator->estimate($entry))
            : $payload;
    }

    private function payload(Appointment $appointment): array
    {
        $queueEntry = $appointment->queueEntry;
        $documentRequest = $appointment->documentRequest;
        $time = fn (?string $value) => $value ? substr($value, 0, 5) : null;

        return [
            'id' => $appointment->id,
            'appointmentNumber' => $appointment->appointmentNumber,
            'serviceId' => $appointment->serviceId,
            'serviceCode' => $appointment->service->code,
            'serviceName' => $appointment->serviceNameSnapshot ?: $appointment->service->name,
            'slotId' => $appointment->slotId,
            'appointmentDate' => $appointment->appointmentDate,
            'startTime' => $time($appointment->startTimeSnapshot),
            'endTime' => $time($appointment->endTimeSnapshot),
            'serviceDurationMinutes' => $appointment->serviceDurationMinutes,
            'cancellationCutoffMinutes' => $appointment->cancellationCutoffMinutes,
            'lateArrivalGraceMinutes' => $appointment->lateArrivalGraceMinutes,
            'earlyArrivalWindowMinutes' => $appointment->earlyArrivalWindowMinutes,
            'documentRequestId' => $appointment->documentRequestId,
            'documentTrackingNumber' => $documentRequest?->trackingNumber,
            'studentNumber' => $appointment->studentNumber,
            'studentName' => $appointment->studentName,
            'purpose' => $appointment->purpose,
            'status' => $appointment->status,
            'queueNumber' => $queueEntry?->queueNumber ?? $appointment->queueNumber,
            'queueTicket' => $appointment->queueTicket,
            'queueStatus' => $queueEntry?->status,
            'queuePosition' => null,
            'estimatedWaitMinutes' => null,
            'rescheduledFromId' => $appointment->rescheduledFromId,
            'rescheduledToId' => $appointment->rescheduledToId,
            'createdAt' => $appointment->createdAt,
            'checkedInAt' => $appointment->checkedInAt,
            'calledAt' => $queueEntry?->calledAt ?? $appointment->calledAt,
            'serviceStartedAt' => $queueEntry?->serviceStartedAt,
            'completedAt' => $appointment->completedAt,
            'staffNotes' => $appointment->staffNotes,
        ];
    }

    private function payloadWithQueueMetrics(Appointment $appointment): array
    {
        $payload = $this->payload($appointment);
        $entry = $appointment->queueEntry;
        if (! $entry || $entry->status !== 'WAITING') {
            return $payload;
        }

        return array_merge($payload, $this->waitEstimator->estimate($entry));
    }
}
