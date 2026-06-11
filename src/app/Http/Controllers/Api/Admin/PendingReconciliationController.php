<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\Reconciliation\ApprovePendingReconciliationAction;
use App\Actions\Admin\Reconciliation\RejectPendingReconciliationAction;
use App\Enums\PlatformPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PendingReconciliationDecisionRequest;
use App\Models\Device;
use App\Models\Order;
use App\Models\Payment;
use App\Models\RoundupDonation;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * P-F7 — the daily Pending Reconciliation approval queue (separate from the
 * bank-file matching tool, though both settle through the same actions).
 *
 *   GET  /admin/api/v1/pending-reconciliation          → ORDERS with ≥1
 *        pending_reconciliation tender captured on ?date (default today),
 *        with the evidence the admin checks against the bank file
 *        (merchant/branch/device, Soft POS reference + auth code, the raw
 *        bank verdict, any round-up riding the sale).
 *   POST /admin/api/v1/pending-reconciliation/approve  → money confirmed:
 *        flip the tenders + fire the deferred money effects (commission
 *        split + charity round-up forwarding).
 *   POST /admin/api/v1/pending-reconciliation/reject   → money never
 *        arrived: tenders marked failed; the order is NOT auto-voided.
 *
 * Platform-wide by design (the platform admin sees every merchant, like the
 * /admin/orders viewer). Gated by settings.manage — the same permission as
 * the bank reconciliation endpoints.
 */
class PendingReconciliationController extends Controller
{
    public function __construct(
        private readonly ApprovePendingReconciliationAction $approveAction,
        private readonly RejectPendingReconciliationAction $rejectAction,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->ensureCanManage($request);

        $day = $this->parseDay($request->query('date'));

        $pendingTenders = Payment::query()
            ->where('pending_reconciliation', true)
            ->whereBetween('captured_at', [$day->startOfDay(), $day->endOfDay()]);

        $page = Order::query()
            ->whereIn('id', (clone $pendingTenders)->select('order_id'))
            ->with(['company:id,uuid,name,name_ar', 'branch:id,uuid,name'])
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->paginate(min($request->integer('per_page', 25), 100));

        $orderIds = $page->getCollection()->pluck('id')->all();

        // Every still-pending tender of the listed orders (not just the
        // day-matched one — a split's other pending half is evidence too).
        $tendersByOrder = Payment::query()
            ->whereIn('order_id', $orderIds)
            ->where('pending_reconciliation', true)
            ->orderBy('id')
            ->get()
            ->groupBy('order_id');

        $deviceIds = $tendersByOrder->flatten()->pluck('device_id')->filter()->unique()->values();
        $devices = Device::query()->whereIn('id', $deviceIds)->get(['id', 'name', 'kiosk_id'])->keyBy('id');

        $donationsByOrder = RoundupDonation::query()
            ->whereIn('order_id', $orderIds)
            ->get(['id', 'order_id', 'amount', 'forwarded_at'])
            ->groupBy('order_id');

        $rows = $page->getCollection()->map(function (Order $order) use ($tendersByOrder, $devices, $donationsByOrder): array {
            $tenders = $tendersByOrder->get($order->id, collect());
            $donations = $donationsByOrder->get($order->id, collect());

            $pendingBaisas = 0;
            $tenderRows = [];
            $deviceName = null;
            foreach ($tenders as $tender) {
                $pendingBaisas += Money::toBaisas($tender->amount);
                $device = $tender->device_id !== null ? $devices->get($tender->device_id) : null;
                if ($deviceName === null && $device !== null) {
                    $deviceName = (string) ($device->name ?? $device->kiosk_id);
                }
                $tenderRows[] = [
                    'id' => (int) $tender->id,
                    'method' => $tender->method instanceof \BackedEnum ? $tender->method->value : (string) $tender->method,
                    'amount' => (string) $tender->amount,
                    'softpos_reference' => $tender->softpos_reference,
                    'softpos_auth_code' => $tender->softpos_auth_code,
                    // The raw Soft POS verdict the device recorded (often
                    // 'timeout' on a force-record) — display evidence only.
                    'bank_verdict' => is_array($tender->bank_response) ? ($tender->bank_response['status'] ?? null) : null,
                    'captured_at' => $tender->captured_at?->toIso8601String(),
                ];
            }

            $roundupBaisas = 0;
            foreach ($donations as $donation) {
                $roundupBaisas += Money::toBaisas($donation->amount);
            }

            return [
                'id' => (int) $order->id,
                'uuid' => (string) $order->uuid,
                'company' => $order->company === null ? null : [
                    'uuid' => $order->company->uuid,
                    'name' => $order->company->name,
                    'name_ar' => $order->company->name_ar,
                ],
                'branch' => $order->branch === null ? null : [
                    'uuid' => $order->branch->uuid,
                    'name' => $order->branch->name,
                ],
                'device_name' => $deviceName,
                'opened_at' => $order->opened_at?->toIso8601String(),
                'grand_total' => (string) $order->grand_total,
                'pending_total' => Money::toOmr($pendingBaisas),
                'tenders' => $tenderRows,
                'roundup_total' => $donations->isEmpty() ? null : Money::toOmr($roundupBaisas),
                'roundup_unforwarded' => $donations->whereNull('forwarded_at')->count(),
            ];
        })->values();

        // Day totals over the WHOLE filtered set (not just the page).
        $totalPendingAmount = (float) (clone $pendingTenders)->sum('amount');

        return response()->json([
            'data' => $rows,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'totals' => [
                'orders' => $page->total(),
                'pending_amount' => number_format($totalPendingAmount, 3, '.', ''),
            ],
            'date' => $day->toDateString(),
        ]);
    }

    public function approve(PendingReconciliationDecisionRequest $request): JsonResponse
    {
        $this->ensureCanManage($request);

        $result = $this->approveAction->handle(
            array_map('intval', $request->validated('order_ids')),
            $request->user(),
        );

        return response()->json(['data' => $result]);
    }

    public function reject(PendingReconciliationDecisionRequest $request): JsonResponse
    {
        $this->ensureCanManage($request);

        $result = $this->rejectAction->handle(
            array_map('intval', $request->validated('order_ids')),
            $request->user(),
        );

        return response()->json(['data' => $result]);
    }

    /** Same gate as the bank reconciliation endpoints. */
    private function ensureCanManage(Request $request): void
    {
        abort_unless(
            (bool) $request->user()?->can(PlatformPermission::SettingsManage->value),
            403,
        );
    }

    private function parseDay(mixed $raw): CarbonImmutable
    {
        if (is_string($raw) && trim($raw) !== '') {
            try {
                return CarbonImmutable::parse(trim($raw));
            } catch (Throwable) {
                // fall through to today
            }
        }

        return CarbonImmutable::today();
    }
}
