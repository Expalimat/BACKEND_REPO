<?php

namespace App\Http\Controllers;

use App\Models\Enrollment;
use App\Models\Payment;
use App\Support\Oess;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $q = Payment::orderByDesc('seq');
        $q = Oess::scopeToStudent($q, $request->user(), $request->query('studentNumber'));
        $isStudent = $request->user()->role === 'Student';

        // a student's paged list still needs to know which enrollments are already paid
        $verifiedEnrollmentIds = ($isStudent && $request->hasAny(['page', 'per_page']))
            ? (clone $q)->where('status', 'Verified')->pluck('enrollmentId')->filter()->values()
            : null;

        $page = Oess::paginateIfRequested(
            $q,
            $request,
            ['id', 'studentNumber', 'studentName', 'paymentMethod', 'referenceNumber', 'status', 'remarks']
        );

        if ($page instanceof LengthAwarePaginator && $isStudent) {
            return response()->json($page->toArray() + ['verifiedEnrollmentIds' => $verifiedEnrollmentIds]);
        }
        return $page;
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (! $user->studentNumber) {
            return response()->json(['message' => 'No student number is linked to this account.'], 422);
        }

        $data = $request->validate([
            'enrollmentId' => 'nullable|string|max:50',
            'amount' => 'required|numeric|min:1|max:1000000',
            'paymentMethod' => 'required|string|max:100',
            'referenceNumber' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9\- ]+$/'],
            'proofFileName' => ['nullable', 'string', 'max:200', 'regex:/^[\w\-. ()]+\.(jpe?g|png|pdf|webp)$/i'],
            'proofFileSize' => 'nullable|string|max:50',
            'remarks' => 'nullable|string|max:500',
        ]);

        // default to the student's latest enrollment
        $enrollmentQuery = Enrollment::where('studentNumber', $user->studentNumber);
        $enrollment = ! empty($data['enrollmentId'])
            ? $enrollmentQuery->where('id', $data['enrollmentId'])->first()
            : $enrollmentQuery->orderByDesc('seq')->first();

        if (! $enrollment) {
            return response()->json(['message' => 'No enrollment application found for this payment.'], 422);
        }

        // payment opens only after the Registrar approves or pre-assesses the enrollment
        $enrollmentStatus = strtolower(preg_replace('/[\s_-]/', '', (string) $enrollment->status));
        if (! in_array($enrollmentStatus, ['approved', 'preassessed'], true)) {
            return response()->json([
                'message' => 'Payment proof can only be submitted after the Registrar approves or pre-assesses your enrollment.',
            ], 422);
        }

        if (Payment::where('enrollmentId', $enrollment->id)->where('status', 'Verified')->exists()) {
            return response()->json(['message' => 'This enrollment already has a verified payment.'], 422);
        }

        // cannot pay more than the assessed total
        $feesTotal = (float) ($enrollment->fees['total'] ?? 0);
        if ($feesTotal > 0 && (float) $data['amount'] > $feesTotal + 0.01) {
            return response()->json(['message' => 'The amount is more than your assessed total.'], 422);
        }

        // the same receipt/reference number cannot be reused
        if (Payment::where('referenceNumber', $data['referenceNumber'])->where('status', '!=', 'Rejected')->exists()) {
            return response()->json(['message' => 'That reference number has already been used.'], 422);
        }

        $payment = Payment::create([
            'id' => Oess::randomId(Payment::class, 'PAY-2026'),
            'enrollmentId' => $enrollment->id,
            'studentNumber' => $user->studentNumber,
            'studentName' => $user->name,
            'amount' => $data['amount'],
            'paymentMethod' => $data['paymentMethod'],
            'referenceNumber' => $data['referenceNumber'],
            'paymentDate' => Oess::now(),
            'status' => 'Pending',
            'proofFileName' => $data['proofFileName'] ?? 'receipt_proof.jpg',
            'proofFileSize' => $data['proofFileSize'] ?? '1.2 MB',
            'verifiedBy' => '',
            'verifiedDate' => '',
            'remarks' => $data['remarks'] ?? '',
        ]);

        Oess::audit($user, 'Uploaded Payment Proof', 'Payments',
            "Uploaded payment proof for ₱{$data['amount']} (Ref: {$data['referenceNumber']})");
        Oess::notifyRole('Cashier', 'Payment Slip Uploaded',
            "Student {$user->name} uploaded payment slip (₱{$data['amount']}) for verification.", 'Payments');

        return response()->json($payment->fresh(), 201);
    }

    public function verify(Request $request, string $id)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['Verified', 'Rejected', 'Pending'])],
            'remarks' => 'nullable|string|max:500',
        ]);

        $payment = Payment::findOrFail($id);
        $payment->status = $data['status'];
        if (! empty($data['remarks'])) {
            $payment->remarks = $data['remarks'];
        }
        $payment->verifiedBy = $request->user()->name;
        $payment->verifiedDate = Oess::now();
        $payment->save();

        $amount = number_format($payment->amount, 2);
        Oess::audit($request->user(), "{$data['status']} Payment", 'Payments',
            "{$data['status']} payment {$id} for {$payment->studentName} (₱{$amount})");

        $remarks = ! empty($data['remarks']) ? " Remarks: {$data['remarks']}" : '';
        Oess::notifyStudent($payment->studentNumber, "Payment {$data['status']}",
            "Your payment transaction of ₱{$amount} was " . strtolower($data['status']) . " by the Cashier.{$remarks}", 'Payments');

        return $payment;
    }
}
