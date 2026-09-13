<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Models\PaymentReversal;
use App\Support\Reversals\AppendTableSessionEventAction;
use App\Support\Reversals\CloseDineInQrSessionAction;
use App\Support\Reversals\CloseTableSessionForOrderAction;
use App\Support\Reversals\ConsumeInventoryAction;
use App\Support\Reversals\Models\Device;
use App\Support\Reversals\Models\Order;
use App\Support\Reversals\Models\VoidReason;
use App\Support\Reversals\VoidOrderCoreAction;
use App\Support\Reversals\WriteLoyaltyTransactionAction;
use stdClass;

/** Mirrors the API side effects using scalar models, independent of portal enum casts. */
final class ApplyReversalOrderEffectsAction
{
    public function __construct(private readonly ReturnRefundedUnitStockAction $stock) {}

    public function void(stdClass $order, PaymentReversal $reversal): void
    {
        [$core, $journal] = $this->core();
        $core->handle(
            Order::query()->findOrFail($order->id),
            Device::query()->findOrFail($reversal->device_id),
            now(),
            'VOID (card reversal '.$reversal->uuid.')',
            VoidReason::query()->findOrFail($reversal->void_reason_id),
        );
        // All domain writes have completed. Serialize journal ids before this
        // transaction commits, matching the API journal's cursor guarantee.
        $journal->flush();
    }

    public function refund(stdClass $order, PaymentReversal $reversal, bool $full): void
    {
        $this->stock->handle($reversal);
        if ($full) {
            [$core] = $this->core();
            $core->reverseLoyalty(Order::query()->findOrFail($order->id), now(), true);
        }
    }

    private function core(): array
    {
        $journal = new AppendTableSessionEventAction;

        return [
            new VoidOrderCoreAction(
                new ConsumeInventoryAction,
                new WriteLoyaltyTransactionAction,
                new CloseDineInQrSessionAction,
                new CloseTableSessionForOrderAction($journal),
            ),
            $journal,
        ];
    }
}
