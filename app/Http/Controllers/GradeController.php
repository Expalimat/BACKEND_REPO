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
        if ($request->filled('term')) {
            $q->where('term', $request->query('term'));
        }

        if ($request->hasAny(['page', 'per_page'])) {
            $summary = (clone $q)->get(['grade', 'units', 'status']);
            $validatedGrades = $summary->where('status', 'Validated');
            $gradePoints = 0;
            $validatedUnits = 0;
            foreach ($validatedGrades as $grade) {
                $numericGrade = filter_var($grade->grade, FILTER_VALIDATE_FLOAT);
                if ($numericGrade !== false) {
                    $gradePoints += $numericGrade * $grade->units;
                    $validatedUnits += $grade->units;
                }
            }

            $page = Oess::paginateIfRequested(
                $q,
                $request,
                ['studentNumber', 'studentName', 'term', 'subjectCode', 'subjectTitle', 'grade', 'status', 'encodedBy', 'validatedBy']
            );

            if ($page instanceof LengthAwarePaginator) {
                return response()->json($page->toArray() + [
                    'summary' => [
                        'validatedCount' => $validatedGrades->count(),
                        'gpa' => $validatedUnits > 0 ? number_format($gradePoints / $validatedUnits, 2) : 'N/A',
                    ],
                ]);
            }

            return $page;
        }

        return Oess::paginateIfRequested(
            $q,
            $request,
            ['studentNumber', 'studentName', 'term', 'subjectCode', 'subjectTitle', 'grade', 'status', 'encodedBy', 'validatedBy']
        );
    }

    // Department staff encodes (or re-encodes) a grade
    public function store(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'studentNumber' => 'required|string|max:50',
            'studentName' => 'nullable|string|max:255',
            'term' => 'nullable|string|max:100',
            'subjectCode' => 'required|string|max:50',
            'subjectTitle' => 'required|string|max:255',
            'units' => 'nullable|integer|min:0|max:12',
            'grade' => 'required|string|max:10',
        ]);

        $studentName = Student::where('studentNumber', $data['studentNumber'])->value('name')
            ?? ($data['studentName'] ?? $data['studentNumber']);

        $grade = Grade::where('studentNumber', $data['studentNumber'])
            ->where('subjectCode', $data['subjectCode'])->first();

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
