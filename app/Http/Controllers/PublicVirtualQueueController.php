<?php

namespace App\Http\Controllers;

use App\Models\AppointmentQueueEntry;
use App\Models\AppointmentService;
use App\Support\AppointmentWaitEstimator;
use App\Support\Oess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PublicVirtualQueueController extends Controller
{
    private const ACTIVE_STATUSES = ['WAITING', 'CALLED', 'SERVING'];

    public function __construct(private readonly AppointmentWaitEstimator $waitEstimator) {}

    public function services()
    {
        $today = now('Asia/Manila')->toDateString();

        return AppointmentService::where('isActive', true)
            ->where('publicBookable', true)
            ->where('queueEnabled', true)
            ->orderBy('name')
            ->get()
            ->map(function (AppointmentService $service) use ($today) {
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
                    'issuedCount' => $issuedCount,
                    'remainingCount' => max(0, $service->queueCapacity - $issuedCount),
                    'acceptingTickets' => ! $service->queuePaused && $issuedCount < $service->queueCapacity,
                ];
            });
    }

    public function join(Request $request)
    {
        $data = $request->validate([
            'service_id' => 'required|string|exists:appointment_services,id',
            'applicant_name' => 'required|string|max:255',
            'applicant_email' => 'required|email|max:255',
            'applicant_phone' => 'required|string|max:50',
        ]);
        $today = now('Asia/Manila')->toDateString();
        $accessToken = bin2hex(random_bytes(32));
        $email = strtolower(trim($data['applicant_email']));

        $entry = DB::transaction(function () use ($data, $today, $accessToken, $email) {
            $service = AppointmentService::whereKey($data['service_id'])->lockForUpdate()->firstOrFail();
            if (! $service->isActive || ! $service->publicBookable || ! $service->queueEnabled) {
                abort(422, 'Guest virtual queue tickets are not available for this service.');
            }
            if ($service->queuePaused) {
                abort(409, 'This virtual queue is temporarily paused. Please try again later.');
            }

            $this->lockQueueSequence($service->id, $today);
            if (AppointmentQueueEntry::where('queueDate', $today)
                ->where('serviceId', $service->id)
                ->where('guestEmail', $email)
                ->whereIn('status', self::ACTIVE_STATUSES)
                ->whereNull('appointmentId')
                ->exists()) {
                abort(409, 'This email already has an active ticket for this service today.');
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
                'userId' => null,
                'guestName' => trim($data['applicant_name']),
                'guestEmail' => $email,
                'guestPhone' => trim($data['applicant_phone']),
                'guestAccessTokenHash' => hash('sha256', $accessToken),
                'serviceId' => $service->id,
                'queueDate' => $today,
                'queueNumber' => $queueNumber,
                'status' => 'WAITING',
                'checkedInAt' => $now,
                'createdAt' => $now,
                'updatedAt' => $now,
            ])->load(['service', 'user']);
        }, 5);

        return response()->json([
            'ticket' => $this->ticketPayload($entry),
            'accessToken' => $accessToken,
        ], 201);
    }

    public function retrieve(Request $request)
    {
        $data = $request->validate([
            'ticket_number' => ['required', 'string', 'max:50', 'regex:/^[A-Z][A-Z0-9_-]*-\d{1,8}$/'],
            'access_token' => 'required|string|size:64',
        ]);
        [$serviceCode, $number] = $this->parseTicketNumber($data['ticket_number']);
        $entry = AppointmentQueueEntry::with(['service', 'user'])
            ->where('queueNumber', $number)
            ->where('guestAccessTokenHash', hash('sha256', $data['access_token']))
            ->whereHas('service', fn ($query) => $query->where('code', $serviceCode))
            ->whereNull('appointmentId')
            ->first();

        if (! $entry) {
            abort(404, 'The ticket number or access code is invalid.');
        }

        return response()->json(['ticket' => $this->ticketPayload($entry)]);
    }

    public function cancel(Request $request)
    {
        $data = $request->validate([
            'ticket_number' => ['required', 'string', 'max:50', 'regex:/^[A-Z][A-Z0-9_-]*-\d{1,8}$/'],
            'access_token' => 'required|string|size:64',
        ]);
        [$serviceCode, $number] = $this->parseTicketNumber($data['ticket_number']);
        $entry = DB::transaction(function () use ($data, $serviceCode, $number) {
            $entry = AppointmentQueueEntry::with(['service', 'user'])
                ->where('queueNumber', $number)
                ->where('guestAccessTokenHash', hash('sha256', $data['access_token']))
                ->whereHas('service', fn ($query) => $query->where('code', $serviceCode))
                ->whereNull('appointmentId')
                ->lockForUpdate()
                ->first();
            if (! $entry) {
                abort(404, 'The ticket number or access code is invalid.');
            }
            if ($entry->queueDate !== now('Asia/Manila')->toDateString() || $entry->status !== 'WAITING') {
                abort(409, 'Only a waiting ticket for today can be cancelled.');
            }

            $entry->status = 'CANCELLED';
            $entry->updatedAt = Oess::now();
            $entry->save();

            return $entry->fresh(['service', 'user']);
        }, 5);

        return response()->json(['ticket' => $this->ticketPayload($entry)]);
    }

    private function ticketPayload(AppointmentQueueEntry $entry): array
    {
        $service = $entry->service;
        $payload = [
            'queueEntryId' => $entry->id,
            'queueTicket' => $service->code.'-'.str_pad((string) $entry->queueNumber, 3, '0', STR_PAD_LEFT),
            'serviceId' => $service->id,
            'serviceCode' => $service->code,
            'serviceName' => $service->name,
            'serviceInstructions' => $service->instructions,
            'queuePaused' => $service->queuePaused,
            'serviceAvailable' => $service->isActive,
            'appointmentDate' => $entry->queueDate,
            'studentName' => $entry->guestName,
            'status' => $entry->status,
            'queueNumber' => $entry->queueNumber,
            'queueStatus' => $entry->status,
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

    private function parseTicketNumber(string $ticketNumber): array
    {
        $separator = strrpos($ticketNumber, '-');
        $serviceCode = substr($ticketNumber, 0, $separator);
        $number = (int) substr($ticketNumber, $separator + 1);

        return [$serviceCode, $number];
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
}
