<?php

namespace App\Http\Controllers;

use App\Models\Clearance;
use App\Support\Oess;
use Illuminate\Http\Request;

class ClearanceController extends Controller
{
    // which office each staff role signs for
    private const OFFICE_BY_ROLE = [
        'Department Staff' => 'Department',
        'Cashier' => 'Cashier',
        'Registrar' => 'Registrar',
    ];

    public function index(Request $request)
    {
        $q = Clearance::orderBy('seq');
        return Oess::scopeToStudent($q, $request->user(), $request->query('studentNumber'))->get();
    }

    public function sign(Request $request, string $id)
    {
        $user = $request->user();
        $data = $request->validate([
            'status' => 'required|string|max:50',
            'remarks' => 'nullable|string',
            'officeType' => 'nullable|string|in:Department,Cashier,Registrar',
        ]);

        // staff sign for their own office, only an admin may choose the office
        $office = self::OFFICE_BY_ROLE[$user->role] ?? ($data['officeType'] ?? null);
        if (! $office) {
            return response()->json(['message' => 'officeType is required.'], 422);
        }

        $clearance = Clearance::findOrFail($id);
        $signoffs = collect($clearance->signoffs)->map(function ($s) use ($office, $data, $user) {
            if (str_contains(strtolower($s['officeName']), strtolower($office))) {
                $s['status'] = $data['status'];
                $s['signedBy'] = $user->name;
                $s['signedDate'] = Oess::now();
                $s['remarks'] = ! empty($data['remarks']) ? $data['remarks'] : ($s['remarks'] ?? '');
            }
            return $s;
        })->values()->all();

        $clearance->signoffs = $signoffs;
        $clearance->overallStatus = collect($signoffs)->every(fn ($s) => $s['status'] === 'Signed')
            ? 'Clearance Complete' : 'Clearance Pending';
        $clearance->save();

        Oess::audit($user, "{$data['status']} Clearance ({$office})", 'Clearance',
            "{$data['status']} clearance signoff for student {$clearance->studentName} ({$clearance->studentNumber})");
        Oess::notifyStudent($clearance->studentNumber, "Clearance Signoff Updated ({$office})",
            "Your clearance signoff for {$office} has been marked as {$data['status']}.", 'Clearance');

        return $clearance;
    }
}
