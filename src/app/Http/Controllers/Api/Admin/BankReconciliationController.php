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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

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

        $statementToken = (string) Str::uuid();
        Cache::put('pos:bank-statement:'.$statementToken, [
            'actor_id' => (int) $request->user()->id,
            'bank_id' => (int) $bank->id,
            'statement_date' => (string) $request->validated('statement_date'),
            'matched' => $preview['matched'],
        ], now()->addMinutes(30));
        $preview['statement_token'] = $statementToken;

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
            $request->validated('statement_token'),
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
