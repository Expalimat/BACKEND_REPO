<?php

namespace App\Http\Controllers;

use App\Models\AppointmentBusinessRule;
use App\Support\Oess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AppointmentBusinessRuleController extends Controller
{
    public function index(Request $request)
    {
        $q = AppointmentBusinessRule::with('service')->orderByDesc('seq');

        if ($request->filled('serviceId')) {
            $q->where('serviceId', $request->query('serviceId'));
        }

        return Oess::paginateIfRequested(
            $q,
            $request,
            ['id', 'name', 'ruleType', 'serviceId', 'dayOfWeek', 'startTime', 'endTime', 'isActive']
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'ruleType' => ['required', 'string', Rule::in(['office_hours', 'closed_day', 'service_closed', 'service_hours'])],
            'serviceId' => ['nullable', 'string', 'exists:appointment_services,id'],
            'dayOfWeek' => ['nullable', 'integer', 'min:0', 'max:6'],
            'startTime' => ['nullable', 'date_format:H:i'],
            'endTime' => ['nullable', 'date_format:H:i'],
            'isActive' => ['nullable', 'boolean'],
        ]);

        $rule = AppointmentBusinessRule::create([
            'id' => Oess::randomId(AppointmentBusinessRule::class, 'BRL'),
            'name' => $data['name'],
            'ruleType' => $data['ruleType'],
            'serviceId' => $data['serviceId'] ?? null,
            'dayOfWeek' => $data['dayOfWeek'] ?? null,
            'startTime' => $data['startTime'] ?? null,
            'endTime' => $data['endTime'] ?? null,
            'isActive' => $data['isActive'] ?? true,
            'createdAt' => Oess::now(),
            'updatedAt' => Oess::now(),
        ]);

        Oess::audit($request->user(), 'Created Booking Rule', 'Appointment Rules',
            "Created business rule {$rule->name} ({$rule->ruleType})");

        return response()->json($rule->load('service'), 201);
    }

    public function update(Request $request, string $id)
    {
        $rule = AppointmentBusinessRule::findOrFail($id);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'ruleType' => ['sometimes', 'required', 'string', Rule::in(['office_hours', 'closed_day', 'service_closed', 'service_hours'])],
            'serviceId' => ['nullable', 'string', 'exists:appointment_services,id'],
            'dayOfWeek' => ['nullable', 'integer', 'min:0', 'max:6'],
            'startTime' => ['nullable', 'date_format:H:i'],
            'endTime' => ['nullable', 'date_format:H:i'],
            'isActive' => ['nullable', 'boolean'],
        ]);

        $rule->fill($data);
        $rule->updatedAt = Oess::now();
        $rule->save();

        Oess::audit($request->user(), 'Updated Booking Rule', 'Appointment Rules',
            "Updated business rule {$rule->name} ({$rule->ruleType})");

        return $rule->load('service');
    }
}
