<?php

namespace App\Http\Controllers;

use App\Models\AppointmentService;
use App\Support\Oess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AppointmentServiceController extends Controller
{
    public function index(Request $request)
    {
        $q = AppointmentService::query()->orderByDesc('seq');

        return Oess::paginateIfRequested(
            $q,
            $request,
            ['id', 'serviceName', 'status', 'estimatedDuration']
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'serviceName' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'requiredDocuments' => ['nullable', 'string', 'max:1000'],
            'estimatedDuration' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', Rule::in(['Active', 'Inactive'])],
        ]);

        $service = AppointmentService::create([
            'id' => Oess::randomId(AppointmentService::class, 'SRV'),
            'serviceName' => $data['serviceName'],
            'description' => $data['description'] ?? '',
            'instructions' => $data['instructions'] ?? '',
            'requiredDocuments' => $data['requiredDocuments'] ?? '',
            'estimatedDuration' => $data['estimatedDuration'] ?? '30 minutes',
            'status' => $data['status'] ?? 'Active',
            'createdAt' => Oess::now(),
            'updatedAt' => Oess::now(),
        ]);

        Oess::audit($request->user(), 'Created Service', 'Appointment Services',
            "Created service {$service->serviceName} ({$service->id})");

        return response()->json($service, 201);
    }

    public function update(Request $request, string $id)
    {
        $service = AppointmentService::findOrFail($id);

        $data = $request->validate([
            'serviceName' => ['sometimes', 'required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'requiredDocuments' => ['nullable', 'string', 'max:1000'],
            'estimatedDuration' => ['nullable', 'string', 'max:50'],
            'status' => ['sometimes', 'required', 'string', Rule::in(['Active', 'Inactive'])],
        ]);

        $service->fill($data);
        $service->updatedAt = Oess::now();
        $service->save();

        Oess::audit($request->user(), 'Updated Service', 'Appointment Services',
            "Updated service {$service->serviceName} ({$service->id})");

        return $service;
    }
}
