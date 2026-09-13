<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\BankSoftPosProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CardTerminalIssuesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', BankSoftPosProfile::class);
        $request->validate([
            'payments_page' => ['sometimes', 'integer', 'min:1'],
            'devices_page' => ['sometimes', 'integer', 'min:1'],
            'reversals_page' => ['sometimes', 'integer', 'min:1'],
        ]);

        return response()->json(['data' => [
            'payments' => DB::table('pos_payments')->where('softpos_mismatch', true)
                ->orderByDesc('id')->paginate(25, [
                    'uuid', 'order_id', 'device_id', 'bank_id', 'amount', 'captured_at',
                    'softpos_provider', 'softpos_reported_provider', 'softpos_mismatch_note',
                ], 'payments_page'),
            'devices' => DB::table('pos_devices')->whereNotNull('card_tenders_blocked_reason')
                ->whereNull('deleted_at')->orderByDesc('card_tenders_blocked_at')
                ->paginate(25, ['uuid', 'name', 'serial_number', 'bank_id', 'card_tenders_blocked_reason', 'card_tenders_blocked_at'], 'devices_page'),
            'reversals' => DB::table('pos_payment_reversals as r')
                ->leftJoin('pos_staff as s', 's.id', '=', 'r.approved_by_staff_id')
                ->where('r.status', 'uncertain')->orderBy('r.attempted_at')
                ->paginate(25, [
                    'r.uuid', 'r.order_id', 'r.payment_id', 'r.kind', 'r.amount', 'r.status',
                    'r.bank_id', 'r.softpos_provider', 'r.terminal_id', 'r.response_code',
                    'r.response_description', 'r.attempted_at', 's.name as approver',
                ], 'reversals_page'),
        ]]);
    }
}
