<?php

namespace App\Http\Controllers;

use App\Models\Student;
use Illuminate\Http\Request;

class StudentController extends Controller
{
    // staff only (route middleware)
    public function index()
    {
        return Student::orderBy('seq')->get();
    }

    public function show(Request $request, string $studentNumber)
    {
        $user = $request->user();
        if ($user->role === 'Student' && $user->studentNumber !== $studentNumber) {
            return response()->json(['message' => 'You do not have permission to do that.'], 403);
        }
        return Student::where('studentNumber', $studentNumber)->first() ?? response()->json(null);
    }
}
