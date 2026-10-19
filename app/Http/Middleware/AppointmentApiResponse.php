<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AppointmentApiResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! $response instanceof JsonResponse || $response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return $response;
        }

        $data = $response->getData(true);
        if (is_array($data) && ($data['success'] ?? false) === true && array_key_exists('data', $data)) {
            return $response;
        }

        $response->setData([
            'success' => true,
            'message' => $this->message($request),
            'data' => $data,
        ]);

        return $response;
    }

    private function message(Request $request): string
    {
        $action = $request->route()?->getActionMethod();
        $path = $request->path();

        if ($action === 'store') {
            return str_ends_with($path, '/appointments')
                ? 'Appointment created successfully.'
                : 'Appointment service created successfully.';
        }

        return match ($action) {
            'cancel' => 'Appointment cancelled successfully.',
            'reschedule' => 'Appointment rescheduled successfully.',
            'checkIn' => 'Student checked in successfully.',
            'createStaffSlot', 'createSlot' => 'Appointment slot created successfully.',
            'updateSlot' => 'Appointment slot updated successfully.',
            'callNext', 'callQueueEntry', 'overrideCall', 'overrideQueueEntry' => 'Queue entry called successfully.',
            'start', 'startQueueEntry' => 'Service started successfully.',
            'finish' => $request->input('status') === 'NO_SHOW'
                ? 'Appointment marked as no-show successfully.'
                : 'Appointment completed successfully.',
            'completeQueueEntry' => 'Appointment completed successfully.',
            'noShowQueueEntry' => 'Appointment marked as no-show successfully.',
            default => 'Request completed successfully.',
        };
    }
}
