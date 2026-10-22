<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\AppointmentService;
use App\Models\AppointmentSlot;
use App\Support\Oess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PublicAppointmentController extends Controller
{
    private const CAPACITY_STATUSES = ['CONFIRMED', 'WAITING', 'SERVING', 'COMPLETED'];

    public function services()
    {
        return AppointmentService::where('isActive', true)
            ->where('publicBookable', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'description', 'instructions', 'serviceDurationMinutes', 'bookingWindowDays']);
    }

    public function availability(Request $request)
    {
        $today = now('Asia/Manila')->toDateString();
        $data = $request->validate([
            'service_id' => 'required|string|exists:appointment_services,id',
            'date_from' => 'nullable|date_format:Y-m-d|after_or_equal:'.$today,
            'date_to' => 'nullable|date_format:Y-m-d|after_or_equal:date_from',
        ]);
        $service = AppointmentService::whereKey($data['service_id'])
            ->where('isActive', true)->where('publicBookable', true)->firstOrFail();
        $from = $data['date_from'] ?? $today;
        $to = $data['date_to'] ?? now('Asia/Manila')->addDays($service->bookingWindowDays)->toDateString();

        return AppointmentSlot::where('serviceId', $service->id)->where('status', 'Open')
            ->whereBetween('appointmentDate', [$from, min($to, now('Asia/Manila')->addDays($service->bookingWindowDays)->toDateString())])
            ->whereDoesntHave('service.closures', fn ($q) => $q->whereColumn('closedDate', 'appointment_slots.appointmentDate'))
            ->whereRaw('(SELECT COUNT(*) FROM "appointments" WHERE "appointments"."slotId" = "appointment_slots"."id" AND "appointments"."status" IN (?, ?, ?, ?)) < "appointment_slots"."capacity"', self::CAPACITY_STATUSES)
            ->orderBy('appointmentDate')->orderBy('startTime')->get()
            ->filter(fn ($slot) => $slot->appointmentDate !== $today || $slot->startTime > now('Asia/Manila')->format('H:i:s'))
            ->map(fn ($slot) => $this->slotPayload($slot))->values();
    }

    public function book(Request $request)
    {
        $data = $request->validate([
            'slot_id' => 'required|string|exists:appointment_slots,id',
            'applicant_name' => 'required|string|max:255',
            'applicant_email' => 'required|email|max:255',
            'applicant_phone' => 'required|string|max:50',
            'purpose' => 'nullable|string|max:2000',
        ]);

        $appointment = DB::transaction(function () use ($data) {
            $slot = AppointmentSlot::whereKey($data['slot_id'])->lockForUpdate()->firstOrFail();
            $service = AppointmentService::whereKey($slot->serviceId)->lockForUpdate()->firstOrFail();
            $today = now('Asia/Manila')->toDateString();
            if (! $service->isActive || ! $service->publicBookable || $slot->status !== 'Open' ||
                $slot->appointmentDate < $today || $slot->appointmentDate > now('Asia/Manila')->addDays($service->bookingWindowDays)->toDateString() ||
                ($slot->appointmentDate === $today && $slot->startTime <= now('Asia/Manila')->format('H:i:s')) ||
                $service->closures()->where('closedDate', $slot->appointmentDate)->exists()) {
                abort(422, 'This appointment time is not available for booking.');
            }
            if (Appointment::where('slotId', $slot->id)->where('applicantEmail', $data['applicant_email'])->whereIn('status', self::CAPACITY_STATUSES)->exists()) {
                abort(409, 'You already have an appointment for this time.');
            }
            if (Appointment::where('slotId', $slot->id)->whereIn('status', self::CAPACITY_STATUSES)->count() >= $slot->capacity) {
                abort(409, 'This appointment time has just filled. Choose another available time.');
            }
            DB::table('appointment_daily_sequences')->insertOrIgnore(['appointmentDate' => $slot->appointmentDate, 'lastNumber' => 0]);
            $sequence = DB::table('appointment_daily_sequences')->where('appointmentDate', $slot->appointmentDate)->lockForUpdate()->first();
            $number = $sequence->lastNumber + 1;
            DB::table('appointment_daily_sequences')->where('id', $sequence->id)->update(['lastNumber' => $number]);
            return Appointment::create([
                'id' => (string) Str::uuid(), 'appointmentNumber' => 'PNC-'.str_replace('-', '', $slot->appointmentDate).'-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT),
                'serviceId' => $service->id, 'slotId' => $slot->id, 'studentNumber' => null, 'studentName' => $data['applicant_name'],
                'applicantEmail' => $data['applicant_email'], 'applicantPhone' => $data['applicant_phone'], 'purpose' => $data['purpose'] ?? null,
                'appointmentDate' => $slot->appointmentDate, 'serviceNameSnapshot' => $service->name, 'startTimeSnapshot' => $slot->startTime,
                'endTimeSnapshot' => $slot->endTime, 'serviceDurationMinutes' => $service->serviceDurationMinutes,
                'cancellationCutoffMinutes' => $service->cancellationCutoffMinutes, 'lateArrivalGraceMinutes' => $service->lateArrivalGraceMinutes,
                'earlyArrivalWindowMinutes' => $service->earlyArrivalWindowMinutes, 'status' => 'CONFIRMED', 'createdAt' => Oess::now(), 'updatedAt' => Oess::now(),
            ]);
        }, 5);

        return response()->json(['reference' => $appointment->appointmentNumber, 'appointment' => $appointment], 201);
    }

    private function slotPayload(AppointmentSlot $slot): array
    {
        $booked = Appointment::where('slotId', $slot->id)->whereIn('status', self::CAPACITY_STATUSES)->count();
        return ['id' => $slot->id, 'serviceId' => $slot->serviceId, 'appointmentDate' => $slot->appointmentDate,
            'startTime' => substr($slot->startTime, 0, 5), 'endTime' => substr($slot->endTime, 0, 5),
            'capacity' => $slot->capacity, 'available' => max(0, $slot->capacity - $booked)];
    }
}
