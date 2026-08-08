<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Admin\Reconciliation\ForwardCharityDonationAction;
use App\Models\RoundupDonation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Master-plan step 10 — the hourly retry sweep for charity round-ups whose
 * forward to the charity app failed (outage, network blip). Without it, a
 * customer's donation could sit unforwarded forever: the inline forward at
 * donation.record is deliberately best-effort (a till must never block on
 * a third system) and nothing retried ordinary failures.
 *
 * Eligibility guards:
 *   - forwarded_at IS NULL (never forwarded), AND
 *   - status IS success, or pending whose linked payment is now settled
 *     (rejected, void, and unconfirmed pending money never leaves), AND
 *   - NO tender on the order is pending_reconciliation — split tenders defer
 *     to the admin approval flow (ReconcileDeferredEffectsAction), which owns
 *     their forwarding and their 'success' status override, AND
 *   - the donation is ≥10 minutes old (the inline attempt gets its chance;
 *     never race an in-flight first forward).
 *
 * Safe to retry: the forward carries pos_reference (the donation uuid) and
 * the charity endpoint dedupes on it — a lost-response success replayed by
 * this sweep answers idempotently instead of double-counting the money.
 */
class RetryRoundupForwarding extends Command
{
    protected $signature = 'donations:retry-roundup-forwarding {--limit=200 : Max forwarding attempts per run}';

    protected $description = 'Re-forward charity round-ups whose first forward failed (excludes pending-reconciliation ones)';

    public function __construct(private readonly ForwardCharityDonationAction $forwarder)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $cutoff = now()->subMinutes(10);
        $donations = RoundupDonation::query()
            ->whereNull('forwarded_at')
            ->whereIn('status', ['success', 'pending'])
            ->where('created_at', '<=', $cutoff)
            ->lazyById(200);

        $scanned = 0;
        $attempted = 0;
        $forwarded = 0;
        $deferred = 0;
        $missingPayments = 0;
        $missingDevices = 0;
        $missingBranches = 0;
        $failed = 0;

        foreach ($donations as $candidate) {
            if ($attempted >= $limit) {
                break;
            }

            $scanned++;
            $outcome = DB::transaction(function () use ($candidate, $cutoff): array {
                // order.void locks this row before it marks the donation void.
                // Take the same lock first so a committed void always wins the
                // re-check, while a retry that wins linearizes before the void.
                $order = DB::table('pos_orders')
                    ->where('id', $candidate->order_id)
                    ->lockForUpdate()
                    ->first(['id', 'status']);

                if ($order === null || (string) $order->status === 'void') {
                    return ['result' => 'stale'];
                }
                $payments = DB::table('pos_payments')
                    ->where('order_id', $order->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id', 'status', 'pending_reconciliation'])
                    ->keyBy('id');

                // The outer lazy query is only a candidate scan. Re-lock and
                // revalidate every mutable eligibility field before HTTP.
                $donation = RoundupDonation::query()
                    ->whereKey($candidate->id)
                    ->where('order_id', $order->id)
                    ->whereNull('forwarded_at')
                    ->whereIn('status', ['success', 'pending'])
                    ->where('created_at', '<=', $cutoff)
                    ->lockForUpdate()
                    ->first();

                if ($donation === null) {
                    return ['result' => 'stale'];
                }

                $payment = $payments->get((int) $donation->payment_id);
                if ($payment === null) {
                    Log::warning('Charity roundup retry candidate skipped', [
                        'donation_id' => (int) $donation->id,
                        'payment_id' => (int) $donation->payment_id,
                        'reason' => 'missing_payment',
                    ]);

                    return ['result' => 'missing_payment'];
                }
                if ($payments->contains(static fn (object $orderPayment): bool => (bool) $orderPayment->pending_reconciliation)) {
                    // The approval flow owns every split leg — never jump it.
                    return ['result' => 'deferred'];
                }

                if ((string) $donation->status === 'pending' && (string) $payment->status !== 'success') {
                    return ['result' => 'stale'];
                }

                // Keep the order + donation locks through the bounded external
                // call. Releasing them before HTTP would reopen the void race.
                // The forwarder is best-effort and returns false within 8s.
                $snapshot = clone $donation;
                $snapshot->forceFill(['status' => 'success']);
                $ok = $this->forwarder->forwardSnapshot($snapshot);

                if ($ok) {
                    $donation->forceFill(['forwarded_at' => now(), 'status' => 'success'])->save();
                }

                return [
                    'result' => $ok ? 'forwarded' : 'failed',
                ];
            });

            switch ($outcome['result']) {
                case 'forwarded':
                    $attempted++;
                    $forwarded++;
                    break;
                case 'failed':
                    $attempted++;
                    $failed++;
                    break;
                case 'deferred':
                    $deferred++;
                    break;
                case 'missing_payment':
                    $missingPayments++;
                    break;
            }
        }

        $summary = [
            'scanned' => $scanned,
            'attempted' => $attempted,
            'forwarded' => $forwarded,
            'deferred' => $deferred,
            'missing_payments' => $missingPayments,
            'missing_devices' => $missingDevices,
            'missing_branches' => $missingBranches,
            'failed' => $failed,
        ];
        Log::info('Charity roundup retry sweep completed', $summary);

        if ($scanned === 0) {
            $this->info('Nothing to retry.');
        } else {
            $this->info(
                "attempted={$attempted} forwarded={$forwarded} deferred-to-approval={$deferred} "
                ."missing-payment={$missingPayments} missing-device={$missingDevices} "
                ."missing-branch={$missingBranches} still-failing={$failed}",
            );
        }

        return self::SUCCESS;
    }
}
