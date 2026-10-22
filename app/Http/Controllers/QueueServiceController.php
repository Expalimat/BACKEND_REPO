<?php

namespace App\Http\Controllers;

use App\Models\QueueService;
use App\Models\User;
use App\Support\Oess;
use App\Support\QueueAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QueueServiceController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $services = $user->role === 'Student'
            ? QueueService::where('isActive', true)
            : QueueAccess::services($user);

        return $services->orderBy('name')->get();
    }

    public function staffAssignments(Request $request, string $serviceId)
    {
        QueueService::findOrFail($serviceId);

        return User::query()->join('queue_service_staff', 'queue_service_staff.userId', '=', 'users.id')
            ->where('queue_service_staff.serviceId', $serviceId)
            ->whereIn('users.role', ['Registrar', 'Department Staff'])
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.username', 'users.role', 'users.department']);
    }

    public function updateStaffAssignments(Request $request, string $serviceId)
    {
        $request->merge(['userIds' => $request->input('user_ids', $request->input('userIds'))]);
        $data = $request->validate([
            'userIds' => 'present|array|max:100',
            'userIds.*' => 'required|string|distinct|exists:users,id',
        ]);
        $staff = User::whereIn('id', $data['userIds'])->get();
        if ($staff->contains(fn (User $user) => $user->status !== 'Active' ||
            ! in_array($user->role, ['Registrar', 'Department Staff'], true))) {
            abort(422, 'Only active Registrar and Department Staff accounts can be assigned to queue services.');
        }

        $service = QueueService::findOrFail($serviceId);
        DB::transaction(function () use ($serviceId, $data, $request) {
            DB::table('queue_service_staff')->where('serviceId', $serviceId)->delete();
            foreach ($data['userIds'] as $userId) {
                DB::table('queue_service_staff')->insert([
                    'serviceId' => $serviceId,
                    'userId' => $userId,
                    'assignedByUserId' => $request->user()->id,
                    'createdAt' => Oess::now(),
                ]);
            }
        });

        Oess::audit($request->user(), 'Updated Queue Service Staff', 'Virtual Queue',
            'Updated staff assignments for queue service '.$service->code);

        return response()->json($this->staffAssignments($request, $serviceId));
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->serviceRules(true));
        $now = Oess::now();
        $service = QueueService::create([
            'id' => (string) Str::uuid(),
            'code' => strtoupper($data['code']),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'instructions' => $data['instructions'] ?? null,
            'serviceDurationMinutes' => $data['serviceDurationMinutes'] ?? 30,
            'isActive' => $data['isActive'] ?? true,
            'publicBookable' => $data['publicBookable'] ?? false,
            'queueEnabled' => $data['queueEnabled'] ?? false,
            'queuePaused' => $data['queuePaused'] ?? false,
            'queueCapacity' => $data['queueCapacity'] ?? 50,
            'createdAt' => $now,
            'updatedAt' => $now,
        ]);

        Oess::audit($request->user(), 'Created Queue Service', 'Virtual Queue', "Created queue service {$service->code}: {$service->name}");

        return response()->json($service, 201);
    }

    public function update(Request $request, string $serviceId)
    {
        $data = $request->validate($this->serviceRules(false));
        $service = DB::transaction(function () use ($serviceId, $data) {
            $service = QueueService::whereKey($serviceId)->lockForUpdate()->firstOrFail();
            if (isset($data['queueCapacity'])) {
                $issued = QueueService::findOrFail($serviceId)->tickets()
                    ->where('queueDate', now('Asia/Manila')->toDateString())->count();
                if ($data['queueCapacity'] < $issued) {
                    abort(422, "Queue capacity cannot be lower than the {$issued} tickets already issued today.");
                }
            }
            $service->fill($data);
            $service->updatedAt = Oess::now();
            $service->save();

            return $service;
        }, 5);

        Oess::audit($request->user(), 'Updated Queue Service', 'Virtual Queue', "Updated queue service {$service->code}: {$service->name}");

        return response()->json($service);
    }

    public function setQueuePause(Request $request, string $serviceId)
    {
        QueueAccess::assertServiceAccess($request->user(), $serviceId);
        $data = $request->validate(['queuePaused' => 'required|boolean']);
        $service = DB::transaction(function () use ($serviceId, $data) {
            $service = QueueService::whereKey($serviceId)->lockForUpdate()->firstOrFail();
            $service->queuePaused = $data['queuePaused'];
            $service->updatedAt = Oess::now();
            $service->save();

            return $service;
        }, 5);

        Oess::audit($request->user(), $service->queuePaused ? 'Paused Virtual Queue Intake' : 'Resumed Virtual Queue Intake',
            'Virtual Queue', "{$service->code}: new ticket intake ".($service->queuePaused ? 'paused' : 'resumed'));

        return response()->json($service);
    }

    private function serviceRules(bool $creating): array
    {
        $nameRules = $creating
            ? ['required', 'string', 'max:255']
            : ['sometimes', 'required', 'string', 'max:255'];

        return [
            'code' => [$creating ? 'required' : 'sometimes', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name' => $nameRules,
            'description' => 'sometimes|nullable|string|max:2000',
            'instructions' => 'sometimes|nullable|string|max:5000',
            'isActive' => 'sometimes|boolean',
            'publicBookable' => 'sometimes|boolean',
            'queueEnabled' => 'sometimes|boolean',
            'queuePaused' => 'sometimes|boolean',
            'queueCapacity' => 'sometimes|integer|min:1|max:5000',
            'serviceDurationMinutes' => 'sometimes|integer|min:1|max:1440',
        ];
    }
}
