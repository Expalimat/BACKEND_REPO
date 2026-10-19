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
        $row = SystemSetting::first() ?? SystemSetting::create(['data' => []]);
        $current = $row->data ?? [];

        // only known keys can be changed
        $row->data = array_merge($current, $request->only(array_keys($current)));
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
