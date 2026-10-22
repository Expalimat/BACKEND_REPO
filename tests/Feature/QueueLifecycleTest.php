<?php

namespace Tests\Feature;

use App\Models\QueueService;
use App\Models\QueueTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QueueLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('Asia/Manila')->setDate(2026, 10, 22)->setTime(8, 0));
        $this->seed();
        DB::table('queue_service_staff')->insert([
            'serviceId' => QueueService::where('code', 'CTC')->value('id'),
            'userId' => User::where('username', 'registrar')->value('id'),
            'createdAt' => now('Asia/Manila')->format('Y-m-d h:i A'),
        ]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_student_can_join_cancel_and_rejoin_a_virtual_queue(): void
    {
        $student = User::where('username', 'student')->firstOrFail();
        $serviceId = QueueService::where('code', 'CTC')->value('id');

        $this->actingAs($student)->getJson('/api/services')->assertOk()
            ->assertJsonPath('data.0.id', $serviceId)
            ->assertJsonPath('data.0.code', 'CTC')
            ->assertJsonPath('data.0.name', 'Certified True Copy (CTC) / Document Claiming')
            ->assertJsonPath('data.0.isActive', true)
            ->assertJsonPath('data.0.queuePaused', false)
            ->assertJsonPath('data.0.queueCapacity', 50)
            ->assertJsonPath('data.0.queueEnabled', true)
            ->assertJsonPath('data.0.publicBookable', false)
            ->assertJsonPath('data.0.instructions', null)
            ->assertJsonPath('data.0.description', 'Join the virtual queue to claim approved certified true copies and other documents.');
        $this->actingAs($student)->getJson('/api/student/virtual-queue/services')
            ->assertOk()->assertJsonPath('data.0.code', 'CTC')->assertJsonPath('data.0.acceptingTickets', true);
        $ticket = $this->actingAs($student)->postJson('/api/student/virtual-queue/tickets', ['serviceId' => $serviceId])
            ->assertCreated()->assertJsonPath('data.queueTicket', 'CTC-001')->assertJsonPath('data.queueStatus', 'WAITING')->json('data');
        $this->actingAs($student)->postJson('/api/student/virtual-queue/tickets', ['serviceId' => $serviceId])
            ->assertStatus(409);
        $this->actingAs($student)->getJson('/api/student/virtual-queue/tickets')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.queueEntryId', $ticket['queueEntryId']);
        $this->actingAs($student)->patchJson('/api/student/virtual-queue/tickets/'.$ticket['queueEntryId'].'/cancel')
            ->assertOk()->assertJsonPath('data.queueStatus', 'CANCELLED');
        $this->actingAs($student)->postJson('/api/student/virtual-queue/tickets', ['serviceId' => $serviceId])
            ->assertCreated()->assertJsonPath('data.queueTicket', 'CTC-002');
    }

    public function test_guest_queue_requires_administrator_opt_in_and_keeps_student_access(): void
    {
        $student = User::where('username', 'student')->firstOrFail();
        $admin = User::where('username', 'admin')->firstOrFail();
        $freshman = QueueService::where('code', 'FRESHMAN-ENROLLMENT')->firstOrFail();
        $this->assertFalse($freshman->publicBookable, 'Guest access must be explicitly enabled by an administrator.');
        $guestData = [
            'service_id' => $freshman->id,
            'applicant_name' => 'Incoming Student',
            'applicant_email' => 'incoming@example.com',
            'applicant_phone' => '09123456789',
        ];

        $this->getJson('/api/public/virtual-queue/services')->assertOk()->assertExactJson([]);
        $this->postJson('/api/public/virtual-queue/tickets', $guestData)->assertUnprocessable();
        $this->actingAs($student)->postJson('/api/student/virtual-queue/tickets', ['serviceId' => $freshman->id])->assertUnprocessable();

        $this->actingAs($admin)->putJson('/api/services/'.$freshman->id, [
            'publicBookable' => true,
            'queueEnabled' => true,
        ])->assertOk()->assertJsonPath('data.publicBookable', true);

        $this->actingAs($student)->getJson('/api/student/virtual-queue/services')
            ->assertOk()->assertJsonFragment(['code' => 'FRESHMAN-ENROLLMENT']);
        $this->actingAs($student)->postJson('/api/student/virtual-queue/tickets', ['serviceId' => $freshman->id])
            ->assertCreated()->assertJsonPath('data.queueTicket', 'FRESHMAN-ENROLLMENT-001');
        $guest = $this->postJson('/api/public/virtual-queue/tickets', $guestData)->assertCreated()
            ->assertJsonPath('ticket.queueTicket', 'FRESHMAN-ENROLLMENT-002')
            ->assertJsonPath('ticket.studentName', 'Incoming Student')
            ->assertJsonPath('ticket.queueDate', now('Asia/Manila')->toDateString())
            ->assertJsonPath('ticket.queueStatus', 'WAITING')
            ->assertJsonPath('ticket.isGuest', true)
            ->assertJsonPath('ticket.queuePosition', 2)
            ->assertJsonMissingPath('ticket.appointmentDate')
            ->assertJsonMissingPath('ticket.guestEmail')->json();
        $ticket = QueueTicket::where('guestEmail', 'incoming@example.com')->firstOrFail();
        $this->assertSame(hash('sha256', $guest['accessToken']), $ticket->guestAccessTokenHash);
        $this->assertNotSame($guest['accessToken'], $ticket->guestAccessTokenHash);

        $this->postJson('/api/public/virtual-queue/tickets/retrieve', [
            'ticket_number' => $guest['ticket']['queueTicket'], 'access_token' => $guest['accessToken'],
        ])->assertOk()->assertJsonPath('ticket.queueEntryId', $ticket->id);
        $this->patchJson('/api/public/virtual-queue/tickets/cancel', [
            'ticket_number' => $guest['ticket']['queueTicket'], 'access_token' => $guest['accessToken'],
        ])->assertOk()->assertJsonPath('ticket.queueStatus', 'CANCELLED');
    }

    public function test_administrator_can_update_queue_service_name_and_settings(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $service = QueueService::where('code', 'FRESHMAN-ENROLLMENT')->firstOrFail();

        $this->actingAs($admin)->putJson('/api/services/'.$service->id, [
            'name' => 'Freshman Enrollment Support',
            'publicBookable' => true,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Freshman Enrollment Support')
            ->assertJsonPath('data.publicBookable', true);
    }

    public function test_staff_queue_lifecycle_enforces_assignment_and_status_transitions(): void
    {
        $registrar = User::where('username', 'registrar')->firstOrFail();
        $student = User::where('username', 'student')->firstOrFail();
        $service = QueueService::where('code', 'CTC')->firstOrFail();
        $ticket = $this->actingAs($student)->postJson('/api/student/virtual-queue/tickets', ['serviceId' => $service->id])
            ->assertCreated()->json('data');

        $this->actingAs($registrar)->getJson('/api/staff/queue?service_id='.$service->id)
            ->assertOk()->assertJsonPath('data.0.queueEntryId', $ticket['queueEntryId']);
        $this->actingAs($registrar)->getJson('/api/queue/'.$service->id)
            ->assertOk()->assertJsonPath('data.service.id', $service->id)
            ->assertJsonPath('data.tickets.0.queueEntryId', $ticket['queueEntryId'])
            ->assertJsonPath('data.tickets.0.queueTicket', 'CTC-001')
            ->assertJsonPath('data.tickets.0.serviceName', $service->name)
            ->assertJsonPath('data.tickets.0.studentName', User::where('username', 'student')->value('name'))
            ->assertJsonPath('data.tickets.0.status', 'WAITING')
            ->assertJsonPath('data.tickets.0.isGuest', false)
            ->assertJsonPath('data.tickets.0.queuePosition', 1);
        $this->getJson('/api/queue/display')->assertOk()
            ->assertJsonPath('services.0.serviceCode', 'CTC')
            ->assertJsonPath('services.0.nowServing', null)
            ->assertJsonPath('services.0.waitingCount', 1);
        $this->actingAs($registrar)->postJson('/api/staff/queue/'.$ticket['queueEntryId'].'/call')
            ->assertOk()->assertJsonPath('data.queueStatus', 'CALLED');
        $this->actingAs($registrar)->postJson('/api/staff/queue/'.$ticket['queueEntryId'].'/start')
            ->assertOk()->assertJsonPath('data.queueStatus', 'SERVING');
        $this->actingAs($registrar)->postJson('/api/staff/queue/'.$ticket['queueEntryId'].'/complete')
            ->assertOk()->assertJsonPath('data.queueStatus', 'COMPLETED');
        $this->actingAs($registrar)->postJson('/api/staff/queue/'.$ticket['queueEntryId'].'/start')->assertStatus(409);
        $this->assertDatabaseHas('queue_tickets', ['id' => $ticket['queueEntryId'], 'status' => 'COMPLETED']);
    }

    public function test_queue_capacity_counts_all_issued_tickets_and_admin_cannot_understate_it(): void
    {
        $student = User::where('username', 'student')->firstOrFail();
        $admin = User::where('username', 'admin')->firstOrFail();
        $service = QueueService::where('code', 'CTC')->firstOrFail();
        $service->queueCapacity = 2;
        $service->save();
        $ticket = $this->actingAs($student)->postJson('/api/student/virtual-queue/tickets', ['serviceId' => $service->id])->assertCreated()->json('data');
        $this->actingAs($student)->patchJson('/api/student/virtual-queue/tickets/'.$ticket['queueEntryId'].'/cancel')->assertOk();
        $this->actingAs($student)->postJson('/api/student/virtual-queue/tickets', ['serviceId' => $service->id])->assertCreated();

        $this->actingAs($admin)->putJson('/api/services/'.$service->id, ['queueCapacity' => 1])->assertUnprocessable();
        $this->actingAs($admin)->putJson('/api/services/'.$service->id, ['queueCapacity' => 2])->assertOk();

        $other = User::where('username', 'registrar')->firstOrFail();
        $this->actingAs($other)->postJson('/api/student/virtual-queue/tickets', ['serviceId' => $service->id])->assertForbidden();
    }

    public function test_administrator_can_replace_queue_staff_assignments(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $registrar = User::where('username', 'registrar')->firstOrFail();
        $department = User::where('username', 'department')->firstOrFail();
        $serviceId = QueueService::where('code', 'CTC')->value('id');

        $this->actingAs($admin)->putJson('/api/staff/services/'.$serviceId.'/staff', [
            'userIds' => [$department->id],
        ])->assertOk()->assertJsonPath('data.0.id', $department->id);
        $this->actingAs($registrar)->getJson('/api/queue/'.$serviceId)->assertForbidden();
        $this->actingAs($department)->getJson('/api/queue/'.$serviceId)->assertOk();
    }

    public function test_forward_migration_removes_legacy_schedule_schema(): void
    {
        foreach (['appointment_services', 'appointment_slots', 'appointments', 'appointment_queue_entries', 'appointment_queue_sequences', 'appointment_service_staff', 'appointment_status_history', 'appointment_notification_deliveries'] as $oldTable) {
            $this->assertFalse(DB::getSchemaBuilder()->hasTable($oldTable), $oldTable.' should be removed.');
        }
        foreach (['queue_services', 'queue_tickets', 'queue_sequences', 'queue_service_staff'] as $queueTable) {
            $this->assertTrue(DB::getSchemaBuilder()->hasTable($queueTable), $queueTable.' should exist.');
        }
        foreach (['appointmentId', 'appointmentDate', 'slotId', 'documentRequestId'] as $obsoleteColumn) {
            $this->assertNotContains($obsoleteColumn, DB::getSchemaBuilder()->getColumnListing('queue_tickets'));
        }
        $this->assertNotContains('appointmentDate', DB::getSchemaBuilder()->getColumnListing('queue_sequences'));
        $this->getJson('/api/appointments')->assertNotFound();
        $this->getJson('/api/appointment-slots')->assertNotFound();
        $this->getJson('/api/appointment-queue/display')->assertNotFound();
    }
}
