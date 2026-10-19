<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\AppointmentQueueEntry;
use App\Models\AppointmentService;
use App\Support\Oess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AppointmentQueueController extends Controller
{
    private const STAFF_ROLES = ['Registrar', 'Cashier', 'Department Staff', 'Administrator'];

    public function index(Request $request)
    {
        $q = AppointmentQueueEntry::with('appointment')->orderByDesc('seq');

        if ($request->filled('queueScope')) {
            $q->where('queueScope', $request->query('queueScope'));
        }

        if ($request->filled('queueDate')) {
            $q->where('queueDate', $request->query('queueDate'));
        }

        return Oess::paginateIfRequested(
            $q,
            $request,
            ['id', 'appointmentId', 'queueNumber', 'queueDate', 'queueScope', 'status']
        );
    }

    public function statusSummary(Request $request, string $id)
    {
        $entry = AppointmentQueueEntry::with('appointment')->findOrFail($id);
        $queueStatus = $this->queueStatusSummary($entry);

        if ($request->user()?->role === 'Student' && $entry->appointment?->studentNumber !== ($request->user()->studentNumber ?? '')) {
            abort(403, 'You may only view your own queue status.');
        }

        return response()->json($queueStatus);
    }

    public function checkIn(Request $request, string $id)
    {
        $entry = AppointmentQueueEntry::findOrFail($id);
        $this->ensureStaff($request);

        $entry->status = 'CHECKED_IN';
        $entry->checkInAt = Oess::now();
        $entry->updatedAt = Oess::now();
        $entry->save();

        $appointment = $entry->appointment;
        if ($appointment) {
            $appointment->status = 'CHECKED_IN';
            $appointment->updatedAt = Oess::now();
            $appointment->save();
        }

        return $entry->fresh();
    }

    public function callNext(Request $request)
    {
        $this->ensureStaff($request);

        $data = $request->validate([
            'queueScope' => ['required', 'string'],
            'queueDate' => ['required', 'date', 'date_format:Y-m-d'],
        ]);

        $entry = AppointmentQueueEntry::where('queueScope', $data['queueScope'])
            ->where('queueDate', $data['queueDate'])
            ->whereIn('status', ['WAITING', 'CHECKED_IN'])
            ->orderBy('queueNumber')
            ->firstOrFail();

        $entry->status = 'SERVING';
        $entry->calledAt = Oess::now();
        $entry->updatedAt = Oess::now();
        $entry->save();

        $appointment = $entry->appointment;
        if ($appointment) {
            $appointment->status = 'SERVING';
            $appointment->updatedAt = Oess::now();
            $appointment->save();
        }

        return $entry->fresh();
    }

    public function startService(Request $request, string $id)
    {
        $entry = AppointmentQueueEntry::findOrFail($id);
        $this->ensureStaff($request);

        $entry->status = 'SERVING';
        $entry->serviceStartAt = Oess::now();
        $entry->updatedAt = Oess::now();
        $entry->save();

        $appointment = $entry->appointment;
        if ($appointment) {
            $appointment->status = 'SERVING';
            $appointment->updatedAt = Oess::now();
            $appointment->save();
        }

        return $entry->fresh();
    }

    public function complete(Request $request, string $id)
    {
        $entry = AppointmentQueueEntry::findOrFail($id);
        $this->ensureStaff($request);

        $entry->status = 'COMPLETED';
        $entry->completionAt = Oess::now();
        $entry->updatedAt = Oess::now();
        $entry->save();

        $appointment = $entry->appointment;
        if ($appointment) {
            $appointment->status = 'COMPLETED';
            $appointment->updatedAt = Oess::now();
            $appointment->save();
        }

        return $entry->fresh();
    }

    public function markNoShow(Request $request, string $id)
    {
        $entry = AppointmentQueueEntry::findOrFail($id);
        $this->ensureStaff($request);

        $entry->status = 'NO_SHOW';
        $entry->updatedAt = Oess::now();
        $entry->save();

        $appointment = $entry->appointment;
        if ($appointment) {
            $appointment->status = 'NO_SHOW';
            $appointment->updatedAt = Oess::now();
            $appointment->save();
        }

        return $entry->fresh();
    }

    public function cancel(Request $request, string $id)
    {
        $entry = AppointmentQueueEntry::findOrFail($id);
        $this->ensureStaff($request);

        $entry->status = 'CANCELLED';
        $entry->updatedAt = Oess::now();
        $entry->save();

        $appointment = $entry->appointment;
        if ($appointment) {
            $appointment->status = 'CANCELLED';
            $appointment->updatedAt = Oess::now();
            $appointment->save();
        }

        return $entry->fresh();
    }

    public function overrideStatus(Request $request, string $id)
    {
        $this->ensureStaff($request);

        $entry = AppointmentQueueEntry::with('appointment')->findOrFail($id);
        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(['WAITING', 'CHECKED_IN', 'SERVING', 'COMPLETED', 'NO_SHOW', 'CANCELLED'])],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $entry->status = $data['status'];
        $entry->overrideReason = $data['reason'];
        $entry->updatedAt = Oess::now();
        $entry->save();

        if ($entry->appointment) {
            $entry->appointment->status = $data['status'];
            $entry->appointment->overrideReason = $data['reason'];
            $entry->appointment->updatedAt = Oess::now();
            $entry->appointment->save();
        }

        return $entry->fresh();
    }

    protected function queueStatusSummary(AppointmentQueueEntry $entry): array
    {
        $scope = $entry->queueScope;
        $date = $entry->queueDate;

        $position = AppointmentQueueEntry::where('queueScope', $scope)
            ->where('queueDate', $date)
            ->whereIn('status', ['WAITING', 'CHECKED_IN'])
            ->where('queueNumber', '<', $entry->queueNumber)
            ->count() + 1;

        $estimatedMinutes = 0;
        if ($entry->appointment) {
            $service = $entry->appointment->service;
            if ($service && $service->estimatedDuration) {
                $estimatedMinutes = $this->parseDurationMinutes($service->estimatedDuration);
            }
        }

        $estimatedWaitingMinutes = $position > 0 ? ($position - 1) * $estimatedMinutes : 0;

        return [
            'queueNumber' => $entry->queueNumber,
            'queuePosition' => $position,
            'estimatedWaitingMinutes' => $estimatedWaitingMinutes,
            'status' => $entry->status,
            'overrideReason' => $entry->overrideReason,
        ];
    }

    protected function parseDurationMinutes(string $duration): int
    {
        if (preg_match('/(\d+)\s*minute/i', $duration, $matches)) {
            return (int) $matches[1];
        }

        if (preg_match('/(\d+)\s*hour/i', $duration, $matches)) {
            return (int) $matches[1] * 60;
        }

        return 15;
    }

    protected function ensureStaff(Request $request): void
    {
        $user = $request->user();
        abort_unless($user && in_array($user->role, self::STAFF_ROLES, true), 403,
            'Only authorized staff can operate the queue.');
    }
}
