<?php

namespace App\Http\Controllers;

use App\Models\Enrollment;
use App\Models\Payment;
use App\Support\Oess;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $q = Payment::orderByDesc('seq');
        $q = Oess::scopeToStudent($q, $request->user(), $request->query('studentNumber'));
        $verifiedEnrollmentIds = null;
        if ($request->user()->role === 'Student' && $request->hasAny(['page', 'per_page'])) {
            $verifiedEnrollmentIds = (clone $q)->where('status', 'Verified')
                ->pluck('enrollmentId')->filter()->values();
        }
        $page = Oess::paginateIfRequested(
            $q,
            $request,
            ['id', 'studentNumber', 'studentName', 'paymentMethod', 'referenceNumber', 'status', 'remarks']
        );

        if ($page instanceof LengthAwarePaginator && $request->user()->role === 'Student') {
            return response()->json($page->toArray() + [
                'verifiedEnrollmentIds' => $verifiedEnrollmentIds,
            ]);
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
            'amount' => 'required|numeric|min:1',
            'paymentMethod' => 'required|string|max:255',
            'referenceNumber' => 'required|string|max:100',
            'proofFileName' => 'nullable|string|max:255',
            'proofFileSize' => 'nullable|string|max:50',
            'remarks' => 'nullable|string',
        ]);

        // default to the student's latest enrollment
        $enrollmentId = $data['enrollmentId']
            ?? Enrollment::where('studentNumber', $user->studentNumber)->orderByDesc('seq')->value('id');

        $payment = Payment::create([
            'id' => Oess::randomId(Payment::class, 'PAY-2026'),
            'enrollmentId' => $enrollmentId,
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
            'status' => 'required|string|max:50',
            'remarks' => 'nullable|string',
        ]);

        $payment = DB::transaction(function () use ($data, $id, $request) {
            $payment = Payment::findOrFail($id);
            $payment->status = $data['status'];
            if (! empty($data['remarks'])) {
                $payment->remarks = $data['remarks'];
            }
            $payment->verifiedBy = $request->user()->name;
            $payment->verifiedDate = Oess::now();
            $payment->save();

            if (strcasecmp(trim($data['status']), 'Verified') === 0 && $payment->enrollmentId) {
                $enrollment = Enrollment::where('id', $payment->enrollmentId)
                    ->where('studentNumber', $payment->studentNumber)
                    ->first();
                $enrollmentStatus = strtolower(preg_replace('/[\s_-]/', '', (string) $enrollment?->status));
                if ($enrollment && in_array($enrollmentStatus, ['approved', 'preassessed'], true)) {
                    $enrollment->status = 'Enrolled';
                    $enrollment->save();
                }
            }

            return $payment;
        });

        $amount = number_format($payment->amount, 2);
        Oess::audit($request->user(), "{$data['status']} Payment", 'Payments',
            "{$data['status']} payment {$id} for {$payment->studentName} (₱{$amount})");

        $remarks = ! empty($data['remarks']) ? " Remarks: {$data['remarks']}" : '';
        Oess::notifyStudent($payment->studentNumber, "Payment {$data['status']}",
            "Your payment transaction of ₱{$amount} was " . strtolower($data['status']) . " by the Cashier.{$remarks}", 'Payments');

        return $payment;
    }
}
