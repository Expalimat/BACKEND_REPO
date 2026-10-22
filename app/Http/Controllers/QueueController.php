<?php

namespace App\Http\Controllers;

use App\Models\QueueService;
use App\Models\QueueTicket;
use App\Models\User;
use App\Support\Oess;
use App\Support\QueueAccess;
use App\Support\QueueWaitEstimator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QueueController extends Controller
{
    private const ACTIVE_STATUSES = ['WAITING', 'CALLED', 'SERVING'];

    public function __construct(private readonly QueueWaitEstimator $waitEstimator) {}

    public function studentServices(Request $request)
    {
        $this->ensureStudent($request->user());
        $today = now('Asia/Manila')->toDateString();

        return QueueService::where('isActive', true)->where('queueEnabled', true)->orderBy('name')->get()
            ->map(fn (QueueService $service) => $this->servicePayload($service, $today));
    }

    public function studentTickets(Request $request)
    {
        $user = $request->user();
        $this->ensureStudent($user);

        return QueueTicket::with(['service', 'user'])->where('userId', $user->id)
            ->where('queueDate', now('Asia/Manila')->toDateString())->whereIn('status', self::ACTIVE_STATUSES)
            ->orderBy('queueNumber')->get()->map(fn (QueueTicket $ticket) => $this->ticketPayload($ticket));
    }

    public function joinStudent(Request $request)
    {
        $user = $request->user();
        $this->ensureStudent($user);
        $request->merge(['serviceId' => $request->input('service_id', $request->input('serviceId'))]);
        $data = $request->validate(['serviceId' => 'required|string|exists:queue_services,id']);
        $today = now('Asia/Manila')->toDateString();

        $ticket = DB::transaction(function () use ($user, $data, $today) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $service = QueueService::whereKey($data['serviceId'])->lockForUpdate()->firstOrFail();
            if (! $service->isActive || ! $service->queueEnabled) {
                abort(422, 'Virtual queue tickets are not available for this service.');
            }
            if ($service->queuePaused) {
                abort(409, 'This virtual queue is temporarily paused. Please try again later.');
            }
            $this->lockSequence($service->id, $today);
            if (QueueTicket::where('queueDate', $today)->where('userId', $user->id)->whereIn('status', self::ACTIVE_STATUSES)->exists()) {
                abort(409, 'You already have an active queue ticket today.');
            }
            $this->assertCapacity($service, $today);
            $number = $this->nextNumber($service->id, $today);
            $now = Oess::now();

            return QueueTicket::create([
                'id' => (string) Str::uuid(), 'userId' => $user->id, 'serviceId' => $service->id,
                'queueDate' => $today, 'queueNumber' => $number, 'status' => 'WAITING',
                'checkedInAt' => $now, 'createdAt' => $now, 'updatedAt' => $now,
            ])->load(['service', 'user']);
        }, 5);

        Oess::audit($user, 'Joined Virtual Queue', 'Virtual Queue',
            "Joined {$ticket->service->code} queue with ticket {$this->ticketNumber($ticket)}");

        return response()->json($this->ticketPayload($ticket), 201);
    }

    public function cancelStudent(Request $request, string $queueEntryId)
    {
        $ticket = DB::transaction(function () use ($request, $queueEntryId) {
            $ticket = QueueTicket::with(['service', 'user'])->where('userId', $request->user()->id)
                ->whereKey($queueEntryId)->lockForUpdate()->firstOrFail();
            if ($ticket->queueDate !== now('Asia/Manila')->toDateString() || $ticket->status !== 'WAITING') {
                abort(409, 'Only your waiting ticket for today can be cancelled.');
            }
            $ticket->status = 'CANCELLED';
            $ticket->updatedAt = Oess::now();
            $ticket->save();

            return $ticket;
        }, 5);
        Oess::audit($request->user(), 'Cancelled Virtual Queue Ticket', 'Virtual Queue', "Cancelled {$this->ticketNumber($ticket)}");

        return response()->json($this->ticketPayload($ticket));
    }

    public function staffIndex(Request $request)
    {
        $request->merge(['serviceId' => $request->input('service_id')]);
        $data = $request->validate([
            'serviceId' => 'nullable|string|exists:queue_services,id',
            'queue_date' => 'nullable|date_format:Y-m-d',
            'status' => 'nullable|in:WAITING,CALLED,SERVING',
        ]);
        if (! empty($data['serviceId'])) {
            QueueAccess::assertServiceAccess($request->user(), $data['serviceId']);
        }
        $query = QueueAccess::tickets($request->user())->with(['service', 'user'])
            ->where('queueDate', $data['queue_date'] ?? now('Asia/Manila')->toDateString())
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->orderBy('serviceId')->orderByRaw('CASE WHEN status = ? THEN 0 WHEN status = ? THEN 1 ELSE 2 END', ['SERVING', 'CALLED'])
            ->orderBy('queueNumber');
        if (! empty($data['serviceId'])) {
            $query->where('serviceId', $data['serviceId']);
        }
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        return $query->get()->map(fn (QueueTicket $ticket) => $this->staffTicketPayload($ticket));
    }

    public function serviceQueue(Request $request, string $serviceId)
    {
        QueueAccess::assertServiceAccess($request->user(), $serviceId);
        $service = QueueService::findOrFail($serviceId);
        $tickets = QueueTicket::with(['service', 'user'])->where('serviceId', $serviceId)
            ->where('queueDate', now('Asia/Manila')->toDateString())->whereIn('status', self::ACTIVE_STATUSES)
            ->orderByRaw('CASE WHEN status = ? THEN 0 WHEN status = ? THEN 1 ELSE 2 END', ['SERVING', 'CALLED'])
            ->orderBy('queueNumber')->get()->map(fn (QueueTicket $ticket) => $this->staffTicketPayload($ticket));

        return response()->json([
            'service' => ['id' => $service->id, 'code' => $service->code, 'name' => $service->name],
            'tickets' => $tickets->values(),
        ]);
    }

    public function display()
    {
        $today = now('Asia/Manila')->toDateString();
        $services = QueueService::where('isActive', true)->where('queueEnabled', true)->orderBy('name')->get();

        return response()->json([
            'services' => $services->map(function (QueueService $service) use ($today) {
                $current = QueueTicket::where('serviceId', $service->id)->where('queueDate', $today)
                    ->whereIn('status', ['CALLED', 'SERVING'])->orderBy('calledAt')->first();

                return [
                    'serviceCode' => $service->code,
                    'serviceName' => $service->name,
                    'nowServing' => $current ? $this->ticketNumber($current) : null,
                    'waitingCount' => QueueTicket::where('serviceId', $service->id)->where('queueDate', $today)->where('status', 'WAITING')->count(),
                ];
            }),
        ]);
    }

    public function call(Request $request, string $queueEntryId)
    {
        return $this->transition($request, $queueEntryId, 'call');
    }

    public function start(Request $request, string $queueEntryId)
    {
        return $this->transition($request, $queueEntryId, 'start');
    }

    public function recall(Request $request, string $queueEntryId)
    {
        return $this->transition($request, $queueEntryId, 'recall');
    }

    public function complete(Request $request, string $queueEntryId)
    {
        return $this->transition($request, $queueEntryId, 'complete');
    }

    public function noShow(Request $request, string $queueEntryId)
    {
        return $this->transition($request, $queueEntryId, 'no-show');
    }

    public function cancel(Request $request, string $queueEntryId)
    {
        return $this->transition($request, $queueEntryId, 'cancel');
    }

    public function override(Request $request, string $queueEntryId)
    {
        return $this->transition($request, $queueEntryId, 'override');
    }

    private function transition(Request $request, string $ticketId, string $action)
    {
        $data = $request->validate($action === 'override'
            ? ['status' => 'required|in:WAITING,CALLED,SERVING,COMPLETED,NO_SHOW,CANCELLED', 'reason' => 'required|string|max:1000']
            : ['reason' => 'nullable|string|max:1000']);
        $actor = $request->user();
        $ticket = DB::transaction(function () use ($actor, $ticketId, $action, $data) {
            $ticket = QueueAccess::tickets($actor)->whereKey($ticketId)->lockForUpdate()->firstOrFail();
            QueueAccess::assertServiceAccess($actor, $ticket->serviceId);
            $newStatus = match ($action) {
                'call' => 'CALLED', 'start' => 'SERVING', 'complete' => 'COMPLETED',
                'no-show' => 'NO_SHOW', 'cancel' => 'CANCELLED', 'override' => $data['status'], default => $ticket->status,
            };
            $allowed = match ($action) {
                'call' => ['WAITING'], 'start' => ['CALLED'], 'recall' => ['CALLED'],
                'complete' => ['SERVING'], 'no-show' => ['CALLED'], 'cancel' => ['WAITING', 'CALLED'],
                'override' => ['WAITING', 'CALLED', 'SERVING', 'COMPLETED', 'NO_SHOW', 'CANCELLED'],
            };
            if (! in_array($ticket->status, $allowed, true)) {
                abort(409, 'This queue ticket cannot be changed with the requested action.');
            }
            if (in_array($newStatus, ['CALLED', 'SERVING'], true) && $ticket->status !== $newStatus) {
                QueueService::whereKey($ticket->serviceId)->lockForUpdate()->firstOrFail();
                $busy = QueueTicket::where('serviceId', $ticket->serviceId)->where('queueDate', $ticket->queueDate)
                    ->whereIn('status', ['CALLED', 'SERVING'])->where('id', '!=', $ticket->id)->exists();
                if ($busy) {
                    abort(409, 'Complete or mark the current service as no-show before calling another ticket.');
                }
            }
            $now = Oess::now();
            if ($action === 'recall') {
                $ticket->calledAt = $now;
            } else {
                $ticket->status = $newStatus;
                if ($newStatus === 'CALLED') {
                    $ticket->calledAt = $now;
                }
                if ($newStatus === 'SERVING') {
                    $ticket->serviceStartedAt = $now;
                }
                if ($newStatus === 'COMPLETED') {
                    $ticket->completedAt = $now;
                }
                if ($action === 'override') {
                    $ticket->overrideReason = $data['reason'];
                }
            }
            $ticket->updatedAt = $now;
            $ticket->save();

            return $ticket->fresh(['service', 'user']);
        }, 5);

        Oess::audit($actor, 'Queue Ticket '.ucfirst($action), 'Virtual Queue',
            "{$ticket->service->code} {$this->ticketNumber($ticket)}: {$ticket->status}".(! empty($data['reason']) ? " ({$data['reason']})" : ''));

        return response()->json($this->ticketPayload($ticket));
    }

    private function servicePayload(QueueService $service, string $date): array
    {
        $issued = QueueTicket::where('serviceId', $service->id)->where('queueDate', $date)->count();
        $active = QueueTicket::where('serviceId', $service->id)->where('queueDate', $date)->whereIn('status', self::ACTIVE_STATUSES)->count();

        return [
            'id' => $service->id, 'code' => $service->code, 'name' => $service->name,
            'description' => $service->description, 'instructions' => $service->instructions,
            'queuePaused' => $service->queuePaused, 'queueCapacity' => $service->queueCapacity,
            'activeCount' => $active, 'issuedCount' => $issued,
            'remainingCount' => max(0, $service->queueCapacity - $issued),
            'acceptingTickets' => ! $service->queuePaused && $issued < $service->queueCapacity,
        ];
    }

    private function staffTicketPayload(QueueTicket $ticket): array
    {
        $payload = $this->ticketPayload($ticket);
        $payload['queuePosition'] = $ticket->status === 'WAITING' ? $this->waitEstimator->estimate($ticket)['queuePosition'] : null;

        return $payload;
    }

    private function ticketPayload(QueueTicket $ticket): array
    {
        $ticket->loadMissing(['service', 'user']);
        $service = $ticket->service;
        $payload = [
            'queueEntryId' => $ticket->id,
            'queueTicket' => $this->ticketNumber($ticket),
            'serviceId' => $service->id,
            'serviceCode' => $service->code,
            'serviceName' => $service->name,
            'serviceInstructions' => $service->instructions,
            'queuePaused' => $service->queuePaused,
            'serviceAvailable' => $service->isActive && $service->queueEnabled,
            'queueDate' => $ticket->queueDate,
            'studentNumber' => $ticket->user?->studentNumber,
            'isGuest' => $ticket->userId === null,
            'studentName' => $ticket->user?->name ?? $ticket->guestName,
            'status' => $ticket->status,
            'queueStatus' => $ticket->status,
            'queueNumber' => $ticket->queueNumber,
            'checkedInAt' => $ticket->checkedInAt,
            'calledAt' => $ticket->calledAt,
            'serviceStartedAt' => $ticket->serviceStartedAt,
            'completedAt' => $ticket->completedAt,
            'createdAt' => $ticket->createdAt,
            'walkIn' => true,
        ];
        if ($ticket->status === 'WAITING') {
            $payload = array_merge($payload, $this->waitEstimator->estimate($ticket));
        }
        if ($ticket->overrideReason) {
            $payload['overrideReason'] = $ticket->overrideReason;
        }

        return $payload;
    }

    private function ticketNumber(QueueTicket $ticket): string
    {
        return $ticket->service->code.'-'.str_pad((string) $ticket->queueNumber, 3, '0', STR_PAD_LEFT);
    }

    private function ensureStudent(User $user): void
    {
        abort_unless($user->role === 'Student' && filled($user->studentNumber), 403, 'A student account is required to join the virtual queue.');
    }

    private function assertCapacity(QueueService $service, string $date): void
    {
        $issued = QueueTicket::where('serviceId', $service->id)->where('queueDate', $date)->count();
        if ($issued >= $service->queueCapacity) {
            abort(409, 'This virtual queue has reached its daily capacity.');
        }
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
