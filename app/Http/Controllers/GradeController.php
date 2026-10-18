<?php

namespace App\Http\Controllers;

use App\Models\Grade;
use App\Models\Student;
use App\Models\SystemSetting;
use App\Support\Oess;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class GradeController extends Controller
{
    public function index(Request $request)
    {
        $q = Grade::orderByDesc('seq');
        $q = Oess::scopeToStudent($q, $request->user(), $request->query('studentNumber'));
        if ($request->filled('term') && is_string($request->query('term'))) {
            $q->where('term', $request->query('term'));
        }

        $columns = ['studentNumber', 'studentName', 'term', 'subjectCode', 'subjectTitle', 'grade', 'status', 'encodedBy', 'validatedBy'];

        if (! $request->hasAny(['page', 'per_page'])) {
            return Oess::paginateIfRequested($q, $request, $columns);
        }

        // GPA is computed over every validated grade in scope, not just the current page
        $validated = (clone $q)->where('status', 'Validated')->get(['grade', 'units']);
        $gradePoints = 0;
        $validatedUnits = 0;
        foreach ($validated as $row) {
            $numeric = filter_var($row->grade, FILTER_VALIDATE_FLOAT);
            if ($numeric !== false) {
                $gradePoints += $numeric * $row->units;
                $validatedUnits += $row->units;
            }
        }

        $page = Oess::paginateIfRequested($q, $request, $columns);
        if ($page instanceof LengthAwarePaginator) {
            return response()->json($page->toArray() + [
                'summary' => [
                    'validatedCount' => $validated->count(),
                    'gpa' => $validatedUnits > 0 ? number_format($gradePoints / $validatedUnits, 2) : 'N/A',
                ],
            ]);
        }
        return $page;
    }

    // Department staff encodes (or re-encodes) a grade
    public function store(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'studentNumber' => 'required|string|max:50|exists:students,studentNumber',
            'term' => 'nullable|string|max:100',
            'subjectCode' => 'required|string|max:50',
            'subjectTitle' => 'required|string|max:255',
            'units' => 'nullable|integer|min:0|max:12',
            // 1.00 - 5.00, or INC / DRP / W / NG / UD
            'grade' => ['required', 'string', 'max:10', 'regex:/^(?:[1-5](?:\.\d{1,2})?|INC|DRP|W|NG|UD)$/i'],
        ]);

        $studentName = Student::where('studentNumber', $data['studentNumber'])->value('name');

        $grade = Grade::where('studentNumber', $data['studentNumber'])
            ->where('subjectCode', $data['subjectCode'])->first();

        // once the Registrar validated a grade it is locked (only the Administrator may change it)
        if ($grade && $grade->status === 'Validated' && $user->role !== 'Administrator') {
            return response()->json(['message' => 'This grade is already validated and locked.'], 403);
        }

        if ($grade) {
            $grade->update([
                'grade' => $data['grade'],
                'status' => 'Encoded',
                'encodedBy' => $user->name,
                'dateEncoded' => Oess::now(),
            ]);
        } else {
            $settings = SystemSetting::first()?->data ?? [];
            $grade = Grade::create([
                'id' => Oess::nextId(Grade::class, 'GRD'),
                'studentNumber' => $data['studentNumber'],
                'studentName' => $studentName,
                'term' => $data['term'] ?? trim(($settings['semester'] ?? '') . ' ' . ($settings['academicYear'] ?? '')),
                'subjectCode' => $data['subjectCode'],
                'subjectTitle' => $data['subjectTitle'],
                'units' => $data['units'] ?? 3,
                'grade' => $data['grade'],
                'status' => 'Encoded',
                'encodedBy' => $user->name,
                'dateEncoded' => Oess::now(),
                'validatedBy' => '',
                'dateValidated' => '',
            ]);
        }

        Oess::audit($user, 'Encoded Grade', 'Grades',
            "Encoded grade {$data['grade']} for {$studentName} ({$data['subjectCode']})");
        Oess::notifyRole('Registrar', 'New Grade Awaiting Validation',
            "Faculty {$user->name} encoded grade for {$data['subjectCode']} ({$studentName}).", 'Grades');

        return response()->json($grade->fresh(), 201);
    }

    // Registrar validates
    public function validateGrade(Request $request, string $id)
    {
        $grade = Grade::findOrFail($id);
        $grade->update([
            'status' => 'Validated',
            'validatedBy' => $request->user()->name,
            'dateValidated' => Oess::now(),
        ]);

        Oess::audit($request->user(), 'Validated Grade', 'Grades',
            "Validated grade for {$grade->studentName} in {$grade->subjectCode} ({$grade->grade})");
        Oess::notifyStudent($grade->studentNumber, 'Grade Validated',
            "Your official grade for {$grade->subjectCode} ({$grade->subjectTitle}) is now validated and available.", 'Grades');

        return $grade;
    }
}
