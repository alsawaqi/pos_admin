<?php

declare(strict_types=1);

/**
 * Mixed-tender apportionment — the fix for the confirmed payout leak: a
 * mixed card+cash order's whole-order merchant residual used to be claimed
 * and paid out in full, re-paying the merchant the cash slice they already
 * held in the drawer (money the platform never received).
 *
 * Channel-split commission rows make the claim exact:
 *   - payouts claim ONLY card-channel merchant residuals (money the
 *     platform holds), with channel-consistent statement snapshots;
 *   - cash_bank-channel rows stay unclaimed by payouts (their commission
 *     is billed via commission invoices instead);
 *   - LEGACY 'all' rows (pre-split history) keep the original behaviour
 *     exactly, so nothing about existing paid history changes.
 */

use App\Actions\Admin\Payouts\CreatePayoutAction;
use App\Actions\Admin\Payouts\PayoutBranchLinesAction;
use App\Models\Branch;
use App\Models\Company;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PlatformRoleSeeder::class);
});

function mtaOmr(int $baisas): string
{
    return number_format($baisas / 1000, 3, '.', '');
}

function mtaCtx(): array
{
    $company = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $company->id]);

    return ['company' => $company, 'branch' => $branch];
}

/**
 * A real order + tenders + channel-stamped commission rows.
 *
 * @param  list<array{party: string, channel: string, baisas: int, settled?: bool}>  $rows
 * @param  list<array{method: string, baisas: int}>  $tenders
 */
function mtaSeedSale(array $ctx, array $rows, array $tenders, string $occurredAt = '2026-06-12 10:00:00'): int
{
    $gross = array_sum(array_map(static fn ($t) => $t['baisas'], $tenders));

    $orderId = DB::table('pos_orders')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id,
        'order_type' => 'quick', 'status' => 'paid', 'source' => 'main_pos',
        'subtotal' => mtaOmr($gross), 'discount_total' => 0, 'tax_total' => 0, 'grand_total' => mtaOmr($gross),
        'opened_at' => $occurredAt, 'closed_at' => $occurredAt, 'created_at' => $occurredAt, 'updated_at' => $occurredAt,
    ]);

    foreach ($tenders as $t) {
        DB::table('pos_payments')->insert([
            'uuid' => (string) Str::uuid(), 'order_id' => $orderId, 'method' => $t['method'],
            'amount' => mtaOmr($t['baisas']), 'status' => 'success', 'pending_reconciliation' => false,
            'captured_at' => $occurredAt, 'created_at' => $occurredAt, 'updated_at' => $occurredAt,
        ]);
    }

    $sort = 0;
    foreach ($rows as $r) {
        DB::table('pos_sale_commissions')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id,
            'device_id' => 1, 'order_id' => $orderId,
            'party_type' => $r['party'], 'party_label' => ucfirst($r['party']),
            'channel' => $r['channel'], 'percent' => 0,
            'gross_amount' => mtaOmr($gross), 'commission_amount' => mtaOmr($r['baisas']),
            'settled_amount' => ($r['settled'] ?? false) ? mtaOmr($r['baisas']) : null,
            'is_settled' => $r['settled'] ?? false,
            'sort_order' => $sort++,
            'occurred_at' => $occurredAt, 'created_at' => $occurredAt, 'updated_at' => $occurredAt,
        ]);
    }

    return $orderId;
}

function mtaWindow(): array
{
    return [now()->parse('2026-06-01'), now()->parse('2026-06-30')];
}

it('pays out only the card-channel residual of a mixed order', function (): void {
    $ctx = mtaCtx();
    // 10.000 = 6.000 card + 4.000 cash; platform 2% split per channel,
    // bank 3% of card. Card residual 5.700; cash residual 3.920 (drawer money).
    $orderId = mtaSeedSale($ctx, [
        ['party' => 'platform', 'channel' => 'card', 'baisas' => 120],
        ['party' => 'platform', 'channel' => 'cash_bank', 'baisas' => 80],
        ['party' => 'bank', 'channel' => 'card', 'baisas' => 180, 'settled' => true],
        ['party' => 'merchant', 'channel' => 'card', 'baisas' => 5700],
        ['party' => 'merchant', 'channel' => 'cash_bank', 'baisas' => 3920],
    ], [
        ['method' => 'card', 'baisas' => 6000],
        ['method' => 'cash', 'baisas' => 4000],
    ]);

    [$from, $to] = mtaWindow();
    $payout = app(CreatePayoutAction::class)->handle($ctx['company']->id, $from, $to, null, $ctx['branch']->id);

    // THE fix: net = 5.700 (what the platform holds after its cuts), NOT the
    // old 9.620 whole-order residual that re-paid the merchant's own drawer.
    expect((string) $payout->net_amount)->toBe('5.700');
    // Statement reflects the card channel only: gross 6.000, not 10.000.
    expect((string) $payout->gross_amount)->toBe('6.000');
    expect((string) $payout->platform_amount)->toBe('0.120');
    expect((string) $payout->bank_amount)->toBe('0.180');

    // The cash-channel residual stays unclaimed — it is the merchant's own
    // money; only its COMMISSION will be invoiced.
    $cashResidual = DB::table('pos_sale_commissions')
        ->where('order_id', $orderId)->where('party_type', 'merchant')->where('channel', 'cash_bank')->first();
    expect($cashResidual->payout_id)->toBeNull();

    $cardResidual = DB::table('pos_sale_commissions')
        ->where('order_id', $orderId)->where('party_type', 'merchant')->where('channel', 'card')->first();
    expect((int) $cardResidual->payout_id)->toBe($payout->id);

    // Branch statement mirrors the channel filter.
    $lines = app(PayoutBranchLinesAction::class)->handle($payout);
    expect($lines)->toHaveCount(1);
    expect($lines[0]['gross'])->toBe('6.000');
    expect($lines[0]['merchant_net'])->toBe('5.700');
});

it('keeps legacy whole-order rows on the exact old behaviour', function (): void {
    $ctx = mtaCtx();
    // A pre-split mixed order: single 'all' merchant residual. Grandfathered:
    // claimed whole, exactly as before the channel column existed.
    mtaSeedSale($ctx, [
        ['party' => 'platform', 'channel' => 'all', 'baisas' => 200],
        ['party' => 'bank', 'channel' => 'all', 'baisas' => 180, 'settled' => true],
        ['party' => 'merchant', 'channel' => 'all', 'baisas' => 9620],
    ], [
        ['method' => 'card', 'baisas' => 6000],
        ['method' => 'cash', 'baisas' => 4000],
    ]);

    [$from, $to] = mtaWindow();
    $payout = app(CreatePayoutAction::class)->handle($ctx['company']->id, $from, $to, null, $ctx['branch']->id);

    expect((string) $payout->net_amount)->toBe('9.620');
    expect((string) $payout->gross_amount)->toBe('10.000');
});

it('never claims cash-channel rows: a pure-cash split order has nothing to pay out', function (): void {
    $ctx = mtaCtx();
    mtaSeedSale($ctx, [
        ['party' => 'platform', 'channel' => 'cash_bank', 'baisas' => 200],
        ['party' => 'merchant', 'channel' => 'cash_bank', 'baisas' => 9800],
    ], [
        ['method' => 'cash', 'baisas' => 10000],
    ]);

    [$from, $to] = mtaWindow();
    app(CreatePayoutAction::class)->handle($ctx['company']->id, $from, $to, null, $ctx['branch']->id);
})->throws(RuntimeException::class, 'No unsettled earnings');

it('invoices the cash-slice commission of a mixed order', function (): void {
    $ctx = mtaCtx();
    // Same mixed 6.000 card + 4.000 cash order; cash-channel platform 0.080
    // is VERIFIED (is_settled) — the invoice must claim exactly it.
    $orderId = mtaSeedSale($ctx, [
        ['party' => 'platform', 'channel' => 'card', 'baisas' => 120, 'settled' => true],
        ['party' => 'platform', 'channel' => 'cash_bank', 'baisas' => 80, 'settled' => true],
        ['party' => 'bank', 'channel' => 'card', 'baisas' => 180, 'settled' => true],
        ['party' => 'merchant', 'channel' => 'card', 'baisas' => 5700, 'settled' => true],
        ['party' => 'merchant', 'channel' => 'cash_bank', 'baisas' => 3920, 'settled' => true],
    ], [
        ['method' => 'card', 'baisas' => 6000],
        ['method' => 'cash', 'baisas' => 4000],
    ]);

    [$from, $to] = mtaWindow();
    $invoice = app(App\Actions\Admin\Invoices\CreateCommissionInvoiceAction::class)
        ->handle($ctx['company']->id, $from, $to, null, $ctx['branch']->id);

    // The bill is the CASH channel only: owed = 0.080, gross = the 4.000 the
    // merchant holds in the drawer, merchant keeps 3.920.
    expect((string) $invoice->total_owed)->toBe('0.080');
    expect((string) $invoice->gross_amount)->toBe('4.000');
    expect((string) $invoice->cash_gross)->toBe('4.000');
    expect((string) $invoice->merchant_amount)->toBe('3.920');

    // Claimed: the cash-channel platform row ONLY — the card-channel platform
    // row belongs to the payout statement.
    $claimed = DB::table('pos_sale_commissions')
        ->where('order_id', $orderId)->whereNotNull('invoice_id')->get();
    expect($claimed)->toHaveCount(1);
    expect($claimed[0]->channel)->toBe('cash_bank');
    expect($claimed[0]->party_type)->toBe('platform');
});

it('collects the platform cut exactly once across payout + invoice of the same mixed order', function (): void {
    $ctx = mtaCtx();
    // The end-to-end invariant of the whole apportionment: for one mixed
    // order, payout withholding (card channel) + invoice billing (cash
    // channel) together equal the order's total platform commission — no
    // double-collection, no gap, and the merchant's total position is
    // exactly collected − commissions.
    mtaSeedSale($ctx, [
        ['party' => 'platform', 'channel' => 'card', 'baisas' => 120, 'settled' => true],
        ['party' => 'platform', 'channel' => 'cash_bank', 'baisas' => 80, 'settled' => true],
        ['party' => 'bank', 'channel' => 'card', 'baisas' => 180, 'settled' => true],
        ['party' => 'merchant', 'channel' => 'card', 'baisas' => 5700, 'settled' => true],
        ['party' => 'merchant', 'channel' => 'cash_bank', 'baisas' => 3920, 'settled' => true],
    ], [
        ['method' => 'card', 'baisas' => 6000],
        ['method' => 'cash', 'baisas' => 4000],
    ]);

    [$from, $to] = mtaWindow();
    $payout = app(CreatePayoutAction::class)->handle($ctx['company']->id, $from, $to, null, $ctx['branch']->id);
    $invoice = app(App\Actions\Admin\Invoices\CreateCommissionInvoiceAction::class)
        ->handle($ctx['company']->id, $from, $to, null, $ctx['branch']->id);

    // Platform take: withheld 0.120 inside the payout + billed 0.080 on the
    // invoice = 0.200 = the order's full platform commission.
    $withheld = (float) $payout->platform_amount;
    $billed = (float) $invoice->total_owed;
    expect(number_format($withheld + $billed, 3, '.', ''))->toBe('0.200');

    // Merchant position: drawer 4.000 + payout 5.700 − invoice 0.080 = 9.620
    // = collected 10.000 − platform 0.200 − bank 0.180. Exact.
    $merchantPosition = 4.000 + (float) $payout->net_amount - $billed;
    expect(number_format($merchantPosition, 3, '.', ''))->toBe('9.620');
});

it('settles a mixed order per channel: variances land on the channel that was edited', function (): void {
    $ctx = mtaCtx();
    $orderId = mtaSeedSale($ctx, [
        ['party' => 'platform', 'channel' => 'card', 'baisas' => 120],
        ['party' => 'platform', 'channel' => 'cash_bank', 'baisas' => 80],
        ['party' => 'bank', 'channel' => 'card', 'baisas' => 180],
        ['party' => 'merchant', 'channel' => 'card', 'baisas' => 5700],
        ['party' => 'merchant', 'channel' => 'cash_bank', 'baisas' => 3920],
    ], [
        ['method' => 'card', 'baisas' => 6000],
        ['method' => 'cash', 'baisas' => 4000],
    ]);

    // Verify with bank ACTUAL 0.200 (est 0.180) and a whole-order platform
    // override 0.190 (est 0.200): the override spreads across both platform
    // rows by estimate weight (120:80 → 114:76), and each channel's merchant
    // absorbs ONLY its own variance.
    $settlement = app(App\Actions\Admin\Reconciliation\SettleCommissionAction::class)->settleOrders(
        $ctx['company']->id, [$orderId => 200], [$orderId => 190], null, 'manual', null, null,
    );

    // number_format: raw sqlite reads drop trailing zeros ('0.200' → '0.2').
    $settled = fn (string $party, string $channel) => number_format((float) DB::table('pos_sale_commissions')
        ->where('order_id', $orderId)->where('party_type', $party)->where('channel', $channel)
        ->value('settled_amount'), 3, '.', '');

    expect($settled('bank', 'card'))->toBe('0.200');
    expect($settled('platform', 'card'))->toBe('0.114');
    expect($settled('platform', 'cash_bank'))->toBe('0.076');
    // card merchant: 5.700 + (0.180−0.200) + (0.120−0.114) = 5.686
    expect($settled('merchant', 'card'))->toBe('5.686');
    // cash merchant: 3.920 + (0.080−0.076) = 3.924 — bank variance NEVER
    // bleeds onto the cash channel.
    expect($settled('merchant', 'cash_bank'))->toBe('3.924');

    // Per-channel invariant after settlement: card 6.000, cash 4.000.
    $sumChannel = fn (string $ch) => number_format((float) DB::table('pos_sale_commissions')
        ->where('order_id', $orderId)->where('channel', $ch)->sum('settled_amount'), 3, '.', '');
    expect($sumChannel('card'))->toBe('6.000');
    expect($sumChannel('cash_bank'))->toBe('4.000');

    // Header merchant_net = the card-channel transfer only (not drawer money).
    expect((string) $settlement->merchant_net)->toBe('5.686');
    expect((string) $settlement->platform_total)->toBe('0.190');
});

it('caps the per-order bank fee at the card amount of a mixed order', function (): void {
    $ctx = mtaCtx();
    $orderId = mtaSeedSale($ctx, [
        ['party' => 'bank', 'channel' => 'card', 'baisas' => 180],
        ['party' => 'merchant', 'channel' => 'card', 'baisas' => 5820],
        ['party' => 'merchant', 'channel' => 'cash_bank', 'baisas' => 4000],
    ], [
        ['method' => 'card', 'baisas' => 6000],
        ['method' => 'cash', 'baisas' => 4000],
    ]);

    // 6.500 fee on a 6.000 card leg: without the cap this would silently eat
    // the cash slice out of the merchant residual.
    app(App\Actions\Admin\Reconciliation\SettleCommissionAction::class)->settleOrders(
        $ctx['company']->id, [$orderId => 6500], [], null, 'manual', null, null,
    );
})->throws(RuntimeException::class, 'cannot exceed');

it('never bills the surviving cash rows of a voided order (void-after-payout)', function (): void {
    $ctx = mtaCtx();
    $orderId = mtaSeedSale($ctx, [
        ['party' => 'platform', 'channel' => 'card', 'baisas' => 120, 'settled' => true],
        ['party' => 'platform', 'channel' => 'cash_bank', 'baisas' => 80, 'settled' => true],
        ['party' => 'bank', 'channel' => 'card', 'baisas' => 180, 'settled' => true],
        ['party' => 'merchant', 'channel' => 'card', 'baisas' => 5700, 'settled' => true],
        ['party' => 'merchant', 'channel' => 'cash_bank', 'baisas' => 3920, 'settled' => true],
    ], [
        ['method' => 'card', 'baisas' => 6000],
        ['method' => 'cash', 'baisas' => 4000],
    ]);

    [$from, $to] = mtaWindow();
    app(CreatePayoutAction::class)->handle($ctx['company']->id, $from, $to, null, $ctx['branch']->id);

    // The sale is voided AFTER the payout claimed its card residual. The
    // void-time order-level guard keeps all rows alive (the payout statement
    // must stay backed) — but the surviving cash-channel platform row must
    // never be billed: the sale was refunded.
    DB::table('pos_orders')->where('id', $orderId)->update(['status' => 'void']);

    app(App\Actions\Admin\Invoices\CreateCommissionInvoiceAction::class)
        ->handle($ctx['company']->id, $from, $to, null, $ctx['branch']->id);
})->throws(RuntimeException::class, 'No verified un-invoiced');

it('never pays out the surviving card residual of a voided order (void-after-invoice)', function (): void {
    $ctx = mtaCtx();
    $orderId = mtaSeedSale($ctx, [
        ['party' => 'platform', 'channel' => 'card', 'baisas' => 120, 'settled' => true],
        ['party' => 'platform', 'channel' => 'cash_bank', 'baisas' => 80, 'settled' => true],
        ['party' => 'bank', 'channel' => 'card', 'baisas' => 180, 'settled' => true],
        ['party' => 'merchant', 'channel' => 'card', 'baisas' => 5700, 'settled' => true],
        ['party' => 'merchant', 'channel' => 'cash_bank', 'baisas' => 3920, 'settled' => true],
    ], [
        ['method' => 'card', 'baisas' => 6000],
        ['method' => 'cash', 'baisas' => 4000],
    ]);

    [$from, $to] = mtaWindow();
    app(App\Actions\Admin\Invoices\CreateCommissionInvoiceAction::class)
        ->handle($ctx['company']->id, $from, $to, null, $ctx['branch']->id);

    // Voided AFTER the invoice billed the cash commission: the surviving
    // card residual must never be transferred — the acquirer refunded it.
    DB::table('pos_orders')->where('id', $orderId)->update(['status' => 'void']);

    app(CreatePayoutAction::class)->handle($ctx['company']->id, $from, $to, null, $ctx['branch']->id);
})->throws(RuntimeException::class, 'No unsettled earnings');

it('rejects a platform edit routed to a channel with no merchant absorber', function (): void {
    $ctx = mtaCtx();
    // Pure-CASH sale under a CARD-scoped platform line: the card channel has
    // a 0-amount platform row but NO merchant residual. An override landing
    // there would make Σ(settled) exceed collected with nobody absorbing it.
    $orderId = mtaSeedSale($ctx, [
        ['party' => 'platform', 'channel' => 'card', 'baisas' => 0],
        ['party' => 'merchant', 'channel' => 'cash_bank', 'baisas' => 10000],
    ], [
        ['method' => 'cash', 'baisas' => 10000],
    ]);

    app(App\Actions\Admin\Reconciliation\SettleCommissionAction::class)->settleOrders(
        $ctx['company']->id, [$orderId => 0], [$orderId => 250], null, 'manual', null, null,
    );
})->throws(RuntimeException::class, 'no money in the channel');

it('still blocks the payout while the mixed order card leg is unreconciled', function (): void {
    $ctx = mtaCtx();
    mtaSeedSale($ctx, [
        ['party' => 'bank', 'channel' => 'card', 'baisas' => 180], // NOT settled
        ['party' => 'merchant', 'channel' => 'card', 'baisas' => 5820],
        ['party' => 'merchant', 'channel' => 'cash_bank', 'baisas' => 4000],
    ], [
        ['method' => 'card', 'baisas' => 6000],
        ['method' => 'cash', 'baisas' => 4000],
    ]);

    [$from, $to] = mtaWindow();
    app(CreatePayoutAction::class)->handle($ctx['company']->id, $from, $to, null, $ctx['branch']->id);
})->throws(RuntimeException::class, 'Reconcile all card sales');
