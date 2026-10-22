<?php

namespace App\Support;

use App\Models\AppointmentQueueEntry;
use App\Models\AppointmentService;
use Carbon\Carbon;

class AppointmentWaitEstimator
{
    private const ACTIVE_STATUSES = ['WAITING', 'CALLED', 'SERVING'];
    private const COMPLETED_SAMPLE_SIZE = 20;

    public function estimate(AppointmentQueueEntry $entry): array
    {
        $averageDuration = $this->averageDuration($entry->serviceId);
        $activeEntries = AppointmentQueueEntry::where('serviceId', $entry->serviceId)
            ->where('queueDate', $entry->queueDate)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->get(['status', 'queueNumber']);

        $waitingAhead = $activeEntries->where('status', 'WAITING')
            ->where('queueNumber', '<', $entry->queueNumber)
            ->count();
        $activeInService = $activeEntries->whereIn('status', ['CALLED', 'SERVING'])->count();
        $peopleAhead = $waitingAhead + $activeInService;

        return [
            'eligiblePeopleAhead' => $peopleAhead,
            'waitingPeopleAhead' => $waitingAhead,
            'queuePosition' => $peopleAhead + 1,
            'averageServiceDurationMinutes' => $averageDuration,
            'estimatedWaitMinutes' => (int) round($peopleAhead * $averageDuration),
            'estimatorVersion' => 'simple-queue-v1',
            'waitEstimateIsEstimate' => true,
            'waitEstimateMethod' => $this->hasActualSample($entry->serviceId)
                ? 'rolling_average_actual_duration'
                : 'configured_service_duration',
            'waitEstimateNote' => 'Estimate only, not a guaranteed time. It assumes one service counter and similar service durations; actual wait may vary.',
            'waitEstimateLimitations' => 'This simple model does not account for multiple counters, priority rules, interruptions, or materially different service types. A versioned estimator can replace it later.',
        ];
    }

    public function averageDuration(string $serviceId): float
    {
        $durations = AppointmentQueueEntry::where('serviceId', $serviceId)
            ->where('status', 'COMPLETED')
            ->whereNotNull('serviceStartedAt')
            ->whereNotNull('completedAt')
            ->orderByDesc('completedAt')
            ->limit(self::COMPLETED_SAMPLE_SIZE)
            ->get(['serviceStartedAt', 'completedAt'])
            ->map(function (AppointmentQueueEntry $entry) {
                try {
                    $minutes = Carbon::parse($entry->serviceStartedAt, 'Asia/Manila')
                        ->diffInSeconds(Carbon::parse($entry->completedAt, 'Asia/Manila')) / 60;
                    return $minutes > 0 ? $minutes : null;
                } catch (\Throwable) {
                    return null;
                }
            })
            ->filter();

        if ($durations->isNotEmpty()) {
            return round((float) $durations->avg(), 1);
        }

        return (float) (AppointmentService::whereKey($serviceId)->value('serviceDurationMinutes') ?: 30);
    }

    private function hasActualSample(string $serviceId): bool
    {
        return AppointmentQueueEntry::where('serviceId', $serviceId)
            ->where('status', 'COMPLETED')
            ->whereNotNull('serviceStartedAt')
            ->whereNotNull('completedAt')
            ->exists();
    }
}
