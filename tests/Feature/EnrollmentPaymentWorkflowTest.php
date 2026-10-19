<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnrollmentPaymentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_cashier_verification_completes_a_registrar_approved_enrollment(): void
    {
        $enrollment = Enrollment::findOrFail('ENR-2026-002');
        $enrollment->status = 'Pre-Assessed';
        $enrollment->save();

        Payment::create([
            'id' => 'PAY-WORKFLOW-001',
            'enrollmentId' => $enrollment->id,
            'studentNumber' => $enrollment->studentNumber,
            'studentName' => $enrollment->studentName,
            'amount' => 6850,
            'paymentMethod' => 'Landbank of the Philippines (LBP)',
            'referenceNumber' => 'TEST-REF-001',
            'paymentDate' => '2026-10-14 09:00 AM',
            'status' => 'Pending',
            'proofFileName' => 'receipt.pdf',
            'proofFileSize' => '1 KB',
        ]);

        $cashier = User::where('username', 'cashier')->firstOrFail();
        $this->actingAs($cashier)
            ->patchJson('/api/payments/PAY-WORKFLOW-001/verify', ['status' => 'Verified'])
            ->assertOk()
            ->assertJsonPath('status', 'Verified');

        $this->assertDatabaseHas('enrollments', [
            'id' => $enrollment->id,
            'status' => 'Enrolled',
        ]);
    }

    public function test_student_can_submit_another_application_after_completed_enrollment_but_not_duplicate_subjects(): void
    {
        $student = User::where('username', 'student')->firstOrFail();
        $this->actingAs($student)
            ->postJson('/api/enrollments', $this->enrollmentPayload(['IT201']))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'These subjects are already enrolled for this term: IT201.');

        $this->actingAs($student)
            ->postJson('/api/enrollments', $this->enrollmentPayload(['IT206']))
            ->assertCreated()
            ->assertJsonPath('status', 'Pending');
    }

    public function test_student_cannot_submit_another_application_while_registrar_review_is_pending(): void
    {
        $student = User::where('username', 'student')->firstOrFail();
        $student->studentNumber = '2026-00002';
        $student->save();

        $this->actingAs($student)
            ->postJson('/api/enrollments', $this->enrollmentPayload(['CS304']))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'You already have an enrollment application awaiting completion for this term.');
    }

    private function enrollmentPayload(array $subjectCodes): array
    {
        return [
            'term' => '2nd Semester 2025-2026',
            'totalUnits' => count($subjectCodes) * 3,
            'items' => array_map(fn ($subjectCode) => [
                'subjectCode' => $subjectCode,
                'subjectTitle' => "{$subjectCode} Test Subject",
                'units' => 3,
                'section' => 'BSIT 2A',
                'schedule' => 'Mon 08:00 AM',
                'room' => 'Room 1',
            ], $subjectCodes),
            'fees' => ['total' => 1000],
        ];
    }
}
