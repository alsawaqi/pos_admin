<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Admin\Reconciliation\ForwardCharityDonationAction;
use App\Models\Branch;
use App\Models\Device;
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
 *   - status IS success (rejected, void, and pending money never leaves), AND
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
    protected $signature = 'donations:retry-roundup-forwarding {--limit=200 : Max forwarding attempts per run}';

    protected $description = 'Re-forward charity round-ups whose first forward failed (excludes pending-reconciliation ones)';

    public function __construct(private readonly ForwardCharityDonationAction $forwarder)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $donations = RoundupDonation::query()
            ->whereNull('forwarded_at')
            ->where('status', 'success')
            ->where('created_at', '<=', now()->subMinutes(10))
            ->lazyById(200);

        $scanned = 0;
        $attempted = 0;
        $forwarded = 0;
        $deferred = 0;
        $missingPayments = 0;
        $missingDevices = 0;
        $missingBranches = 0;
        $failed = 0;

        foreach ($donations as $donation) {
            if ($attempted >= $limit) {
                break;
            }

            $scanned++;
            $payment = DB::table('pos_payments')->where('id', $donation->payment_id)->first();
            if ($payment === null) {
                $missingPayments++;
                Log::warning('Charity roundup retry candidate skipped', [
                    'donation_id' => (int) $donation->id,
                    'payment_id' => (int) $donation->payment_id,
                    'reason' => 'missing_payment',
                ]);

                continue;
            }
            if ((bool) $payment->pending_reconciliation) {
                // The approval flow owns these — never jump its queue.
                $deferred++;

                continue;
            }

            $device = Device::withTrashed()->find($donation->device_id);
            if ($device === null) {
                $missingDevices++;
                Log::warning('Charity roundup retry candidate skipped', [
                    'donation_id' => (int) $donation->id,
                    'device_id' => (int) $donation->device_id,
                    'reason' => 'missing_device',
                ]);

                continue;
            }

            $branch = Branch::withTrashed()->find($donation->branch_id);
            if ($branch === null) {
                $missingBranches++;
                Log::warning('Charity roundup retry origin is incomplete', [
                    'donation_id' => (int) $donation->id,
                    'branch_id' => (int) $donation->branch_id,
                    'reason' => 'missing_branch',
                ]);
            }

            // The limit bounds real external attempts. Deferred or irreparably
            // incomplete rows above cannot permanently starve later donations.
            $attempted++;

            // Replay the durable local settlement outcome explicitly. Some
            // successful card rows have no receipt body, and the charity API
            // otherwise (correctly) treats an unproven outcome as failed.
            $ok = $this->forwarder->forward(
                $device,
                $branch,
                (string) $donation->amount,
                $donation->bank_response,
                (string) $donation->status,
                (string) $donation->uuid,
            );

            if ($ok) {
                $donation->forceFill(['forwarded_at' => now()])->save();
                $forwarded++;
            } else {
                $failed++; // next hourly run retries
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
