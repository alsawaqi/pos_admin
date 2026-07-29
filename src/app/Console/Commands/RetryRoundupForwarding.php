<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Admin\Reconciliation\ForwardCharityDonationAction;
use App\Models\Branch;
use App\Models\Device;
use App\Models\RoundupDonation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Master-plan step 10 — the hourly retry sweep for charity round-ups whose
 * forward to the charity app failed (outage, network blip). Without it, a
 * customer's donation could sit unforwarded forever: the inline forward at
 * donation.record is deliberately best-effort (a till must never block on
 * a third system) and nothing retried ordinary failures.
 *
 * Eligibility guards:
 *   - forwarded_at IS NULL (never forwarded), AND
 *   - the linked payment is NOT pending_reconciliation — those defer to
 *     the admin approval flow (ReconcileDeferredEffectsAction), which owns
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
    protected $signature = 'donations:retry-roundup-forwarding {--limit=200 : Max donations per run}';

    protected $description = 'Re-forward charity round-ups whose first forward failed (excludes pending-reconciliation ones)';

    public function __construct(private readonly ForwardCharityDonationAction $forwarder)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $donations = RoundupDonation::query()
            ->whereNull('forwarded_at')
            ->where('created_at', '<=', now()->subMinutes(10))
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($donations->isEmpty()) {
            $this->info('Nothing to retry.');

            return self::SUCCESS;
        }

        $forwarded = 0;
        $deferred = 0;
        $failed = 0;

        foreach ($donations as $donation) {
            $payment = DB::table('pos_payments')->where('id', $donation->payment_id)->first();
            if ($payment === null || (bool) $payment->pending_reconciliation) {
                // The approval flow owns these — never jump its queue.
                $deferred++;

                continue;
            }

            $device = Device::query()->find($donation->device_id);
            $branch = Branch::query()->find($donation->branch_id);
            if ($device === null) {
                $failed++;

                continue;
            }

            // Same shape as the inline attempt (status from the recorded
            // receipt) — this is a RETRY of that call, not the approval
            // path's confirmed-'success' override.
            $ok = $this->forwarder->forward(
                $device,
                $branch,
                (string) $donation->amount,
                $donation->bank_response,
                null,
                (string) $donation->uuid,
            );

            if ($ok) {
                $donation->forceFill(['forwarded_at' => now()])->save();
                $forwarded++;
            } else {
                $failed++; // next hourly run retries
            }
        }

        $this->info("forwarded={$forwarded} deferred-to-approval={$deferred} still-failing={$failed}");

        return self::SUCCESS;
    }
}
