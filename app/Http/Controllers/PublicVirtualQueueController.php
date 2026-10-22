<?php

namespace App\Http\Controllers;

use App\Models\QueueService;
use App\Models\QueueTicket;
use App\Support\Oess;
use App\Support\QueueWaitEstimator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PublicVirtualQueueController extends Controller
{
    public function __construct(private readonly QueueWaitEstimator $waitEstimator) {}

    public function services()
    {
        $today = now('Asia/Manila')->toDateString();

        return QueueService::where('isActive', true)->where('publicBookable', true)->where('queueEnabled', true)
            ->orderBy('name')->get()->map(function (QueueService $service) use ($today) {
                $issued = QueueTicket::where('serviceId', $service->id)->where('queueDate', $today)->count();

                return [
                    'id' => $service->id, 'code' => $service->code, 'name' => $service->name,
                    'instructions' => $service->instructions, 'queuePaused' => $service->queuePaused,
                    'queueCapacity' => $service->queueCapacity, 'issuedCount' => $issued,
                    'remainingCount' => max(0, $service->queueCapacity - $issued),
                    'acceptingTickets' => ! $service->queuePaused && $issued < $service->queueCapacity,
                ];
            });
    }

    public function join(Request $request)
    {
        $data = $request->validate([
            'service_id' => 'required|string|exists:queue_services,id',
            'applicant_name' => 'required|string|max:255',
            'applicant_email' => 'required|email|max:255',
            'applicant_phone' => 'required|string|max:50',
        ]);
        $today = now('Asia/Manila')->toDateString();
        $token = bin2hex(random_bytes(32));
        $email = strtolower(trim($data['applicant_email']));
        $ticket = DB::transaction(function () use ($data, $today, $token, $email) {
            $service = QueueService::whereKey($data['service_id'])->lockForUpdate()->firstOrFail();
            if (! $service->isActive || ! $service->publicBookable || ! $service->queueEnabled) {
                abort(422, 'Guest virtual queue tickets are not available for this service.');
            }
            if ($service->queuePaused) {
                abort(409, 'This virtual queue is temporarily paused. Please try again later.');
            }
            $this->lockSequence($service->id, $today);
            if (QueueTicket::where('serviceId', $service->id)->where('queueDate', $today)->where('guestEmail', $email)
                ->whereIn('status', ['WAITING', 'CALLED', 'SERVING'])->exists()) {
                abort(409, 'This email already has an active ticket for this service today.');
            }
            $issued = QueueTicket::where('serviceId', $service->id)->where('queueDate', $today)->count();
            if ($issued >= $service->queueCapacity) {
                abort(409, 'This virtual queue has reached its daily capacity.');
            }
            $number = $this->nextNumber($service->id, $today);
            $now = Oess::now();

            return QueueTicket::create([
                'id' => (string) Str::uuid(), 'userId' => null, 'guestName' => trim($data['applicant_name']),
                'guestEmail' => $email, 'guestPhone' => trim($data['applicant_phone']),
                'guestAccessTokenHash' => hash('sha256', $token), 'serviceId' => $service->id,
                'queueDate' => $today, 'queueNumber' => $number, 'status' => 'WAITING',
                'checkedInAt' => $now, 'createdAt' => $now, 'updatedAt' => $now,
            ])->load(['service', 'user']);
        }, 5);

        return response()->json(['ticket' => $this->ticketPayload($ticket), 'accessToken' => $token], 201);
    }

    public function retrieve(Request $request)
    {
        $data = $request->validate([
            'ticket_number' => ['required', 'string', 'max:50', 'regex:/^[A-Z][A-Z0-9_-]*-\d{1,8}$/'],
            'access_token' => 'required|string|size:64',
        ]);
        [$serviceCode, $number] = $this->parseTicketNumber($data['ticket_number']);
        $ticket = QueueTicket::with(['service', 'user'])->where('queueNumber', $number)
            ->where('guestAccessTokenHash', hash('sha256', $data['access_token']))
            ->whereHas('service', fn ($query) => $query->where('code', $serviceCode))
            ->whereNull('userId')->first();
        if (! $ticket) {
            abort(404, 'The ticket number or access code is invalid.');
        }

        return response()->json(['ticket' => $this->ticketPayload($ticket)]);
    }

    public function cancel(Request $request)
    {
        $data = $request->validate([
            'ticket_number' => ['required', 'string', 'max:50', 'regex:/^[A-Z][A-Z0-9_-]*-\d{1,8}$/'],
            'access_token' => 'required|string|size:64',
        ]);
        [$serviceCode, $number] = $this->parseTicketNumber($data['ticket_number']);
        $ticket = DB::transaction(function () use ($data, $serviceCode, $number) {
            $ticket = QueueTicket::with(['service', 'user'])->where('queueNumber', $number)
                ->where('guestAccessTokenHash', hash('sha256', $data['access_token']))
                ->whereHas('service', fn ($query) => $query->where('code', $serviceCode))
                ->whereNull('userId')->lockForUpdate()->first();
            if (! $ticket) {
                abort(404, 'The ticket number or access code is invalid.');
            }
            if ($ticket->queueDate !== now('Asia/Manila')->toDateString() || $ticket->status !== 'WAITING') {
                abort(409, 'Only a waiting ticket for today can be cancelled.');
            }
            $ticket->status = 'CANCELLED';
            $ticket->updatedAt = Oess::now();
            $ticket->save();

            return $ticket->fresh(['service', 'user']);
        }, 5);

        return response()->json(['ticket' => $this->ticketPayload($ticket)]);
    }

    private function ticketPayload(QueueTicket $ticket): array
    {
        $service = $ticket->service;
        $payload = [
            'queueEntryId' => $ticket->id,
            'queueTicket' => $service->code.'-'.str_pad((string) $ticket->queueNumber, 3, '0', STR_PAD_LEFT),
            'serviceId' => $service->id, 'serviceCode' => $service->code, 'serviceName' => $service->name,
            'serviceInstructions' => $service->instructions, 'queuePaused' => $service->queuePaused,
            'serviceAvailable' => $service->isActive && $service->queueEnabled,
            'queueDate' => $ticket->queueDate, 'isGuest' => true, 'studentName' => $ticket->guestName,
            'status' => $ticket->status, 'queueNumber' => $ticket->queueNumber, 'queueStatus' => $ticket->status,
            'checkedInAt' => $ticket->checkedInAt, 'calledAt' => $ticket->calledAt,
            'serviceStartedAt' => $ticket->serviceStartedAt, 'completedAt' => $ticket->completedAt,
            'createdAt' => $ticket->createdAt, 'walkIn' => true,
        ];

        return $ticket->status === 'WAITING' ? array_merge($payload, $this->waitEstimator->estimate($ticket)) : $payload;
    }

    private function parseTicketNumber(string $ticketNumber): array
    {
        $separator = strrpos($ticketNumber, '-');

        return [substr($ticketNumber, 0, $separator), (int) substr($ticketNumber, $separator + 1)];
    }

    private function lockSequence(string $serviceId, string $date): void
    {
        DB::table('queue_sequences')->insertOrIgnore(['serviceId' => $serviceId, 'queueDate' => $date, 'lastNumber' => 0]);
        DB::table('queue_sequences')->where('serviceId', $serviceId)->where('queueDate', $date)
            ->update(['lastNumber' => DB::raw(DB::getQueryGrammar()->wrap('lastNumber'))]);
        DB::table('queue_sequences')->where('serviceId', $serviceId)->where('queueDate', $date)->lockForUpdate()->first();
    }

    private function nextNumber(string $serviceId, string $date): int
    {
        $sequence = DB::table('queue_sequences')->where('serviceId', $serviceId)->where('queueDate', $date)->lockForUpdate()->first();
        $number = (int) $sequence->lastNumber + 1;
        DB::table('queue_sequences')->where('serviceId', $serviceId)->where('queueDate', $date)->update(['lastNumber' => $number]);

        return $number;
    }
}
