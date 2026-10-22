<?php

namespace App\Support;

use App\Models\QueueService;
use App\Models\QueueTicket;
use Carbon\Carbon;

class QueueWaitEstimator
{
    private const ACTIVE_STATUSES = ['WAITING', 'CALLED', 'SERVING'];

    public function estimate(QueueTicket $ticket): array
    {
        $averageDuration = $this->averageDuration($ticket->serviceId);
        $active = QueueTicket::where('serviceId', $ticket->serviceId)
            ->where('queueDate', $ticket->queueDate)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->get(['status', 'queueNumber']);
        $waitingAhead = $active->where('status', 'WAITING')->where('queueNumber', '<', $ticket->queueNumber)->count();
        $peopleAhead = $waitingAhead + $active->whereIn('status', ['CALLED', 'SERVING'])->count();

        return [
            'eligiblePeopleAhead' => $peopleAhead,
            'waitingPeopleAhead' => $waitingAhead,
            'queuePosition' => $peopleAhead + 1,
            'averageServiceDurationMinutes' => $averageDuration,
            'estimatedWaitMinutes' => (int) round($peopleAhead * $averageDuration),
            'estimatorVersion' => 'simple-queue-v1',
            'waitEstimateIsEstimate' => true,
            'waitEstimateMethod' => $this->hasActualSample($ticket->serviceId) ? 'rolling_average_actual_duration' : 'configured_service_duration',
            'waitEstimateNote' => 'Estimate only, not a guaranteed time. It assumes one service counter and similar service durations; actual wait may vary.',
            'waitEstimateLimitations' => 'This simple model does not account for multiple counters, priority rules, interruptions, or materially different service types.',
        ];
    }

    public function averageDuration(string $serviceId): float
    {
        $durations = QueueTicket::where('serviceId', $serviceId)
            ->where('status', 'COMPLETED')->whereNotNull('serviceStartedAt')->whereNotNull('completedAt')
            ->orderByDesc('completedAt')->limit(20)->get(['serviceStartedAt', 'completedAt'])
            ->map(function (QueueTicket $ticket) {
                try {
                    $minutes = Carbon::parse($ticket->serviceStartedAt, 'Asia/Manila')
                        ->diffInSeconds(Carbon::parse($ticket->completedAt, 'Asia/Manila')) / 60;

                    return $minutes > 0 ? $minutes : null;
                } catch (\Throwable) {
                    return null;
                }
            })->filter();

        return $durations->isNotEmpty()
            ? round((float) $durations->avg(), 1)
            : (float) (QueueService::whereKey($serviceId)->value('serviceDurationMinutes') ?: 30);
    }

    private function hasActualSample(string $serviceId): bool
    {
        return QueueTicket::where('serviceId', $serviceId)->where('status', 'COMPLETED')
            ->whereNotNull('serviceStartedAt')->whereNotNull('completedAt')->exists();
    }
}
