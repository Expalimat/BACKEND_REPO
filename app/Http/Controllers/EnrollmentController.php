<?php

namespace App\Http\Controllers;

use App\Models\Enrollment;
use App\Models\SubjectOffering;
use App\Models\SystemSetting;
use App\Models\Term;
use App\Support\Oess;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class EnrollmentController extends Controller
{
    public function terms()
    {
        return Term::orderBy('seq')->get();
    }

    public function offerings(Request $request)
    {
        $request->validate(['term' => 'nullable|string|max:100']);

        $q = SubjectOffering::orderBy('seq');
        if ($request->filled('term')) {
            $q->where('term', 'like', '%' . Oess::escapeLike($request->query('term')) . '%');
        }
        return Oess::paginateIfRequested($q, $request, [
            'subjectCode', 'subjectTitle', 'section', 'term', 'instructor', 'schedule', 'room',
            'status', 'prerequisites',
        ]);
    }

    public function storeOffering(Request $request)
    {
        $data = $request->validate([
            'subjectCode' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9 _.-]+$/'],
            'subjectTitle' => 'required|string|max:255',
            'units' => 'required|integer|min:0|max:12',
            'section' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9 _.-]+$/'],
            'term' => 'required|string|max:100',
            'schedule' => 'nullable|string|max:255',
            'room' => 'nullable|string|max:100',
            'instructor' => 'nullable|string|max:255',
            'totalSlots' => 'required|integer|min:0|max:500',
            'prerequisites' => 'nullable|string|max:255',
        ]);

        $id = 'SEC-' . preg_replace('/\s+/', '', $data['subjectCode']) . '-' . preg_replace('/\s+/', '', $data['section']);
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

        $data = $request->validate([
            'subjectTitle' => 'sometimes|string|max:255',
            'units' => 'sometimes|integer|min:0|max:12',
            'section' => ['sometimes', 'string', 'max:100', 'regex:/^[A-Za-z0-9 _.-]+$/'],
            'term' => 'sometimes|string|max:100',
            'schedule' => 'nullable|string|max:255',
            'room' => 'nullable|string|max:100',
            'instructor' => 'nullable|string|max:255',
            'totalSlots' => 'sometimes|integer|min:0|max:500',
            'availableSlots' => 'sometimes|integer|min:0|max:500',
            'prerequisites' => 'nullable|string|max:255',
            'isPrerequisiteSatisfied' => 'sometimes|boolean',
            'status' => ['sometimes', Rule::in(['Open', 'Full', 'Closed'])],
        ]);

        $total = $data['totalSlots'] ?? $offering->totalSlots;
        $available = $data['availableSlots'] ?? $offering->availableSlots;
        if ($available > $total) {
            return response()->json(['message' => 'Available slots cannot be more than total slots.'], 422);
        }

        $offering->update($data);

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

        $settings = SystemSetting::first()?->data ?? [];
        if (($settings['enrollmentStatus'] ?? 'Open') !== 'Open') {
            return response()->json(['message' => 'Enrollment is currently closed.'], 422);
        }

        $data = $request->validate([
            'term' => 'nullable|string|max:100',
            'items' => 'required|array|min:1|max:15',
            'items.*.subjectCode' => 'required|string|max:50|distinct',
            'items.*.section' => 'required|string|max:100',
            'fees' => 'required|array',
            'fees.tuition' => 'required|numeric|min:0|max:1000000',
            'fees.miscellaneous' => 'required|numeric|min:0|max:1000000',
            'fees.laboratory' => 'required|numeric|min:0|max:1000000',
            'fees.library' => 'required|numeric|min:0|max:1000000',
            'fees.athletic' => 'required|numeric|min:0|max:1000000',
            'fees.total' => 'required|numeric|min:0|max:1000000',
        ]);

        // Never trust subject details from the browser: rebuild every item from the real offerings
        $items = [];
        foreach ($data['items'] as $row) {
            $offering = SubjectOffering::where('subjectCode', $row['subjectCode'])
                ->where('section', $row['section'])->first();
            if (! $offering) {
                return response()->json(['message' => "Subject {$row['subjectCode']} ({$row['section']}) does not exist."], 422);
            }
            $items[] = [
                'subjectCode' => $offering->subjectCode,
                'subjectTitle' => $offering->subjectTitle,
                'units' => (int) $offering->units,
                'section' => $offering->section,
                'schedule' => $offering->schedule,
                'room' => $offering->room,
            ];
        }

        $totalUnits = (int) collect($items)->sum('units');
        $maxUnits = (int) ($settings['maxUnitsRegular'] ?? 24);
        if ($totalUnits > $maxUnits) {
            return response()->json(['message' => "You cannot enroll in more than {$maxUnits} units."], 422);
        }

        $fees = $data['fees'];
        $feeParts = collect(Arr::except($fees, ['total']))->sum();
        if (abs($feeParts - (float) $fees['total']) > 0.01) {
            return response()->json(['message' => 'Fee total does not match the fee breakdown.'], 422);
        }

        $term = $data['term'] ?? trim(($settings['semester'] ?? '') . ' ' . ($settings['academicYear'] ?? ''));
        // "2nd Semester 2025-2026" -> semester + academic year
        $semester = $settings['semester'] ?? null;
        $academicYear = $settings['academicYear'] ?? null;
        if (preg_match('/^(.*?)\s+(\d{4}-\d{4})$/', $term, $m)) {
            [$semester, $academicYear] = [$m[1], $m[2]];
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
            'totalUnits' => $totalUnits,
            'remarks' => '',
            'items' => $items,
            'fees' => $fees,
        ]);

        Oess::audit($user, 'Submitted Online Enrollment', 'Enrollment',
            'Submitted ' . count($items) . " subjects totaling {$totalUnits} units");
        Oess::notifyRole('Registrar', 'New Enrollment Submission',
            "Student {$user->name} submitted an enrollment application for review.", 'Enrollment');

        return response()->json($enrollment->fresh(), 201);
    }

    public function updateStatus(Request $request, string $id)
    {
        $data = $request->validate([
            // "Pre-Assessed" = Registrar reviewed the student's prerequisites/curriculum standing
            // and opened payment, without a full approval yet.
            'status' => 'required|string|in:Pending,Approved,Pre-Assessed,Rejected,Enrolled',
            'remarks' => 'nullable|string|max:500',
        ]);

        $enrollment = Enrollment::findOrFail($id);
        $enrollment->status = $data['status'];
        if (! empty($data['remarks'])) {
            $enrollment->remarks = $data['remarks'];
        }
        $enrollment->save();

        Oess::audit($request->user(), "{$data['status']} Enrollment", 'Enrollment',
            "{$data['status']} enrollment request {$id} for {$enrollment->studentName}");

        $studentMessage = match ($data['status']) {
            'Approved' => 'Your enrollment application was approved by the Registrar. You may now submit your proof of payment.',
            'Pre-Assessed' => 'Your enrollment application was pre-assessed by the Registrar. You may now submit your proof of payment.',
            'Rejected' => 'Your enrollment application was not approved by the Registrar.',
            default => null,
        };
        if ($studentMessage) {
            $remarks = ! empty($data['remarks']) ? " Remarks: {$data['remarks']}" : '';
            Oess::notifyStudent($enrollment->studentNumber, "Enrollment {$data['status']}", $studentMessage . $remarks, 'Enrollment');
        }

        return $enrollment;
    }
}
