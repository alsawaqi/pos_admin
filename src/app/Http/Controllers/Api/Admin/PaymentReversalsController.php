<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Payments\ApplyPaymentReversalResultAction;
use App\Actions\Payments\ReversalException;
use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Http\Controllers\Controller;
use App\Models\BankSoftPosProfile;
use App\Models\PaymentReversal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PaymentReversalsController extends Controller
{
    public function resolve(Request $request, string $uuid, ApplyPaymentReversalResultAction $results, WriteAuditLogAction $audit): JsonResponse
    {
        $this->authorize('manage', BankSoftPosProfile::class);
        $payload = $request->validate([
            'outcome' => ['required', 'in:approved,declined'],
            'evidence_note' => ['required', 'string', 'max:255'],
            'reversal_auth_code' => ['nullable', 'string', 'max:32'],
            'reversal_transaction_id' => ['nullable', 'string', 'max:64'],
        ]);
        try {
            $result = DB::transaction(function () use ($request, $uuid, $payload, $results, $audit): array {
                $result = $results->handle($uuid, [
                    'status' => $payload['outcome'], 'evidence_note' => $payload['evidence_note'],
                    'auth_code' => $payload['reversal_auth_code'] ?? null,
                    'reversal_transaction_id' => $payload['reversal_transaction_id'] ?? null,
                ], adminId: (int) $request->user()->id);
                $reversal = PaymentReversal::query()->where('uuid', $uuid)->firstOrFail();
                $audit->handle(new AuditLogData(
                    event: 'payment_reversal.resolved', actorUserId: (int) $request->user()->id,
                    companyId: (int) $reversal->company_id, branchId: (int) $reversal->branch_id,
                    auditableType: PaymentReversal::class, auditableId: (int) $reversal->id,
                    oldValues: ['status' => 'uncertain'],
                    newValues: ['status' => $payload['outcome'], 'evidence_note' => $payload['evidence_note'], 'ledger_payment_id' => $reversal->ledger_payment_id],
                ));

                return $result;
            }, 5);

            return response()->json(['data' => $result]);
        } catch (ReversalException $exception) {
            return response()->json(['code' => $exception->codeName, 'message' => $exception->getMessage()], $exception->httpStatus);
        }
    }
}
