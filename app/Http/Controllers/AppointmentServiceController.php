<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\AppointmentService;
use App\Models\AppointmentServiceClosure;
use App\Models\AppointmentServiceStaff;
use App\Models\AppointmentSlot;
use App\Models\User;
use App\Support\AppointmentAccess;
use App\Support\Oess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AppointmentServiceController extends Controller
{
    private const BOOKING_STATUSES = ['CONFIRMED', 'WAITING', 'SERVING', 'COMPLETED'];

    public function index(Request $request)
    {
        $user = $request->user();
        if ($user->role === 'Student') {
            return AppointmentService::where('isActive', true)->orderBy('name')->get();
        }

        return AppointmentAccess::services($user)->orderBy('name')->get();
    }

    public function staffAssignments(Request $request, string $serviceId)
    {
        if ($request->user()->role !== 'Administrator') {
            abort(403, 'Only a service administrator can manage service staff assignments.');
        }
        AppointmentService::findOrFail($serviceId);

        return User::query()
            ->join('appointment_service_staff', 'appointment_service_staff.userId', '=', 'users.id')
            ->where('appointment_service_staff.serviceId', $serviceId)
            ->whereIn('users.role', ['Registrar', 'Department Staff'])
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.username', 'users.role', 'users.department']);
    }

    public function updateStaffAssignments(Request $request, string $serviceId)
    {
        if ($request->user()->role !== 'Administrator') {
            abort(403, 'Only a service administrator can manage service staff assignments.');
        }
        $request->merge(['userIds' => $request->input('user_ids', $request->input('userIds'))]);
        $data = $request->validate([
            'userIds' => 'required|array|max:100',
            'userIds.*' => 'required|string|distinct|exists:users,id',
        ]);
        $staff = User::whereIn('id', $data['userIds'])->get();
        if ($staff->contains(fn (User $user) => $user->status !== 'Active' ||
            ! in_array($user->role, ['Registrar', 'Department Staff'], true))) {
            abort(422, 'Only active Registrar and Department Staff accounts can be assigned to appointment services.');
        }

        $service = AppointmentService::findOrFail($serviceId);
        DB::transaction(function () use ($serviceId, $data, $request) {
            AppointmentServiceStaff::where('serviceId', $serviceId)->delete();
            foreach ($data['userIds'] as $userId) {
                AppointmentServiceStaff::create([
                    'serviceId' => $serviceId,
                    'userId' => $userId,
                    'assignedByUserId' => $request->user()->id,
                    'createdAt' => Oess::now(),
                ]);
            }
        });

        Oess::audit($request->user(), 'Updated Appointment Service Staff', 'Appointments',
            'Updated staff assignments for appointment service '.$service->code);

        return response()->json($this->staffAssignments($request, $serviceId));
    }

    public function store(Request $request)
    {
        if ($request->user()->role !== 'Administrator') {
            abort(403, 'Only a service administrator can configure appointment services.');
        }
        $data = $request->validate($this->serviceRules(true));
        $now = Oess::now();
        $service = AppointmentService::create([
            'id' => (string) Str::uuid(),
            'code' => strtoupper($data['code']),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'instructions' => $data['instructions'] ?? null,
            'requiredDocuments' => $data['requiredDocuments'] ?? [],
            'serviceDurationMinutes' => $data['serviceDurationMinutes'] ?? 30,
            'bookingWindowDays' => $data['bookingWindowDays'] ?? 30,
            'cancellationCutoffMinutes' => $data['cancellationCutoffMinutes'] ?? 0,
            'lateArrivalGraceMinutes' => $data['lateArrivalGraceMinutes'] ?? 0,
            'earlyArrivalWindowMinutes' => $data['earlyArrivalWindowMinutes'] ?? 30,
            'isActive' => true,
            'publicBookable' => $data['publicBookable'] ?? false,
            'createdAt' => $now,
            'updatedAt' => $now,
        ]);

        Oess::audit($request->user(), 'Created Appointment Service', 'Appointments',
            "Created appointment service {$service->code}: {$service->name}");

        return response()->json($service, 201);
    }

    public function update(Request $request, string $id)
    {
        if ($request->user()->role !== 'Administrator') {
            abort(403, 'Only a service administrator can configure appointment services.');
        }
        $data = $request->validate($this->serviceRules(false));
        $service = DB::transaction(function () use ($id, $data) {
            $service = $this->lockService($id);
            $service->fill($data);
            $service->updatedAt = Oess::now();
            $service->save();

            return $service;
        }, 5);

        Oess::audit($request->user(), 'Updated Appointment Service', 'Appointments',
            "Updated appointment service {$service->code}: {$service->name}");

        return response()->json($service);
    }

    public function studentSlots(Request $request)
    {
        $today = now('Asia/Manila')->toDateString();
        $request->merge([
            'serviceId' => $request->input('service_id', $request->input('serviceId')),
            'from' => $request->input('date_from', $request->input('from')),
            'to' => $request->input('date_to', $request->input('to')),
        ]);
        $data = $request->validate([
            'serviceId' => 'nullable|string|exists:appointment_services,id',
            'from' => 'nullable|date_format:Y-m-d|after_or_equal:'.$today,
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
        ]);

        $query = AppointmentSlot::where('status', 'Open')
            ->where('appointmentDate', '>=', $data['from'] ?? $today)
            ->where('appointmentDate', '<=', now('Asia/Manila')->addDays(365)->toDateString())
            ->whereHas('service', fn ($builder) => $builder->where('isActive', true))
            ->whereDoesntHave('service.closures', fn ($builder) => $builder->whereColumn('closedDate', 'appointment_slots.appointmentDate'))
            ->whereRaw('(SELECT COUNT(*) FROM "appointments" WHERE "appointments"."slotId" = "appointment_slots"."id" AND "appointments"."status" IN (?, ?, ?, ?)) < "appointment_slots"."capacity"', self::BOOKING_STATUSES);
        if (($data['from'] ?? $today) === $today) {
            $query->where(function ($builder) use ($today) {
                $builder->where('appointmentDate', '>', $today)
                    ->orWhere(function ($todayBuilder) use ($today) {
                        $todayBuilder->where('appointmentDate', $today)
                            ->where('startTime', '>', now('Asia/Manila')->format('H:i:s'));
                    });
            });
        }

        if (! empty($data['to'])) {
            $query->where('appointmentDate', '<=', $data['to']);
        }
        if (! empty($data['serviceId'])) {
            $query->where('serviceId', $data['serviceId']);
        }

        return $query->orderBy('appointmentDate')->orderBy('startTime')->get()
            ->filter(fn (AppointmentSlot $slot) => $slot->appointmentDate <= now('Asia/Manila')->addDays($slot->service->bookingWindowDays)->toDateString())
            ->map(fn (AppointmentSlot $slot) => $this->slotPayload($slot));
    }

    public function staffSlots(Request $request)
    {
        $request->merge([
            'serviceId' => $request->input('service_id', $request->input('serviceId')),
            'from' => $request->input('date_from', $request->input('from')),
            'to' => $request->input('date_to', $request->input('to')),
        ]);
        $data = $request->validate([
            'serviceId' => 'nullable|string|exists:appointment_services,id',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'status' => 'nullable|in:Open,Closed',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = AppointmentAccess::slots($request->user())
            ->with('service')
            ->orderBy('appointmentDate')
            ->orderBy('startTime');
        if (! empty($data['serviceId'])) {
            $query->where('serviceId', $data['serviceId']);
        }
        if (! empty($data['from'])) {
            $query->where('appointmentDate', '>=', $data['from']);
        }
        if (! empty($data['to'])) {
            $query->where('appointmentDate', '<=', $data['to']);
        }
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        $slots = $query->paginate((int) ($data['per_page'] ?? 50));
        $slots->setCollection($slots->getCollection()->map(fn (AppointmentSlot $slot) => $this->slotPayload($slot)));

        return response()->json($slots);
    }

    public function serviceSlots(Request $request, string $serviceId)
    {
        $service = AppointmentAccess::services($request->user())->whereKey($serviceId)->firstOrFail();
        $data = $request->validate(['date' => 'nullable|date_format:Y-m-d']);

        $query = AppointmentSlot::where('serviceId', $service->id)
            ->orderBy('appointmentDate')
            ->orderBy('startTime');
        if (! empty($data['date'])) {
            $query->where('appointmentDate', $data['date']);
        }

        return $query->get()->map(fn (AppointmentSlot $slot) => $this->slotPayload($slot));
    }

    public function createSlot(Request $request, string $serviceId)
    {
        if ($request->user()->role !== 'Administrator') {
            abort(403, 'Only a service administrator can configure appointment schedules.');
        }
        $service = AppointmentService::whereKey($serviceId)->firstOrFail();
        $data = $request->validate($this->slotRules($service));
        [$service, $slot] = DB::transaction(function () use ($serviceId, $data) {
            $this->lockService($serviceId);
            $service = AppointmentService::whereKey($serviceId)
                ->where('isActive', true)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertDateIsOpen($service->id, $data['appointmentDate']);
            $this->assertNoOverlap($service->id, $data['appointmentDate'], $data['startTime'], $data['endTime']);

            $now = Oess::now();
            $slot = AppointmentSlot::create([
                'id' => (string) Str::uuid(),
                'serviceId' => $service->id,
                'appointmentDate' => $data['appointmentDate'],
                'startTime' => $data['startTime'],
                'endTime' => $data['endTime'],
                'capacity' => $data['capacity'],
                'status' => 'Open',
                'createdAt' => $now,
                'updatedAt' => $now,
            ]);

            return [$service, $slot];
        }, 5);

        Oess::audit($request->user(), 'Created Appointment Slot', 'Appointments',
            "Added {$service->name} availability on {$slot->appointmentDate} from {$slot->startTime} to {$slot->endTime} (capacity {$slot->capacity})");

        return response()->json($this->slotPayload($slot), 201);
    }

    public function createStaffSlot(Request $request)
    {
        $request->merge([
            'serviceId' => $request->input('service_id'),
            'appointmentDate' => $request->input('appointment_date'),
            'startTime' => $request->input('start_time'),
            'endTime' => $request->input('end_time'),
        ]);
        $data = $request->validate([
            'serviceId' => 'required|string|exists:appointment_services,id',
            'appointmentDate' => 'required|date_format:Y-m-d',
            'startTime' => 'required|date_format:H:i',
            'endTime' => 'required|date_format:H:i',
            'capacity' => 'required|integer|min:1|max:500',
        ]);

        return $this->createSlot($request, $data['serviceId']);
    }

    public function updateSlot(Request $request, string $id)
    {
        if ($request->user()->role !== 'Administrator') {
            abort(403, 'Only a service administrator can configure appointment schedules.');
        }
        $request->merge(array_filter([
            'appointmentDate' => $request->input('appointment_date', $request->input('appointmentDate')),
            'startTime' => $request->input('start_time', $request->input('startTime')),
            'endTime' => $request->input('end_time', $request->input('endTime')),
        ], fn ($value) => $value !== null));
        $slot = AppointmentSlot::findOrFail($id);
        $service = $slot->service;
        $data = $request->validate([
            'appointmentDate' => ['sometimes', 'required', 'date_format:Y-m-d', 'after_or_equal:'.now('Asia/Manila')->toDateString()],
            'startTime' => 'sometimes|required|date_format:H:i',
            'endTime' => 'sometimes|required|date_format:H:i',
            'capacity' => 'sometimes|required|integer|min:1|max:500',
            'status' => 'sometimes|required|in:Open,Closed',
        ]);

        $slot = DB::transaction(function () use ($id, $service, $data) {
            $slot = $this->lockSlot($id);
            $newDate = $data['appointmentDate'] ?? $slot->appointmentDate;
            $newStart = $data['startTime'] ?? substr($slot->startTime, 0, 5);
            $newEnd = $data['endTime'] ?? substr($slot->endTime, 0, 5);
            $scheduleChanged = $newDate !== $slot->appointmentDate ||
                $newStart !== substr($slot->startTime, 0, 5) ||
                $newEnd !== substr($slot->endTime, 0, 5);
            if ($newStart >= $newEnd) {
                abort(422, 'The end time must be later than the start time.');
            }

            $booked = $this->bookedCount($slot->id);
            if ($scheduleChanged && $booked > 0) {
                abort(409, 'A schedule with existing appointments cannot be moved; close it and create a new schedule instead.');
            }
            if (isset($data['capacity']) && $data['capacity'] < $booked) {
                abort(422, "Capacity cannot be lower than the {$booked} active appointments.");
            }
            if ($scheduleChanged) {
                if ($newDate > now('Asia/Manila')->addDays($service->bookingWindowDays)->toDateString()) {
                    abort(422, 'The schedule date is outside this service booking window.');
                }
                $this->assertDateIsOpen($service->id, $newDate);
                $this->assertNoOverlap($service->id, $newDate, $newStart, $newEnd, $slot->id);
            }

            $slot->fill($data);
            $slot->updatedAt = Oess::now();
            $slot->save();

            return $slot;
        }, 5);

        Oess::audit($request->user(), 'Updated Appointment Slot', 'Appointments',
            "Updated {$slot->service->name} availability on {$slot->appointmentDate} at {$slot->startTime}");

        return response()->json($this->slotPayload($slot));
    }

    public function closures(Request $request, string $serviceId)
    {
        AppointmentAccess::services($request->user())->whereKey($serviceId)->firstOrFail();

        return AppointmentServiceClosure::where('serviceId', $serviceId)
            ->where('closedDate', '>=', now('Asia/Manila')->toDateString())
            ->orderBy('closedDate')
            ->get(['id', 'serviceId', 'closedDate', 'reason', 'createdAt', 'updatedAt']);
    }

    public function closeDate(Request $request, string $serviceId)
    {
        if ($request->user()->role !== 'Administrator') {
            abort(403, 'Only a service administrator can configure appointment schedules.');
        }
        $data = $request->validate([
            'closedDate' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now('Asia/Manila')->toDateString()],
            'reason' => 'nullable|string|max:1000',
        ]);
        $service = AppointmentService::findOrFail($serviceId);
        $now = Oess::now();
        $closure = AppointmentServiceClosure::updateOrCreate(
            ['serviceId' => $service->id, 'closedDate' => $data['closedDate']],
            [
                'id' => (string) Str::uuid(),
                'reason' => $data['reason'] ?? null,
                'createdAt' => $now,
                'updatedAt' => $now,
            ]
        );

        Oess::audit($request->user(), 'Closed Appointment Date', 'Appointments',
            "Closed {$service->name} booking date {$closure->closedDate}".(! empty($data['reason']) ? ": {$data['reason']}" : ''));

        return response()->json($closure, 201);
    }

    public function reopenDate(Request $request, string $serviceId, string $closureId)
    {
        if ($request->user()->role !== 'Administrator') {
            abort(403, 'Only a service administrator can configure appointment schedules.');
        }
        $closure = AppointmentServiceClosure::where('serviceId', $serviceId)->whereKey($closureId)->firstOrFail();
        $closure->delete();
        Oess::audit($request->user(), 'Reopened Appointment Date', 'Appointments',
            "Reopened {$closure->service->name} booking date {$closure->closedDate}");

        return response()->json(['message' => 'Appointment date reopened.']);
    }

    private function serviceRules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes|required';

        return [
            'code' => [$creating ? 'required' : 'prohibited', 'string', 'max:30', 'regex:/^[A-Z][A-Z0-9_-]*$/', 'unique:appointment_services,code'],
            'name' => "{$required}|string|max:255",
            'description' => 'sometimes|nullable|string|max:2000',
            'instructions' => 'sometimes|nullable|string|max:4000',
            'requiredDocuments' => 'sometimes|array|max:30',
            'requiredDocuments.*' => 'string|max:255',
            'serviceDurationMinutes' => 'sometimes|required|integer|min:1|max:1440',
            'bookingWindowDays' => 'sometimes|required|integer|min:1|max:365',
            'cancellationCutoffMinutes' => 'sometimes|required|integer|min:0|max:10080',
            'lateArrivalGraceMinutes' => 'sometimes|required|integer|min:0|max:240',
            'earlyArrivalWindowMinutes' => 'sometimes|required|integer|min:0|max:1440',
            'isActive' => 'sometimes|required|boolean',
            'publicBookable' => 'sometimes|required|boolean',
        ];
    }

    private function slotRules(AppointmentService $service): array
    {
        $maxDate = now('Asia/Manila')->addDays($service->bookingWindowDays)->toDateString();

        return [
            'appointmentDate' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now('Asia/Manila')->toDateString(), 'before_or_equal:'.$maxDate],
            'startTime' => 'required|date_format:H:i',
            'endTime' => 'required|date_format:H:i|after:startTime',
            'capacity' => 'required|integer|min:1|max:500',
        ];
    }

    private function assertDateIsOpen(string $serviceId, string $date): void
    {
        if (AppointmentServiceClosure::where('serviceId', $serviceId)->where('closedDate', $date)->exists()) {
            abort(422, 'This date is closed for appointments.');
        }
    }

    private function assertNoOverlap(string $serviceId, string $date, string $startTime, string $endTime, ?string $exceptId = null): void
    {
        $query = AppointmentSlot::where('serviceId', $serviceId)
            ->where('appointmentDate', $date)
            ->where('startTime', '<', $endTime)
            ->where('endTime', '>', $startTime);
        if ($exceptId) {
            $query->where('id', '!=', $exceptId);
        }
        if ($query->exists()) {
            abort(422, 'This time overlaps another slot for the selected service.');
        }
    }

    private function bookedCount(string $slotId): int
    {
        return Appointment::where('slotId', $slotId)->whereIn('status', self::BOOKING_STATUSES)->count();
    }

    private function lockService(string $serviceId): AppointmentService
    {
        DB::table('appointment_services')
            ->where('id', $serviceId)
            ->update(['updatedAt' => DB::raw('updatedAt')]);

        return AppointmentService::whereKey($serviceId)->lockForUpdate()->firstOrFail();
    }

    private function lockSlot(string $slotId): AppointmentSlot
    {
        DB::table('appointment_slots')
            ->where('id', $slotId)
            ->update(['updatedAt' => DB::raw('updatedAt')]);

        return AppointmentSlot::whereKey($slotId)->lockForUpdate()->firstOrFail();
    }

    private function slotPayload(AppointmentSlot $slot): array
    {
        $booked = $this->bookedCount($slot->id);
        $service = $slot->service;
        $closed = $service->closures()->where('closedDate', $slot->appointmentDate)->exists();

        return [
            'id' => $slot->id,
            'serviceId' => $slot->serviceId,
            'serviceCode' => $service->code,
            'serviceName' => $service->name,
            'appointmentDate' => $slot->appointmentDate,
            'startTime' => substr($slot->startTime, 0, 5),
            'endTime' => substr($slot->endTime, 0, 5),
            'capacity' => $slot->capacity,
            'currentBookingCount' => $booked,
            'booked' => $booked,
            'available' => max(0, $slot->capacity - $booked),
            'status' => $slot->status,
            'dateClosed' => $closed,
            'createdAt' => $slot->createdAt,
            'updatedAt' => $slot->updatedAt,
        ];
    }
}
