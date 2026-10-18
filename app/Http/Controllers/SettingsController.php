<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use App\Support\Oess;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function show()
    {
        return SystemSetting::first()?->data ?? (object) [];
    }

    public function update(Request $request)
    {
        // only these keys can be changed, and each one is type-checked
        $data = $request->validate([
            'academicYear' => ['sometimes', 'string', 'regex:/^\d{4}-\d{4}$/'],
            'semester' => 'sometimes|string|max:50',
            'enrollmentStatus' => 'sometimes|string|max:50',
            'gradeEncodingStatus' => 'sometimes|string|max:50',
            'clearancePeriodStatus' => 'sometimes|string|max:50',
            'maxUnitsRegular' => 'sometimes|integer|min:1|max:40',
            'maxUnitsIrregular' => 'sometimes|integer|min:1|max:40',
            'universityName' => 'sometimes|string|max:255',
            'systemVersion' => 'sometimes|string|max:50',
        ]);

        $row = SystemSetting::first() ?? SystemSetting::create(['data' => []]);
        $row->data = array_merge($row->data ?? [], $data);
        $row->save();

        $s = $row->data;
        Oess::audit(
            $request->user(),
            'Updated System Settings',
            'System Settings',
            "Updated configuration (Term: {$s['academicYear']} {$s['semester']}, Enrollment: {$s['enrollmentStatus']})"
        );
        return $row->data;
    }
}
