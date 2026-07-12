<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\ReconcilePaymentsAction;
use App\Enums\PlatformPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BankReconciliationCommitRequest;
use App\Http\Requests\Admin\BankReconciliationPreviewRequest;
use App\Models\Bank;
use App\Services\Admin\BankReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BankReconciliationController extends Controller
{
    public function __construct(
        private readonly BankReconciliationService $reconciliation,
        private readonly ReconcilePaymentsAction $reconcilePayments,
    ) {}

    public function preview(BankReconciliationPreviewRequest $request): JsonResponse
    {
        $this->ensureCanManage($request);

        $bank = Bank::query()->findOrFail((int) $request->validated('bank_id'));

        $preview = $this->reconciliation->preview(
            $bank,
            (string) $request->validated('statement_date'),
            $request->file('file'),
        );

        return response()->json(['data' => $preview]);
    }

    public function commit(BankReconciliationCommitRequest $request): JsonResponse
    {
        $this->ensureCanManage($request);

        // A2 — optional per-payment actual bank fee captured from the statement
        // ({ payment_id: fee }); persisted so settlement can pre-fill it.
        $fees = [];
        foreach ((array) $request->validated('fees', []) as $paymentId => $fee) {
            $fees[(int) $paymentId] = $fee;
        }

        $result = $this->reconcilePayments->handle(
            array_map('intval', $request->validated('payment_ids')),
            $request->user(),
            $fees,
        );

        return response()->json(['data' => $result]);
    }

    private function ensureCanManage(Request $request): void
    {
        abort_unless(
            (bool) $request->user()?->can(PlatformPermission::SettingsManage->value),
            403,
        );
    }
}
