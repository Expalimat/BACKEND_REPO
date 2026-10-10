<?php

namespace App\Http\Controllers;

use App\Models\DocumentRequest;
use App\Support\Oess;
use Illuminate\Http\Request;

class DocumentRequestController extends Controller
{
    public function index(Request $request)
    {
        $q = DocumentRequest::orderByDesc('seq');
        return Oess::scopeToStudent($q, $request->user(), $request->query('studentNumber'))->get();
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (! $user->studentNumber) {
            return response()->json(['message' => 'No student number is linked to this account.'], 422);
        }

        $data = $request->validate([
            'documentType' => 'required|string|max:255',
            'purpose' => 'required|string',
            'details' => 'nullable|string',
        ]);

        $year = now('Asia/Manila')->year;
        $tracking = Oess::randomId(DocumentRequest::class, "TRK-{$year}", 'trackingNumber');

        $doc = DocumentRequest::create([
            'id' => Oess::randomId(DocumentRequest::class, "DOC-{$year}"),
            'trackingNumber' => $tracking,
            'studentNumber' => $user->studentNumber,
            'studentName' => $user->name,
            'documentType' => $data['documentType'],
            'purpose' => $data['purpose'],
            'requestDate' => Oess::now(),
            'status' => 'Pending',
            'releaseDate' => '',
            'remarks' => $data['details'] ?? 'Document request filed online.',
            'downloadUrl' => '',
            'processedBy' => '',
        ]);

        Oess::audit($user, 'Requested Document', 'Document Requests',
            "Submitted request for {$data['documentType']} (Tracking: {$tracking})");
        Oess::notifyRole('Registrar', 'New Document Request',
            "Student {$user->name} requested {$data['documentType']}.", 'Document Requests');

        return response()->json($doc->fresh(), 201);
    }

    public function updateStatus(Request $request, string $id)
    {
        $data = $request->validate([
            'status' => 'required|string|max:50',
            'remarks' => 'nullable|string',
        ]);

        $doc = DocumentRequest::findOrFail($id);
        $doc->status = $data['status'];
        if (! empty($data['remarks'])) {
            $doc->remarks = $data['remarks'];
        }
        $doc->processedBy = $request->user()->name;
        if ($data['status'] === 'Released') {
            $doc->releaseDate = Oess::now();
            $doc->downloadUrl = '#';
        }
        $doc->save();

        Oess::audit($request->user(), "{$data['status']} Document Request", 'Document Requests',
            "{$data['status']} request {$doc->trackingNumber} ({$doc->documentType}) for {$doc->studentName}");

        $remarks = ! empty($data['remarks']) ? " Remarks: {$data['remarks']}" : '';
        Oess::notifyStudent($doc->studentNumber, "Document Request {$data['status']}",
            "Your request for {$doc->documentType} ({$doc->trackingNumber}) is now " . strtolower($data['status']) . ".{$remarks}",
            'Document Requests');

        return $doc;
    }
}
