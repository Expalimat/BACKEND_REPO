<?php

namespace App\Http\Controllers;

use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\SubjectOffering;
use App\Models\SystemSetting;
use App\Models\Term;
use App\Support\Oess;
use Illuminate\Http\Request;

class EnrollmentController extends Controller
{
    public function terms()
    {
        return Term::orderBy('seq')->get();
    }

    public function offerings(Request $request)
    {
        $q = SubjectOffering::orderBy('seq');
        if ($request->filled('term')) {
            $q->where('term', 'like', '%' . $request->query('term') . '%');
        }
        return Oess::paginateIfRequested($q, $request, [
            'subjectCode', 'subjectTitle', 'section', 'term', 'instructor', 'schedule', 'room',
            'status', 'prerequisites',
        ]);
    }

    public function storeOffering(Request $request)
    {
        $data = $request->validate([
            'subjectCode' => 'required|string|max:50',
            'subjectTitle' => 'required|string|max:255',
            'units' => 'required|integer|min:0|max:12',
            'section' => 'required|string|max:100',
            'term' => 'required|string|max:100',
            'schedule' => 'nullable|string|max:255',
            'room' => 'nullable|string|max:100',
            'instructor' => 'nullable|string|max:255',
            'totalSlots' => 'required|integer|min:0',
            'prerequisites' => 'nullable|string|max:255',
        ]);

        $id = 'SEC-' . $data['subjectCode'] . '-' . preg_replace('/\s+/', '', $data['section']);
        if (SubjectOffering::where('id', $id)->exists()) {
            return response()->json(['message' => 'That subject section already exists.'], 422);
        }

        $offering = SubjectOffering::create($data + [
            'id' => $id,
            'availableSlots' => $data['totalSlots'],
            'isPrerequisiteSatisfied' => true,
            'status' => 'Open',
        ]);

        Oess::audit($request->user(), 'Created Subject Section', 'Subject Offerings', "Added {$offering->subjectCode} - {$offering->section}");
        return response()->json($offering->fresh(), 201);
    }

    public function updateOffering(Request $request, string $id)
    {
        $offering = SubjectOffering::findOrFail($id);
        $offering->update($request->only([
            'subjectTitle', 'units', 'section', 'term', 'schedule', 'room', 'instructor',
            'totalSlots', 'availableSlots', 'prerequisites', 'isPrerequisiteSatisfied', 'status',
        ]));

        Oess::audit($request->user(), 'Updated Subject Section', 'Subject Offerings', "Updated {$id}");
        return response()->json(true);
    }

    public function index(Request $request)
    {
        $q = Enrollment::orderByDesc('seq');
        return Oess::paginateIfRequested(
            Oess::scopeToStudent($q, $request->user(), $request->query('studentNumber')),
            $request,
            ['id', 'studentNumber', 'studentName', 'program', 'term', 'status', 'remarks']
        );
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (! $user->studentNumber) {
            return response()->json(['message' => 'No student number is linked to this account.'], 422);
        }

        $data = $request->validate([
            'term' => 'nullable|string|max:100',
            'totalUnits' => 'required|integer|min:0',
            'items' => 'required|array|min:1',
            'items.*.subjectCode' => 'required|string|max:50',
            'fees' => 'required|array',
        ]);

        $settings = SystemSetting::first()?->data ?? [];
        $term = $data['term'] ?? trim(($settings['semester'] ?? '') . ' ' . ($settings['academicYear'] ?? ''));
        // "2nd Semester 2025-2026" -> semester + academic year
        $semester = $settings['semester'] ?? null;
        $academicYear = $settings['academicYear'] ?? null;
        if (preg_match('/^(.*?)\s+(\d{4}-\d{4})$/', $term, $m)) {
            [$semester, $academicYear] = [$m[1], $m[2]];
        }

        $termEnrollments = Enrollment::where('studentNumber', $user->studentNumber)
            ->where('term', $term)
            ->get();
        $verifiedEnrollmentIds = Payment::where('studentNumber', $user->studentNumber)
            ->where('status', 'Verified')
            ->whereIn('enrollmentId', $termEnrollments->pluck('id'))
            ->pluck('enrollmentId')
            ->all();
        $registrarApprovedStatuses = ['approved', 'preassessed'];
        $verifiedEnrollmentIds = $termEnrollments
            ->filter(function ($enrollment) use ($registrarApprovedStatuses, $verifiedEnrollmentIds) {
                $status = strtolower(preg_replace('/[\s_-]/', '', (string) $enrollment->status));
                return in_array($status, $registrarApprovedStatuses, true)
                    && in_array($enrollment->id, $verifiedEnrollmentIds, true);
            })
            ->pluck('id')
            ->all();
        $hasActiveApplication = $termEnrollments->contains(function ($enrollment) use ($verifiedEnrollmentIds) {
            $status = strtolower(preg_replace('/[\s_-]/', '', (string) $enrollment->status));
            return in_array($status, ['pending', 'approved', 'preassessed'], true)
                && ! in_array($enrollment->id, $verifiedEnrollmentIds, true);
        });

        if ($hasActiveApplication) {
            return response()->json([
                'message' => 'You already have an enrollment application awaiting completion for this term.',
            ], 422);
        }

        $completedSubjectCodes = $termEnrollments
            ->filter(function ($enrollment) use ($verifiedEnrollmentIds) {
                $status = strtolower(preg_replace('/[\s_-]/', '', (string) $enrollment->status));
                return in_array($status, ['enrolled', 'completed', 'confirmed'], true)
                    || in_array($enrollment->id, $verifiedEnrollmentIds, true);
            })
            ->flatMap(fn ($enrollment) => collect($enrollment->items ?? [])
                ->pluck('subjectCode')
                ->map(fn ($subjectCode) => strtoupper(trim((string) $subjectCode))))
            ->filter()
            ->unique()
            ->all();
        $submittedSubjectCodes = collect($data['items'])
            ->pluck('subjectCode')
            ->map(fn ($subjectCode) => strtoupper(trim($subjectCode)));
        $duplicateSubjectCodes = $submittedSubjectCodes
            ->filter(fn ($subjectCode) => in_array($subjectCode, $completedSubjectCodes, true))
            ->unique()
            ->values();

        if ($submittedSubjectCodes->count() !== $submittedSubjectCodes->unique()->count()) {
            return response()->json(['message' => 'An enrollment cannot contain the same subject more than once.'], 422);
        }
        if ($duplicateSubjectCodes->isNotEmpty()) {
            return response()->json([
                'message' => 'These subjects are already enrolled for this term: ' . $duplicateSubjectCodes->implode(', ') . '.',
            ], 422);
        }

        $enrollment = Enrollment::create([
            'id' => Oess::nextId(Enrollment::class, 'ENR-2026'),
            'studentNumber' => $user->studentNumber,
            'studentName' => $user->name,
            'program' => $user->program,
            'term' => $term,
            'academicYear' => $academicYear,
            'semester' => $semester,
            'dateSubmitted' => Oess::now(),
            'status' => 'Pending',
            'totalUnits' => $data['totalUnits'],
            'remarks' => '',
            'items' => $data['items'],
            'fees' => $data['fees'],
        ]);

        Oess::audit($user, 'Submitted Online Enrollment', 'Enrollment',
            'Submitted ' . count($data['items']) . " subjects totaling {$data['totalUnits']} units");
        Oess::notifyRole('Registrar', 'New Enrollment Submission',
            "Student {$user->name} submitted an enrollment application for review.", 'Enrollment');

        return response()->json($enrollment->fresh(), 201);
    }

    public function updateStatus(Request $request, string $id)
    {
        $data = $request->validate([
            'status' => 'required|string|max:50',
            'remarks' => 'nullable|string',
        ]);

        $enrollment = Enrollment::findOrFail($id);
        $enrollment->status = $data['status'];
        if (! empty($data['remarks'])) {
            $enrollment->remarks = $data['remarks'];
        }
        $enrollment->save();

        Oess::audit($request->user(), "{$data['status']} Enrollment", 'Enrollment',
            "{$data['status']} enrollment request {$id} for {$enrollment->studentName}");
        return $enrollment;
    }
}
