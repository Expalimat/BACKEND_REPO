<?php

namespace App\Http\Controllers;

use App\Models\AppointmentBusinessRule;
use App\Models\AppointmentService;
use App\Models\AppointmentSlot;
use App\Support\Oess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AppointmentSlotController extends Controller
{
    public function index(Request $request)
    {
        $q = AppointmentSlot::with('service')->orderByDesc('seq');

        if ($request->filled('serviceId')) {
            $q->where('serviceId', $request->query('serviceId'));
        }

        if ($request->filled('appointmentDate')) {
            $q->where('appointmentDate', $request->query('appointmentDate'));
        }

        return Oess::paginateIfRequested(
            $q,
            $request,
            ['id', 'serviceId', 'appointmentDate', 'startTime', 'endTime', 'status', 'maxCapacity']
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'serviceId' => ['required', 'string', 'exists:appointment_services,id'],
            'appointmentDate' => ['required', 'date', 'date_format:Y-m-d'],
            'startTime' => ['required', 'date_format:H:i'],
            'endTime' => ['required', 'date_format:H:i', 'after:startTime'],
            'maxCapacity' => ['required', 'integer', 'min:1'],
            'status' => ['nullable', 'string', Rule::in(['Open', 'Closed', 'BookedOut'])],
        ]);

        $service = AppointmentService::findOrFail($data['serviceId']);
        abort_unless($service->status === 'Active', 422, 'Only active services can have slots.');
        $this->validateAgainstRules($service->id, $data['appointmentDate'], $data['startTime'], $data['endTime']);

        $slot = AppointmentSlot::create([
            'id' => Oess::randomId(AppointmentSlot::class, 'SLOT'),
            'serviceId' => $data['serviceId'],
            'appointmentDate' => $data['appointmentDate'],
            'startTime' => $data['startTime'],
            'endTime' => $data['endTime'],
            'maxCapacity' => $data['maxCapacity'],
            'currentBookings' => 0,
            'status' => $data['status'] ?? 'Open',
            'createdAt' => Oess::now(),
            'updatedAt' => Oess::now(),
        ]);

        Oess::audit($request->user(), 'Created Slot', 'Appointment Slots',
            "Created slot {$slot->id} for {$service->serviceName} on {$slot->appointmentDate}");

        return response()->json($slot->load('service'), 201);
    }

    public function update(Request $request, string $id)
    {
        $slot = AppointmentSlot::findOrFail($id);

        $data = $request->validate([
            'serviceId' => ['sometimes', 'required', 'string', 'exists:appointment_services,id'],
            'appointmentDate' => ['sometimes', 'required', 'date', 'date_format:Y-m-d'],
            'startTime' => ['sometimes', 'required', 'date_format:H:i'],
            'endTime' => ['sometimes', 'required', 'date_format:H:i'],
            'maxCapacity' => ['sometimes', 'required', 'integer', 'min:1'],
            'status' => ['sometimes', 'required', 'string', Rule::in(['Open', 'Closed', 'BookedOut'])],
        ]);

        if (isset($data['serviceId'])) {
            $service = AppointmentService::findOrFail($data['serviceId']);
            abort_unless($service->status === 'Active', 422, 'Only active services can have slots.');
        }

        $serviceId = $data['serviceId'] ?? $slot->serviceId;
        $appointmentDate = $data['appointmentDate'] ?? $slot->appointmentDate;
        $startTime = $data['startTime'] ?? $slot->startTime;
        $endTime = $data['endTime'] ?? $slot->endTime;

        if (isset($data['startTime']) || isset($data['endTime'])) {
            abort_unless($endTime > $startTime, 422, 'End time must be after start time.');
        }

        $this->validateAgainstRules($serviceId, $appointmentDate, $startTime, $endTime);

        $slot->fill($data);
        $slot->updatedAt = Oess::now();
        $slot->save();

        Oess::audit($request->user(), 'Updated Slot', 'Appointment Slots',
            "Updated slot {$slot->id} for {$slot->serviceId}");

        return $slot->load('service');
    }

    protected function validateAgainstRules(string $serviceId, string $appointmentDate, string $startTime, string $endTime): void
    {
        $date = Carbon::parse($appointmentDate);
        $dayOfWeek = (int) $date->dayOfWeek;

        $rules = AppointmentBusinessRule::where('isActive', true)
            ->where(function ($query) use ($serviceId, $dayOfWeek) {
                $query->whereNull('serviceId')
                    ->orWhere('serviceId', $serviceId)
                    ->orWhere(function ($scope) use ($dayOfWeek) {
                        $scope->where('ruleType', 'office_hours')
                            ->where('dayOfWeek', $dayOfWeek);
                    });
            })
            ->get();

        foreach ($rules as $rule) {
            if ($rule->ruleType === 'closed_day' && $rule->dayOfWeek === $dayOfWeek) {
                abort(422, 'This date is closed under appointment business rules.');
            }

            if ($rule->ruleType === 'service_closed' && ($rule->serviceId === null || $rule->serviceId === $serviceId)) {
                abort(422, 'This service is closed under appointment business rules.');
            }

            if (($rule->ruleType === 'office_hours' || $rule->ruleType === 'service_hours') && $rule->startTime && $rule->endTime) {
                $overlapStart = $startTime < $rule->startTime;
                $overlapEnd = $endTime > $rule->endTime;
                if ($overlapStart || $overlapEnd) {
                    abort(422, 'This slot falls outside the configured operating hours.');
                }
            }
        }
    }
}
