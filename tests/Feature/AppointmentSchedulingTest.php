<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentQueueEntry;
use App\Models\AppointmentService;
use App\Models\AppointmentSlot;
use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AppointmentSchedulingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('Asia/Manila')->setDate(2026, 10, 19)->setTime(8, 0));
        $this->seed();
        DB::table('appointment_service_staff')->insert([
            'serviceId' => AppointmentService::where('code', 'CTC')->value('id'),
            'userId' => User::where('username', 'registrar')->value('id'),
            'createdAt' => now('Asia/Manila')->format('Y-m-d h:i A'),
        ]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_student_can_book_a_slot_until_capacity_is_reached(): void
    {
        $slot = $this->createSlot('2026-10-20', '09:00', 1);
        $student = User::where('username', 'student')->firstOrFail();

        $this->actingAs($student)
            ->getJson('/api/appointment-slots')
            ->assertOk()
            ->assertJsonPath('data.0.available', 1);

        $this->actingAs($student)
            ->postJson('/api/appointments', ['slotId' => $slot->id, 'purpose' => 'Claim CTC'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'CONFIRMED')
            ->assertJsonPath('data.serviceCode', 'CTC')
            ->assertJsonPath('data.appointmentNumber', 'PNC-20261020-0001')
            ->assertJsonPath('data.slotId', $slot->id)
            ->assertJsonPath('data.appointmentDate', '2026-10-20')
            ->assertJsonPath('data.startTime', '09:00')
            ->assertJsonPath('data.endTime', '09:30');

        $this->actingAs($student)
            ->postJson('/api/appointments', ['slotId' => $slot->id])
            ->assertStatus(409);

        $otherStudent = $this->createStudent('student-capacity', '2026-00003');
        $this->actingAs($otherStudent)
            ->postJson('/api/appointments', ['slotId' => $slot->id])
            ->assertStatus(409)
            ->assertJsonPath('message', 'This appointment time has just filled. Choose another available time.');

        $this->assertDatabaseHas('appointments', [
            'slotId' => $slot->id,
            'studentNumber' => $student->studentNumber,
            'status' => 'CONFIRMED',
        ]);
    }

    public function test_student_can_join_and_retrieve_only_their_own_virtual_queue_ticket(): void
    {
        $student = User::where('username', 'student')->firstOrFail();
        $otherStudent = $this->createStudent('student-queue-other', '2026-00003');
        $serviceId = AppointmentService::where('code', 'CTC')->value('id');

        $this->actingAs($student)
            ->getJson('/api/student/virtual-queue/services')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'CTC')
            ->assertJsonPath('data.0.acceptingTickets', true);

        $ticket = $this->actingAs($student)
            ->postJson('/api/student/virtual-queue/tickets', ['serviceId' => $serviceId])
            ->assertCreated()
            ->assertJsonPath('data.queueTicket', 'CTC-001')
            ->assertJsonPath('data.queueStatus', 'WAITING')
            ->assertJsonPath('data.walkIn', true)
            ->json('data');

        $this->actingAs($student)
            ->postJson('/api/student/virtual-queue/tickets', ['serviceId' => $serviceId])
            ->assertStatus(409)
            ->assertJsonPath('message', 'You already have an active queue ticket today.');

        $this->actingAs($otherStudent)
            ->postJson('/api/student/virtual-queue/tickets', ['serviceId' => $serviceId])
            ->assertCreated()
            ->assertJsonPath('data.queueTicket', 'CTC-002');

        $this->actingAs($student)
            ->getJson('/api/student/virtual-queue/tickets')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.queueEntryId', $ticket['queueEntryId'])
            ->assertJsonPath('data.0.studentNumber', $student->studentNumber);

        $this->actingAs($otherStudent)
            ->getJson('/api/student/virtual-queue/tickets')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.studentNumber', $otherStudent->studentNumber);

        $this->actingAs($otherStudent)
            ->patchJson('/api/student/virtual-queue/tickets/'.$ticket['queueEntryId'].'/cancel')
            ->assertNotFound();
        $this->actingAs($student)
            ->patchJson('/api/student/virtual-queue/tickets/'.$ticket['queueEntryId'].'/cancel')
            ->assertOk()
            ->assertJsonPath('data.queueStatus', 'CANCELLED');
        $this->actingAs($student)
            ->getJson('/api/student/virtual-queue/tickets')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->actingAs($student)
            ->postJson('/api/student/virtual-queue/tickets', ['serviceId' => $serviceId])
            ->assertCreated()
            ->assertJsonPath('data.queueTicket', 'CTC-003');
    }

    public function test_freshman_queue_requires_admin_opt_in_and_remains_guest_only(): void
    {
        $student = User::where('username', 'student')->firstOrFail();
        $admin = User::where('username', 'admin')->firstOrFail();
        $service = AppointmentService::where('code', 'FRESHMAN-ENROLLMENT')->firstOrFail();

        $this->getJson('/api/public/virtual-queue/services')
            ->assertOk()
            ->assertExactJson([]);
        $this->postJson('/api/public/virtual-queue/tickets', [
            'service_id' => $service->id,
            'applicant_name' => 'Incoming Student',
            'applicant_email' => 'incoming@example.com',
            'applicant_phone' => '09123456789',
        ])->assertUnprocessable();

        $this->actingAs($admin)
            ->putJson('/api/appointment-services/'.$service->id, [
                'name' => $service->name,
                'publicBookable' => true,
                'queueEnabled' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.queueEnabled', true);

        $this->actingAs($student)
            ->getJson('/api/student/virtual-queue/services')
            ->assertOk()
            ->assertJsonMissing(['code' => 'FRESHMAN-ENROLLMENT']);
        $this->actingAs($student)
            ->postJson('/api/student/virtual-queue/tickets', ['serviceId' => $service->id])
            ->assertUnprocessable();

        $this->getJson('/api/public/virtual-queue/services')
            ->assertOk()
            ->assertJsonPath('0.code', 'FRESHMAN-ENROLLMENT')
            ->assertJsonPath('0.acceptingTickets', true);
    }

    public function test_guest_ticket_requires_private_code_for_retrieval_and_cancellation(): void
    {
        $service = AppointmentService::where('code', 'FRESHMAN-ENROLLMENT')->firstOrFail();
        $service->update(['queueEnabled' => true, 'publicBookable' => true]);

        $ticket = $this->postJson('/api/public/virtual-queue/tickets', [
            'service_id' => $service->id,
            'applicant_name' => 'Incoming Student',
            'applicant_email' => 'incoming@example.com',
            'applicant_phone' => '09123456789',
        ])->assertCreated()
            ->assertJsonPath('ticket.queueTicket', 'FRESHMAN-ENROLLMENT-001')
            ->assertJsonPath('ticket.queueStatus', 'WAITING')
            ->assertJsonPath('ticket.studentName', 'Incoming Student')
            ->assertJsonMissingPath('ticket.guestEmail')
            ->json();

        $entry = AppointmentQueueEntry::where('guestEmail', 'incoming@example.com')->firstOrFail();
        $this->assertSame(hash('sha256', $ticket['accessToken']), $entry->guestAccessTokenHash);
        $this->assertNotSame($ticket['accessToken'], $entry->guestAccessTokenHash);

        $this->postJson('/api/public/virtual-queue/tickets/retrieve', [
            'ticket_number' => $ticket['ticket']['queueTicket'],
            'access_token' => str_repeat('0', 64),
        ])->assertNotFound();

        $this->postJson('/api/public/virtual-queue/tickets/retrieve', [
            'ticket_number' => $ticket['ticket']['queueTicket'],
            'access_token' => $ticket['accessToken'],
        ])->assertOk()
            ->assertJsonPath('ticket.queueTicket', $ticket['ticket']['queueTicket'])
            ->assertJsonMissingPath('accessToken');

        $this->actingAs(User::where('username', 'admin')->firstOrFail())
            ->getJson('/api/staff/queue')
            ->assertOk()
            ->assertJsonPath('data.0.studentName', 'Incoming Student')
            ->assertJsonPath('data.0.isGuest', true);

        $this->patchJson('/api/public/virtual-queue/tickets/cancel', [
            'ticket_number' => $ticket['ticket']['queueTicket'],
            'access_token' => str_repeat('0', 64),
        ])->assertNotFound();
        $this->patchJson('/api/public/virtual-queue/tickets/cancel', [
            'ticket_number' => $ticket['ticket']['queueTicket'],
            'access_token' => $ticket['accessToken'],
        ])->assertOk()
            ->assertJsonPath('ticket.queueStatus', 'CANCELLED');
    }

    public function test_guest_ticket_consumes_queue_capacity_and_duplicate_active_email_is_rejected(): void
    {
        $service = AppointmentService::where('code', 'FRESHMAN-ENROLLMENT')->firstOrFail();
        $service->update(['queueEnabled' => true, 'publicBookable' => true, 'queueCapacity' => 1]);
        $payload = [
            'service_id' => $service->id,
            'applicant_name' => 'Incoming Student',
            'applicant_email' => 'incoming@example.com',
            'applicant_phone' => '09123456789',
        ];

        $this->postJson('/api/public/virtual-queue/tickets', $payload)->assertCreated();
        $this->postJson('/api/public/virtual-queue/tickets', array_merge($payload, [
            'applicant_email' => 'INCOMING@example.com',
        ]))->assertStatus(409)
            ->assertJsonPath('message', 'This email already has an active ticket for this service today.');
        $this->postJson('/api/public/virtual-queue/tickets', array_merge($payload, [
            'applicant_email' => 'another@example.com',
        ]))->assertStatus(409)
            ->assertJsonPath('message', 'This virtual queue has reached its daily capacity.');
    }

    public function test_waiting_ticket_position_counts_the_ticket_currently_being_served(): void
    {
        $firstStudent = User::where('username', 'student')->firstOrFail();
        $secondStudent = $this->createStudent('student-queue-position', '2026-00008');
        $serviceId = AppointmentService::where('code', 'CTC')->value('id');

        $firstTicket = $this->actingAs($firstStudent)
            ->postJson('/api/student/virtual-queue/tickets', ['serviceId' => $serviceId])
            ->assertCreated()
            ->json('data');
        $this->actingAs($secondStudent)
            ->postJson('/api/student/virtual-queue/tickets', ['serviceId' => $serviceId])
            ->assertCreated();

        $this->actingAs(User::where('username', 'registrar')->firstOrFail())
            ->postJson('/api/staff/queue/'.$firstTicket['queueEntryId'].'/call')
            ->assertOk();

        $this->actingAs($secondStudent)
            ->getJson('/api/student/virtual-queue/tickets')
            ->assertOk()
            ->assertJsonPath('data.0.queuePosition', 2)
            ->assertJsonPath('data.0.eligiblePeopleAhead', 1);
    }

    public function test_virtual_queue_capacity_and_pause_are_enforced_without_removing_existing_tickets(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $student = User::where('username', 'student')->firstOrFail();
        $otherStudent = $this->createStudent('student-queue-capacity', '2026-00004');
        $service = AppointmentService::where('code', 'CTC')->firstOrFail();

        $this->actingAs($admin)
            ->putJson('/api/appointment-services/'.$service->id, [
                'name' => $service->name,
                'queueEnabled' => true,
                'queuePaused' => false,
                'queueCapacity' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.queueCapacity', 1);

        $ticketId = $this->actingAs($student)
            ->postJson('/api/student/virtual-queue/tickets', ['serviceId' => $service->id])
            ->assertCreated()
            ->json('data.queueEntryId');

        $this->actingAs($otherStudent)
            ->postJson('/api/student/virtual-queue/tickets', ['serviceId' => $service->id])
            ->assertStatus(409)
            ->assertJsonPath('message', 'This virtual queue has reached its daily capacity.');

        $this->actingAs($student)
            ->patchJson('/api/student/virtual-queue/tickets/'.$ticketId.'/cancel')
            ->assertOk()
            ->assertJsonPath('data.queueStatus', 'CANCELLED');
        $this->actingAs($otherStudent)
            ->postJson('/api/student/virtual-queue/tickets', ['serviceId' => $service->id])
            ->assertStatus(409)
            ->assertJsonPath('message', 'This virtual queue has reached its daily capacity.');

        $this->actingAs($admin)
            ->putJson('/api/appointment-services/'.$service->id, [
                'name' => $service->name,
                'queueCapacity' => 2,
            ])
            ->assertOk();
        $activeTicketId = $this->actingAs($otherStudent)
            ->postJson('/api/student/virtual-queue/tickets', ['serviceId' => $service->id])
            ->assertCreated()
            ->json('data.queueEntryId');

        $this->actingAs($admin)
            ->patchJson('/api/staff/appointment-services/'.$service->id.'/queue-pause', ['queuePaused' => true])
            ->assertOk()
            ->assertJsonPath('data.queuePaused', true);
        $this->actingAs($otherStudent)
            ->postJson('/api/student/virtual-queue/tickets', ['serviceId' => $service->id])
            ->assertStatus(409)
            ->assertJsonPath('message', 'This virtual queue is temporarily paused. Please try again later.');

        $this->assertDatabaseHas('appointment_queue_entries', [
            'id' => $activeTicketId,
            'status' => 'WAITING',
        ]);
    }

    public function test_staff_can_process_virtual_queue_tickets_and_public_display_hides_student_identity(): void
    {
        $student = User::where('username', 'student')->firstOrFail();
        $registrar = User::where('username', 'registrar')->firstOrFail();
        $serviceId = AppointmentService::where('code', 'CTC')->value('id');
        $ticket = $this->actingAs($student)
            ->postJson('/api/student/virtual-queue/tickets', ['serviceId' => $serviceId])
            ->assertCreated()
            ->json('data');

        $this->actingAs($registrar)
            ->getJson('/api/staff/queue?service_id='.$serviceId)
            ->assertOk()
            ->assertJsonPath('data.0.walkIn', true)
            ->assertJsonPath('data.0.queueEntryId', $ticket['queueEntryId'])
            ->assertJsonPath('data.0.studentNumber', $student->studentNumber);

        $this->actingAs($registrar)
            ->postJson('/api/appointment-queue/'.$serviceId.'/call-next')
            ->assertOk()
            ->assertJsonPath('data.queueTicket', 'CTC-001')
            ->assertJsonPath('data.queueStatus', 'CALLED');
        $this->actingAs($registrar)
            ->postJson('/api/staff/queue/'.$ticket['queueEntryId'].'/start')
            ->assertOk()
            ->assertJsonPath('data.queueStatus', 'SERVING');
        $this->actingAs($registrar)
            ->postJson('/api/staff/queue/'.$ticket['queueEntryId'].'/complete')
            ->assertOk()
            ->assertJsonPath('data.queueStatus', 'COMPLETED');

        $this->assertDatabaseHas('appointment_queue_entries', [
            'id' => $ticket['queueEntryId'],
            'status' => 'COMPLETED',
            'userId' => $student->id,
            'appointmentId' => null,
        ]);
        $this->getJson('/api/appointment-queue/display')
            ->assertOk()
            ->assertJsonMissingPath('data.services.0.studentName')
            ->assertJsonMissingPath('data.services.0.studentNumber');
    }

    public function test_documented_api_aliases_support_student_booking_and_staff_management(): void
    {
        $student = User::where('username', 'student')->firstOrFail();
        $registrar = User::where('username', 'registrar')->firstOrFail();
        $slot = $this->createSlot('2026-10-20', '09:00', 2);

        $this->actingAs($student)
            ->getJson('/api/services')
            ->assertOk()
            ->assertJsonFragment(['code' => 'CTC']);
        $this->actingAs($student)
            ->getJson('/api/appointment-slots?service_id='.$slot->serviceId.'&date_from=2026-10-20&date_to=2026-10-20')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $slot->id);

        $appointment = $this->actingAs($student)
            ->postJson('/api/appointments', ['appointment_slot_id' => $slot->id])
            ->assertCreated()
            ->json('data');
        $this->actingAs($student)
            ->getJson('/api/appointments/my')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $appointment['id']);

        $this->actingAs($registrar)
            ->getJson('/api/staff/appointments?reference_number='.$appointment['appointmentNumber'].'&per_page=1')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $appointment['id'])
            ->assertJsonPath('data.per_page', 1);
        $this->actingAs($registrar)
            ->getJson('/api/staff/appointment-slots?service_id='.$slot->serviceId)
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $slot->id);
        $this->actingAs($registrar)
            ->getJson('/api/staff/reports/appointments?service_id='.$slot->serviceId)
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.by_status.CONFIRMED', 1)
            ->assertJsonPath('data.by_service.0.service_id', $slot->serviceId);
    }

    public function test_appointment_api_preserves_http_error_statuses_and_validation_details(): void
    {
        $this->getJson('/api/services')
            ->assertUnauthorized();

        $student = User::where('username', 'student')->firstOrFail();
        $this->actingAs($student)
            ->getJson('/api/staff/queue')
            ->assertForbidden();
        $this->actingAs($student)
            ->postJson('/api/appointments', [])
            ->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors' => ['slotId']]);
        $this->actingAs($student)
            ->getJson('/api/appointments/not-a-real-id')
            ->assertNotFound();
    }

    public function test_staff_appointments_and_queue_are_limited_to_explicit_service_assignments(): void
    {
        $registrar = User::where('username', 'registrar')->firstOrFail();
        $departmentStaff = User::where('username', 'department')->firstOrFail();
        $admin = User::where('username', 'admin')->firstOrFail();
        $student = User::where('username', 'student')->firstOrFail();
        $ctcSlot = $this->createSlot('2026-10-20', '09:00', 2);

        $otherService = $this->actingAs($admin)
            ->postJson('/api/appointment-services', ['code' => 'REG', 'name' => 'Registrar Consultation'])
            ->assertCreated()
            ->json('data');
        $otherSlot = $this->actingAs($admin)
            ->postJson("/api/appointment-services/{$otherService['id']}/slots", [
                'appointmentDate' => '2026-10-20',
                'startTime' => '10:00',
                'endTime' => '10:30',
                'capacity' => 2,
            ])
            ->assertCreated()
            ->json('data');

        $ctcAppointment = $this->actingAs($student)
            ->postJson('/api/appointments', ['slotId' => $ctcSlot->id])
            ->assertCreated()
            ->json('data');
        $otherAppointment = $this->actingAs($student)
            ->postJson('/api/appointments', ['slotId' => $otherSlot['id']])
            ->assertCreated()
            ->json('data');

        $this->actingAs($registrar)
            ->getJson('/api/staff/appointments?per_page=100')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $ctcAppointment['id']);
        $this->actingAs($registrar)
            ->getJson('/api/appointments/'.$otherAppointment['id'])
            ->assertNotFound();
        $this->actingAs($registrar)
            ->getJson('/api/staff/queue?service_id='.$otherService['id'])
            ->assertForbidden();

        $this->actingAs($admin)
            ->putJson('/api/staff/services/'.$otherService['id'].'/staff', [
                'user_ids' => [$departmentStaff->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.0.id', $departmentStaff->id);
        $this->actingAs($departmentStaff)
            ->getJson('/api/staff/appointments?per_page=100')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $otherAppointment['id']);
        $this->actingAs($departmentStaff)
            ->patchJson('/api/appointments/'.$otherAppointment['id'].'/cancel', ['reason' => 'Student request'])
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED');
        $this->actingAs($registrar)
            ->getJson('/api/staff/services/'.$otherService['id'].'/staff')
            ->assertForbidden();

        $this->actingAs($admin)
            ->getJson('/api/appointments/'.$otherAppointment['id'])
            ->assertOk()
            ->assertJsonPath('data.id', $otherAppointment['id']);
    }

    public function test_student_availability_excludes_times_that_have_already_passed_today(): void
    {
        $this->createSlot('2026-10-19', '07:00', 2);
        $upcoming = $this->createSlot('2026-10-19', '09:00', 2);
        $student = User::where('username', 'student')->firstOrFail();

        $this->actingAs($student)
            ->getJson('/api/appointment-slots')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $upcoming->id);
    }

    public function test_student_cannot_link_another_students_document_request(): void
    {
        $slot = $this->createSlot('2026-10-20', '10:00', 2);
        $student = User::where('username', 'student')->firstOrFail();

        $this->actingAs($student)
            ->postJson('/api/appointments', [
                'slotId' => $slot->id,
                'documentRequestId' => 'DOC-2026-5503',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Choose one of your own active document requests.');
    }

    public function test_registrar_can_manage_services_and_capacity_without_overlapping_slots(): void
    {
        $registrar = User::where('username', 'admin')->firstOrFail();
        $ctc = AppointmentService::where('code', 'CTC')->firstOrFail();

        $this->actingAs($registrar)
            ->postJson('/api/appointment-services', [
                'code' => 'REG',
                'name' => 'Registrar Consultation',
                'description' => 'General registrar inquiries.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'REG');

        $this->actingAs($registrar)
            ->postJson("/api/appointment-services/{$ctc->id}/slots", [
                'appointmentDate' => '2026-10-20',
                'startTime' => '09:00',
                'endTime' => '09:30',
                'capacity' => 3,
            ])
            ->assertCreated()
            ->assertJsonPath('data.available', 3);

        $this->actingAs($registrar)
            ->postJson("/api/appointment-services/{$ctc->id}/slots", [
                'appointmentDate' => '2026-10-20',
                'startTime' => '09:15',
                'endTime' => '09:45',
                'capacity' => 3,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This time overlaps another slot for the selected service.');
    }

    public function test_capacity_cannot_be_reduced_below_active_confirmations(): void
    {
        $slot = $this->createSlot('2026-10-20', '12:00', 2);
        $student = User::where('username', 'student')->firstOrFail();
        $otherStudent = $this->createStudent('student-capacity', '2026-00003');
        $registrar = User::where('username', 'admin')->firstOrFail();

        $this->actingAs($student)->postJson('/api/appointments', ['slotId' => $slot->id])->assertCreated();
        $this->actingAs($otherStudent)->postJson('/api/appointments', ['slotId' => $slot->id])->assertCreated();

        $this->actingAs($registrar)
            ->patchJson("/api/appointment-slots/{$slot->id}", ['capacity' => 1])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Capacity cannot be lower than the 2 active appointments.');
    }

    public function test_student_and_registrar_roles_are_enforced_for_appointment_management(): void
    {
        $student = User::where('username', 'student')->firstOrFail();
        $registrar = User::where('username', 'admin')->firstOrFail();

        $this->actingAs($student)
            ->postJson('/api/appointment-services', ['code' => 'NEW', 'name' => 'New service'])
            ->assertForbidden();

        $slot = $this->createSlot('2026-10-20', '11:00', 2);
        $this->actingAs($registrar)
            ->postJson('/api/appointments', ['slotId' => $slot->id])
            ->assertForbidden();
    }

    public function test_staff_check_in_and_call_flow_is_reflected_on_public_display_without_identity_data(): void
    {
        $slot = $this->createSlot('2026-10-19', '09:00', 2);
        $student = User::where('username', 'student')->firstOrFail();
        $registrar = User::where('username', 'admin')->firstOrFail();
        $appointmentId = $this->actingAs($student)
            ->postJson('/api/appointments', ['slotId' => $slot->id])
            ->assertCreated()
            ->json('data.id');
        $this->travelTo(Carbon::parse('2026-10-19 08:30:00', 'Asia/Manila'));

        $this->actingAs($registrar)
            ->postJson("/api/staff/appointments/{$appointmentId}/check-in")
            ->assertOk()
            ->assertJsonPath('data.queueTicket', 'CTC-001')
            ->assertJsonPath('data.status', 'WAITING')
            ->assertJsonPath('data.queueStatus', 'WAITING');
        $queueEntryId = AppointmentQueueEntry::where('appointmentId', $appointmentId)->value('id');

        $this->getJson("/api/appointment-queue/{$slot->serviceId}")
            ->assertOk()
            ->assertJsonPath('data.appointments.0.status', 'WAITING')
            ->assertJsonPath('data.appointments.0.queueTicket', 'CTC-001');

        $this->actingAs($registrar)
            ->getJson('/api/staff/queue?service_id='.$slot->serviceId)
            ->assertOk()
            ->assertJsonPath('data.0.queueEntryId', $queueEntryId)
            ->assertJsonPath('data.0.queuePosition', 1);
        $this->postJson("/api/staff/queue/{$queueEntryId}/call")
            ->assertOk()
            ->assertJsonPath('data.status', 'WAITING')
            ->assertJsonPath('data.queueStatus', 'CALLED');

        $response = $this->getJson('/api/appointment-queue/display')
            ->assertOk()
            ->assertJsonPath('data.services.0.serviceCode', 'CTC')
            ->assertJsonPath('data.services.0.nowServing', 'CTC-001');
        $response->assertJsonMissingPath('data.services.0.studentName');
        $response->assertJsonMissingPath('data.services.0.studentNumber');

        $this->postJson("/api/staff/queue/{$queueEntryId}/start")
            ->assertOk()
            ->assertJsonPath('data.status', 'SERVING');

        $this->postJson("/api/staff/queue/{$queueEntryId}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED');

        $this->assertDatabaseHas('appointments', [
            'id' => $appointmentId,
            'studentNumber' => $student->studentNumber,
            'status' => 'COMPLETED',
        ]);
    }

    public function test_public_queue_display_reports_upcoming_slot_capacity_without_counting_confirmed_bookings_as_waiting(): void
    {
        $service = AppointmentService::where('code', 'FRESHMAN-ENROLLMENT')->firstOrFail();
        $slot = AppointmentSlot::create([
            'id' => fake()->uuid(),
            'serviceId' => $service->id,
            'appointmentDate' => '2026-10-20',
            'startTime' => '09:00',
            'endTime' => '09:30',
            'capacity' => 10,
            'status' => 'Open',
            'createdAt' => now('Asia/Manila')->format('Y-m-d h:i A'),
            'updatedAt' => now('Asia/Manila')->format('Y-m-d h:i A'),
        ]);

        foreach (range(1, 3) as $number) {
            $this->postJson('/api/public/appointments/book', [
                'slot_id' => $slot->id,
                'applicant_name' => "Applicant {$number}",
                'applicant_email' => "applicant{$number}@example.com",
                'applicant_phone' => '09123456789',
            ])->assertCreated();
        }

        $services = $this->getJson('/api/appointment-queue/display')
            ->assertOk()
            ->json('data.services');
        $freshmanService = collect($services)->firstWhere('serviceCode', 'FRESHMAN-ENROLLMENT');

        $this->assertSame(0, $freshmanService['waitingCount']);
        $this->assertSame('2026-10-20', $freshmanService['upcomingBookedSlots'][0]['appointmentDate']);
        $this->assertSame(3, $freshmanService['upcomingBookedSlots'][0]['bookedCount']);
        $this->assertSame(10, $freshmanService['upcomingBookedSlots'][0]['capacity']);
        $this->assertSame(7, $freshmanService['upcomingBookedSlots'][0]['remainingCount']);
    }

    public function test_queue_calls_are_serialized_and_completion_cannot_be_repeated(): void
    {
        $slot = $this->createSlot('2026-10-19', '09:00', 2);
        $firstStudent = User::where('username', 'student')->firstOrFail();
        $secondStudent = $this->createStudent('student-queue-serialize', '2026-00007');
        $registrar = User::where('username', 'registrar')->firstOrFail();
        $first = $this->actingAs($firstStudent)
            ->postJson('/api/appointments', ['slotId' => $slot->id])
            ->assertCreated()
            ->json('data');
        $second = $this->actingAs($secondStudent)
            ->postJson('/api/appointments', ['slotId' => $slot->id])
            ->assertCreated()
            ->json('data');

        $this->travelTo(Carbon::parse('2026-10-19 08:30:00', 'Asia/Manila'));
        $this->actingAs($registrar)->postJson("/api/staff/appointments/{$first['id']}/check-in")->assertOk();
        $this->actingAs($registrar)->postJson("/api/staff/appointments/{$second['id']}/check-in")->assertOk();

        $firstEntryId = AppointmentQueueEntry::where('appointmentId', $first['id'])->value('id');
        $secondEntryId = AppointmentQueueEntry::where('appointmentId', $second['id'])->value('id');
        $this->actingAs($registrar)
            ->postJson("/api/staff/queue/{$firstEntryId}/call")
            ->assertOk()
            ->assertJsonPath('data.queueStatus', 'CALLED');
        $this->actingAs($registrar)
            ->postJson("/api/staff/queue/{$secondEntryId}/call")
            ->assertStatus(409);

        $this->actingAs($registrar)->postJson("/api/staff/queue/{$firstEntryId}/start")->assertOk();
        $this->actingAs($registrar)->postJson("/api/staff/queue/{$firstEntryId}/complete")->assertOk();
        $this->actingAs($registrar)
            ->postJson("/api/staff/queue/{$firstEntryId}/complete")
            ->assertStatus(409);
        $this->actingAs($registrar)
            ->postJson("/api/staff/queue/{$secondEntryId}/call")
            ->assertOk()
            ->assertJsonPath('data.queueStatus', 'CALLED');

        $this->assertSame(1, DB::table('appointment_queue_entries')
            ->where('serviceId', $slot->serviceId)
            ->where('queueDate', '2026-10-19')
            ->whereIn('status', ['CALLED', 'SERVING'])
            ->count());
    }

    public function test_students_only_see_their_own_appointments_and_can_cancel_upcoming_bookings(): void
    {
        $slot = $this->createSlot('2026-10-20', '13:00', 2);
        $student = User::where('username', 'student')->firstOrFail();
        $otherStudent = User::where('username', 'student2')->first();
        if (! $otherStudent) {
            $otherStudent = User::create([
                'id' => 'USR-TEST-002',
                'username' => 'student2',
                'password' => 'password',
                'role' => 'Student',
                'name' => 'Other Student',
                'studentNumber' => '2026-00002',
                'status' => 'Active',
            ]);
        }
        $first = Appointment::create($this->appointmentData($slot, $student));
        $other = Appointment::create($this->appointmentData($slot, $otherStudent, 'APT-20261020-0002'));

        $this->actingAs($student)
            ->getJson('/api/appointments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $first->id);

        $this->patchJson("/api/appointments/{$first->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED');
        $this->actingAs($student)
            ->getJson("/api/appointments/{$other->id}")
            ->assertNotFound();
        $this->actingAs($student)
            ->patchJson("/api/appointments/{$other->id}/cancel")
            ->assertNotFound();
    }

    public function test_registrar_cannot_mark_an_appointment_no_show_before_its_slot_ends(): void
    {
        $slot = $this->createSlot('2026-10-19', '09:00', 1);
        $student = User::where('username', 'student')->firstOrFail();
        $registrar = User::where('username', 'admin')->firstOrFail();
        $slot->service->update(['lateArrivalGraceMinutes' => 10]);
        $appointment = Appointment::create($this->appointmentData($slot, $student));

        $this->actingAs($registrar)
            ->patchJson("/api/appointments/{$appointment->id}/finish", ['status' => 'NO_SHOW'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'An appointment can be marked no-show only after its late-arrival grace period has ended.');

        $this->travelTo(Carbon::parse('2026-10-19 09:39:00', 'Asia/Manila'));
        $this->patchJson("/api/appointments/{$appointment->id}/finish", ['status' => 'NO_SHOW'])
            ->assertStatus(409);
        $this->travelTo(Carbon::parse('2026-10-19 09:41:00', 'Asia/Manila'));
        $this->patchJson("/api/appointments/{$appointment->id}/finish", ['status' => 'NO_SHOW'])
            ->assertOk()
            ->assertJsonPath('data.status', 'NO_SHOW');
        $this->postJson("/api/appointment-queue/{$slot->serviceId}/call-next")
            ->assertNotFound();
    }

    public function test_service_configuration_and_closed_dates_control_student_availability(): void
    {
        $registrar = User::where('username', 'admin')->firstOrFail();
        $student = User::where('username', 'student')->firstOrFail();
        $service = AppointmentService::where('code', 'CTC')->firstOrFail();
        $slot = $this->createSlot('2026-10-20', '09:00', 2);

        $this->actingAs($registrar)
            ->putJson("/api/appointment-services/{$service->id}", [
                'instructions' => 'Bring the claim stub.',
                'requiredDocuments' => ['Claim stub', 'Valid ID'],
                'serviceDurationMinutes' => 45,
                'bookingWindowDays' => 20,
                'cancellationCutoffMinutes' => 60,
                'lateArrivalGraceMinutes' => 10,
            ])
            ->assertOk()
            ->assertJsonPath('data.instructions', 'Bring the claim stub.')
            ->assertJsonPath('data.requiredDocuments.0', 'Claim stub')
            ->assertJsonPath('data.serviceDurationMinutes', 45);

        $this->actingAs($registrar)
            ->postJson("/api/appointment-services/{$service->id}/closures", [
                'closedDate' => '2026-10-20',
                'reason' => 'Office unavailable',
            ])
            ->assertCreated();

        $this->actingAs($student)
            ->getJson("/api/appointment-slots?serviceId={$service->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($student)
            ->postJson('/api/appointments', ['slotId' => $slot->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This appointment time is not available for booking.');

        $closureId = $this->actingAs($registrar)
            ->getJson("/api/appointment-services/{$service->id}/closures")
            ->assertOk()
            ->json('data.0.id');
        $this->actingAs($registrar)
            ->deleteJson("/api/appointment-services/{$service->id}/closures/{$closureId}")
            ->assertOk();

        $this->actingAs($student)
            ->getJson("/api/appointment-slots?serviceId={$service->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.available', 2);
    }

    public function test_rescheduling_keeps_original_appointment_and_links_replacement(): void
    {
        $student = User::where('username', 'student')->firstOrFail();
        $oldSlot = $this->createSlot('2026-10-20', '09:00', 2);
        $newSlot = $this->createSlot('2026-10-21', '10:00', 2);

        $old = $this->actingAs($student)
            ->postJson('/api/appointments', ['slotId' => $oldSlot->id])
            ->assertCreated()
            ->json('data');

        $rescheduleResponse = $this->actingAs($student)
            ->patchJson("/api/appointments/{$old['id']}/reschedule", ['slotId' => $newSlot->id])
            ->assertCreated()
            ->assertJsonPath('data.previousAppointment.status', 'RESCHEDULED')
            ->assertJsonPath('data.appointment.status', 'CONFIRMED')
            ->assertJsonPath('data.appointment.rescheduledFromId', $old['id']);
        $replacement = $rescheduleResponse->json('data.appointment');
        $this->assertSame($replacement['id'], $rescheduleResponse->json('data.previousAppointment.rescheduledToId'));

        $this->assertNotSame($old['appointmentNumber'], $replacement['appointmentNumber']);
        $this->assertStringStartsWith('PNC-20261021-', $replacement['appointmentNumber']);
        $this->actingAs($student)
            ->getJson("/api/appointments/{$old['id']}/history")
            ->assertOk()
            ->assertJsonPath('data.1.eventType', 'appointment_rescheduled');
    }

    public function test_self_check_in_is_disabled_and_staff_queue_estimates_are_derived(): void
    {
        $slot = $this->createSlot('2026-10-19', '09:00', 3);
        $student = User::where('username', 'student')->firstOrFail();
        $otherStudent = $this->createStudent('student-queue', '2026-00004');
        $registrar = User::where('username', 'registrar')->firstOrFail();
        $first = $this->actingAs($student)
            ->postJson('/api/appointments', ['slotId' => $slot->id, 'studentNumber' => $otherStudent->studentNumber])
            ->assertCreated()
            ->json('data');
        $second = $this->actingAs($otherStudent)
            ->postJson('/api/appointments', ['slotId' => $slot->id])
            ->assertCreated()
            ->json('data');

        $this->travelTo(Carbon::parse('2026-10-19 08:30:00', 'Asia/Manila'));
        $this->actingAs($otherStudent)
            ->patchJson("/api/appointments/{$first['id']}/check-in")
            ->assertForbidden();
        $this->actingAs($student)
            ->patchJson("/api/appointments/{$first['id']}/check-in")
            ->assertForbidden();
        $this->actingAs($registrar)
            ->patchJson("/api/appointments/{$first['id']}/check-in")
            ->assertOk()
            ->assertJsonPath('data.status', 'WAITING');
        $this->actingAs($registrar)
            ->patchJson("/api/appointments/{$second['id']}/check-in")
            ->assertOk();
        $this->actingAs($registrar)
            ->postJson("/api/appointment-queue/{$slot->serviceId}/call-next")
            ->assertOk();

        $queue = $this->actingAs($registrar)
            ->getJson("/api/appointment-queue/{$slot->serviceId}")
            ->assertOk();
        $this->assertSame(2, $queue->json('data.appointments.1.queuePosition'));
        $this->assertSame(30, $queue->json('data.appointments.1.estimatedWaitMinutes'));
        $this->assertSame('2026-00001', $queue->json('data.appointments.0.studentNumber'));
    }

    public function test_staff_override_requires_reason_and_history_records_it(): void
    {
        $slot = $this->createSlot('2026-10-19', '09:00', 2);
        $firstStudent = User::where('username', 'student')->firstOrFail();
        $secondStudent = $this->createStudent('student-override', '2026-00005');
        $registrar = User::where('username', 'registrar')->firstOrFail();
        $first = $this->actingAs($firstStudent)->postJson('/api/appointments', ['slotId' => $slot->id])->json('data');
        $second = $this->actingAs($secondStudent)->postJson('/api/appointments', ['slotId' => $slot->id])->json('data');
        $this->travelTo(Carbon::parse('2026-10-19 08:30:00', 'Asia/Manila'));
        $this->actingAs($registrar)->patchJson("/api/appointments/{$first['id']}/check-in")->assertOk();
        $this->actingAs($registrar)->patchJson("/api/appointments/{$second['id']}/check-in")->assertOk();
        $secondQueueEntryId = AppointmentQueueEntry::where('appointmentId', $second['id'])->value('id');
        $this->actingAs($registrar)
            ->postJson("/api/staff/queue/{$secondQueueEntryId}/call")
            ->assertStatus(409);

        $this->actingAs($registrar)
            ->postJson("/api/appointment-queue/{$slot->serviceId}/override-call", ['appointmentId' => $second['id']])
            ->assertUnprocessable();
        $this->actingAs($registrar)
            ->postJson("/api/staff/queue/{$secondQueueEntryId}/override")
            ->assertUnprocessable();
        $this->actingAs($registrar)
            ->postJson("/api/staff/queue/{$secondQueueEntryId}/override", ['reason' => 'Approved accessibility accommodation'])
            ->assertOk()
            ->assertJsonPath('data.queueStatus', 'CALLED');

        $this->actingAs($registrar)
            ->getJson("/api/appointments/{$second['id']}/history")
            ->assertOk()
            ->assertJsonFragment([
                'eventType' => 'staff_override_call',
                'reason' => 'Approved accessibility accommodation',
            ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'Appointment Queue Override',
            'module' => 'Appointments',
        ]);
    }

    public function test_reference_numbers_are_unique_and_use_the_configured_readable_format(): void
    {
        $firstSlot = $this->createSlot('2026-10-20', '09:00', 1);
        $secondSlot = $this->createSlot('2026-10-20', '10:00', 1);
        $student = User::where('username', 'student')->firstOrFail();
        $otherStudent = $this->createStudent('student-reference', '2026-00006');

        $first = $this->actingAs($student)->postJson('/api/appointments', ['slotId' => $firstSlot->id])->assertCreated();
        $second = $this->actingAs($otherStudent)->postJson('/api/appointments', ['slotId' => $secondSlot->id])->assertCreated();

        $this->assertTrue($first->json('success'));
        $this->assertSame('Appointment created successfully.', $first->json('message'));
        $this->assertSame('PNC-20261020-0001', $first->json('data.appointmentNumber'));
        $this->assertSame('PNC-20261020-0002', $second->json('data.appointmentNumber'));
        $this->assertDatabaseHas('appointment_status_history', [
            'appointmentId' => $first->json('data.id'),
            'eventType' => 'appointment_confirmed',
            'newStatus' => 'CONFIRMED',
        ]);
        $delivery = DB::table('appointment_notification_deliveries')
            ->where('appointmentId', $first->json('data.id'))
            ->where('notificationType', 'CONFIRMED')
            ->first();
        $this->assertNotNull($delivery);
        $this->assertContains($delivery->status, ['QUEUED', 'SENT']);
    }

    public function test_existing_appointment_snapshots_survive_future_schedule_and_service_edits(): void
    {
        $slot = $this->createSlot('2026-10-20', '09:00', 2);
        $student = User::where('username', 'student')->firstOrFail();
        $registrar = User::where('username', 'admin')->firstOrFail();
        $service = $slot->service;
        $appointment = $this->actingAs($student)
            ->postJson('/api/appointments', ['slotId' => $slot->id])
            ->assertCreated()
            ->json('data');

        $this->actingAs($registrar)
            ->patchJson("/api/appointment-slots/{$slot->id}", ['startTime' => '11:00', 'endTime' => '11:30'])
            ->assertStatus(409);
        $this->actingAs($registrar)
            ->putJson("/api/appointment-services/{$service->id}", [
                'name' => 'Updated service label',
                'cancellationCutoffMinutes' => 120,
                'earlyArrivalWindowMinutes' => 60,
                'lateArrivalGraceMinutes' => 15,
            ])
            ->assertOk();

        $this->actingAs($student)
            ->getJson("/api/appointments/{$appointment['id']}")
            ->assertOk()
            ->assertJsonPath('data.serviceName', $service->name)
            ->assertJsonPath('data.startTime', '09:00')
            ->assertJsonPath('data.endTime', '09:30')
            ->assertJsonPath('data.cancellationCutoffMinutes', 0)
            ->assertJsonPath('data.earlyArrivalWindowMinutes', 30)
            ->assertJsonPath('data.lateArrivalGraceMinutes', 0);
    }

    public function test_late_check_in_requires_a_staff_override_reason(): void
    {
        $slot = $this->createSlot('2026-10-19', '09:00', 1);
        $student = User::where('username', 'student')->firstOrFail();
        $registrar = User::where('username', 'registrar')->firstOrFail();
        $appointment = $this->actingAs($student)
            ->postJson('/api/appointments', ['slotId' => $slot->id])
            ->assertCreated()
            ->json('data');
        $this->travelTo(Carbon::parse('2026-10-19 09:31:00', 'Asia/Manila'));

        $this->actingAs($student)
            ->patchJson("/api/appointments/{$appointment['id']}/check-in")
            ->assertForbidden();
        $this->actingAs($registrar)
            ->patchJson("/api/appointments/{$appointment['id']}/check-in")
            ->assertStatus(409);
        $this->actingAs($registrar)
            ->patchJson("/api/appointments/{$appointment['id']}/check-in", [
                'overrideReason' => 'Approved late arrival',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'WAITING');
        $this->assertDatabaseHas('appointment_status_history', [
            'appointmentId' => $appointment['id'],
            'eventType' => 'staff_override',
            'reason' => 'Approved late arrival',
        ]);
    }

    public function test_students_cannot_change_arbitrary_statuses_or_check_in_a_cancelled_appointment(): void
    {
        $slot = $this->createSlot('2026-10-20', '09:00', 1);
        $student = User::where('username', 'student')->firstOrFail();
        $registrar = User::where('username', 'registrar')->firstOrFail();
        $appointment = $this->actingAs($student)
            ->postJson('/api/appointments', ['slotId' => $slot->id])
            ->assertCreated()
            ->json('data');

        $this->actingAs($student)
            ->patchJson("/api/appointments/{$appointment['id']}/finish", ['status' => 'COMPLETED'])
            ->assertForbidden();
        $this->actingAs($student)
            ->patchJson("/api/appointments/{$appointment['id']}", ['status' => 'SERVING'])
            ->assertMethodNotAllowed();
        $this->actingAs($student)
            ->patchJson("/api/appointments/{$appointment['id']}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED');
        $this->actingAs($registrar)
            ->patchJson("/api/appointments/{$appointment['id']}/check-in")
            ->assertStatus(409);
    }

    public function test_daily_appointment_reminder_is_idempotent_and_tracked(): void
    {
        $slot = $this->createSlot('2026-10-20', '09:00', 1);
        $student = User::where('username', 'student')->firstOrFail();
        $appointment = $this->actingAs($student)
            ->postJson('/api/appointments', ['slotId' => $slot->id])
            ->assertCreated()
            ->json('data');

        Artisan::call('appointments:queue-reminders');
        Artisan::call('appointments:queue-reminders');

        $this->assertDatabaseHas('appointment_notification_deliveries', [
            'appointmentId' => $appointment['id'],
            'notificationType' => 'REMINDER_1_DAY',
        ]);
        $this->assertSame(
            1,
            DB::table('appointment_notification_deliveries')
                ->where('appointmentId', $appointment['id'])
                ->where('notificationType', 'REMINDER_1_DAY')
                ->count()
        );
    }

    private function createSlot(string $date, string $startTime, int $capacity): AppointmentSlot
    {
        $service = AppointmentService::where('code', 'CTC')->firstOrFail();
        $endTime = Carbon::createFromFormat('H:i', $startTime)->addMinutes(30)->format('H:i');

        return AppointmentSlot::create([
            'id' => fake()->uuid(),
            'serviceId' => $service->id,
            'appointmentDate' => $date,
            'startTime' => $startTime,
            'endTime' => $endTime,
            'capacity' => $capacity,
            'status' => 'Open',
            'createdAt' => now('Asia/Manila')->format('Y-m-d h:i A'),
            'updatedAt' => now('Asia/Manila')->format('Y-m-d h:i A'),
        ]);
    }

    private function createStudent(string $username, string $studentNumber): User
    {
        $student = User::create([
            'id' => 'USR-'.strtoupper(str_replace('-', '', $username)),
            'username' => $username,
            'password' => 'password',
            'role' => 'Student',
            'name' => 'Other Student',
            'studentNumber' => $studentNumber,
            'status' => 'Active',
        ]);

        Student::firstOrCreate(
            ['studentNumber' => $studentNumber],
            ['name' => $student->name]
        );

        return $student;
    }

    private function appointmentData(AppointmentSlot $slot, User $student, string $number = 'APT-20261020-0001'): array
    {
        return [
            'id' => fake()->uuid(),
            'appointmentNumber' => $number,
            'serviceId' => $slot->serviceId,
            'slotId' => $slot->id,
            'studentNumber' => $student->studentNumber,
            'studentName' => $student->name,
            'appointmentDate' => $slot->appointmentDate,
            'serviceNameSnapshot' => $slot->service->name,
            'startTimeSnapshot' => $slot->startTime,
            'endTimeSnapshot' => $slot->endTime,
            'serviceDurationMinutes' => 30,
            'cancellationCutoffMinutes' => $slot->service->cancellationCutoffMinutes,
            'lateArrivalGraceMinutes' => $slot->service->lateArrivalGraceMinutes,
            'earlyArrivalWindowMinutes' => $slot->service->earlyArrivalWindowMinutes,
            'status' => 'CONFIRMED',
            'createdAt' => now('Asia/Manila')->format('Y-m-d h:i A'),
            'updatedAt' => now('Asia/Manila')->format('Y-m-d h:i A'),
        ];
    }
}
