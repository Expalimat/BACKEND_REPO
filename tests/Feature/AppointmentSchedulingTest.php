<?php

namespace Tests\Feature;

use App\Models\AppointmentBusinessRule;
use App\Models\AppointmentService;
use App\Models\AppointmentSlot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppointmentSchedulingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_business_rules_and_queue_summary_are_enforced(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $student = User::where('username', 'student')->firstOrFail();

        $service = AppointmentService::create([
            'id' => 'SRV-TEST-001',
            'serviceName' => 'CTC Claiming',
            'description' => 'Document claiming service',
            'instructions' => 'Bring valid ID',
            'requiredDocuments' => 'Valid ID',
            'estimatedDuration' => '30 minutes',
            'status' => 'Active',
            'createdAt' => now('Asia/Manila')->format('Y-m-d h:i A'),
            'updatedAt' => now('Asia/Manila')->format('Y-m-d h:i A'),
        ]);

        $closedDate = Carbon::parse('next Monday');
        AppointmentBusinessRule::create([
            'id' => 'BRL-001',
            'name' => 'Monday Closed',
            'ruleType' => 'closed_day',
            'serviceId' => null,
            'dayOfWeek' => $closedDate->dayOfWeek,
            'startTime' => null,
            'endTime' => null,
            'isActive' => true,
            'createdAt' => now('Asia/Manila')->format('Y-m-d h:i A'),
            'updatedAt' => now('Asia/Manila')->format('Y-m-d h:i A'),
        ]);

        $this->actingAs($admin)
            ->postJson('/api/appointment-slots', [
                'serviceId' => $service->id,
                'appointmentDate' => $closedDate->format('Y-m-d'),
                'startTime' => '09:00',
                'endTime' => '09:30',
                'maxCapacity' => 5,
            ])
            ->assertStatus(422);

        $validDate = Carbon::parse('next Tuesday');
        $slot = $this->actingAs($admin)
            ->postJson('/api/appointment-slots', [
                'serviceId' => $service->id,
                'appointmentDate' => $validDate->format('Y-m-d'),
                'startTime' => '09:00',
                'endTime' => '09:30',
                'maxCapacity' => 5,
            ])
            ->assertStatus(201)
            ->json();

        $this->actingAs($student)
            ->postJson('/api/appointments', [
                'serviceId' => $service->id,
                'slotId' => $slot['id'],
            ])
            ->assertStatus(201);

        $appointment = \App\Models\Appointment::query()->firstOrFail();
        $queueEntry = $appointment->queueEntry()->firstOrFail();

        $this->actingAs($student)
            ->getJson('/api/queue-entries/' . $queueEntry->id . '/summary')
            ->assertOk()
            ->assertJsonPath('queuePosition', 1)
            ->assertJsonPath('status', 'WAITING')
            ->assertJsonPath('estimatedWaitingMinutes', 0);
    }
}
