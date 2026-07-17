<?php

declare(strict_types=1);

use App\Actions\Admin\Reconciliation\SettleCommissionAction;
use App\Enums\PlatformRole;
use App\Models\Branch;
use App\Models\CommissionSettlement;
use App\Models\Company;
use App\Models\Device;
use App\Models\SaleCommission;
use App\Models\User;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Commission settlement — reconcile a merchant's card sales against the bank's
 * ACTUAL fee (estimate → settled). Pass-through: the merchant bears the
 * variance, the platform cut is fixed, and Σ(settled) always == collected.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PlatformRoleSeeder::class);
});

function settleActingAs(\Tests\TestCase $test, ?string $role): User
{
    /** @var User $user */
    $user = User::factory()->create();
    if ($role !== null) {
        app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
        $user->assignRole($role);
    }
    $test->actingAs($user);

    return $user;
}

/** company + branch + an assigned device. */
function settleSeedGraph(): array
{
    $company = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    $device = Device::factory()->assigned()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'name' => 'POS-SETTLE-1',
    ]);

    return ['company' => $company, 'branch' => $branch, 'device' => $device];
}

function settleOmr(int $baisas): string
{
    return number_format($baisas / 1000, 3, '.', '');
}

/**
 * A paid sale + its commission breakdown rows (the ESTIMATE), using a fixed
 * platform 2% / bank 3% profile (merchant residual). A card sale carries a
 * bank cut; a cash sale's bank row is 0. Returns the order id.
 */
function settleSeedSale(array $ctx, int $grossBaisas, bool $card, ?CarbonImmutable $occurredAt = null, ?int $branchId = null): int
{
    $occurredAt ??= CarbonImmutable::now();
    $branchId ??= $ctx['branch']->id;
    $cardBaisas = $card ? $grossBaisas : 0;
    $platform = (int) round($grossBaisas * 2 / 100);
    $bank = (int) round($cardBaisas * 3 / 100);
    $merchant = $grossBaisas - $platform - $bank;

    $orderId = DB::table('pos_orders')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $ctx['company']->id, 'branch_id' => $branchId,
        'order_type' => 'quick', 'status' => 'paid', 'source' => 'main_pos',
        'subtotal' => settleOmr($grossBaisas), 'discount_total' => 0, 'tax_total' => 0,
        'grand_total' => settleOmr($grossBaisas),
        'opened_at' => $occurredAt, 'closed_at' => $occurredAt, 'created_at' => $occurredAt, 'updated_at' => $occurredAt,
    ]);

    DB::table('pos_payments')->insert([
        'uuid' => (string) Str::uuid(), 'order_id' => $orderId, 'method' => $card ? 'card' : 'cash',
        'amount' => settleOmr($grossBaisas), 'status' => 'success', 'pending_reconciliation' => false,
        'device_id' => $ctx['device']->id, 'terminal_id' => $ctx['device']->terminal_id,
        'captured_at' => $occurredAt, 'created_at' => $occurredAt, 'updated_at' => $occurredAt,
    ]);

    foreach ([['platform', 'Platform', 2, $platform, 0], ['bank', 'Acme Bank', 3, $bank, 1], ['merchant', 'Merchant', 95, $merchant, 2]] as [$type, $label, $percent, $amount, $sort]) {
        DB::table('pos_sale_commissions')->insert([
            'uuid' => (string) Str::uuid(),
            'company_id' => $ctx['company']->id, 'branch_id' => $branchId, 'device_id' => $ctx['device']->id,
            'order_id' => $orderId, 'party_type' => $type, 'party_label' => $label, 'percent' => $percent,
            'gross_amount' => settleOmr($grossBaisas), 'commission_amount' => settleOmr($amount),
            'sort_order' => $sort, 'occurred_at' => $occurredAt, 'created_at' => $occurredAt, 'updated_at' => $occurredAt,
        ]);
    }

    return $orderId;
}

function settleRow(int $orderId, string $party): SaleCommission
{
    // Read through Eloquent so the decimal:3 cast pads (raw SQLite NUMERIC
    // affinity drops trailing zeros: '9.500' → '9.5').
    return SaleCommission::query()->where('order_id', $orderId)->where('party_type', $party)->firstOrFail();
}

function settleWindow(): array
{
    return [CarbonImmutable::now()->startOfDay(), CarbonImmutable::now()->endOfDay()];
}

function settleOrderUuid(int $orderId): string
{
    return (string) DB::table('pos_orders')->where('id', $orderId)->value('uuid');
}

// ─── Math: pass-through, Σ == collected ────────────────────────────────────

it('moves the bank variance onto the merchant when the actual fee is HIGHER', function (): void {
    $ctx = settleSeedGraph();
    $orderId = settleSeedSale($ctx, 10000, card: true); // platform 200, bank est 300, merchant 9500
    [$from, $to] = settleWindow();

    $settlement = app(SettleCommissionAction::class)->settle(
        $ctx['company']->id, $from, $to, null, 500, 'manual', null, null, null, null,
    );

    expect((string) settleRow($orderId, 'bank')->settled_amount)->toBe('0.500')
        ->and((string) settleRow($orderId, 'merchant')->settled_amount)->toBe('9.300') // 9500 + (300 - 500)
        ->and((string) settleRow($orderId, 'platform')->settled_amount)->toBe('0.200') // unchanged
        ->and((string) $settlement->estimated_bank)->toBe('0.300')
        ->and((string) $settlement->actual_bank)->toBe('0.500')
        ->and((string) $settlement->variance)->toBe('0.200')
        ->and((string) $settlement->merchant_net)->toBe('9.300')
        ->and((string) $settlement->card_gross)->toBe('10.000');

    // Σ(settled) across the order == collected (10.000), exactly as the estimate did.
    $sumSettled = DB::table('pos_sale_commissions')->where('order_id', $orderId)->sum('settled_amount');
    expect(number_format((float) $sumSettled, 3, '.', ''))->toBe('10.000');
});

it('lets the operator edit the platform commission per sale; the merchant is the residual (A3)', function (): void {
    $ctx = settleSeedGraph();
    $orderId = settleSeedSale($ctx, 10000, card: true); // platform 200, bank est 300, merchant 9500

    // Confirm the bank fee at the estimate (300) but RAISE the platform
    // commission 200 → 400. The merchant absorbs the +200 platform variance.
    $settlement = app(SettleCommissionAction::class)->settleOrders(
        $ctx['company']->id,
        [$orderId => 300],   // actual bank (baisas) = estimate
        [$orderId => 400],   // actual platform (baisas) = raised
        null,
        'manual',
        null,
        null,
    );

    expect((string) settleRow($orderId, 'bank')->settled_amount)->toBe('0.300')
        ->and((string) settleRow($orderId, 'platform')->settled_amount)->toBe('0.400')  // edited
        ->and((string) settleRow($orderId, 'merchant')->settled_amount)->toBe('9.300')  // 9500 + 0 + (200 - 400)
        ->and((string) $settlement->platform_total)->toBe('0.400')
        ->and((string) $settlement->merchant_net)->toBe('9.300');

    // Invariant holds: Σ(settled) == collected (10.000).
    $sumSettled = DB::table('pos_sale_commissions')->where('order_id', $orderId)->sum('settled_amount');
    expect(number_format((float) $sumSettled, 3, '.', ''))->toBe('10.000');
});

it('rejects a bank fee + platform commission that drive the merchant net negative (A3)', function (): void {
    $ctx = settleSeedGraph();
    $orderId = settleSeedSale($ctx, 10000, card: true); // merchant est 9500

    // bank 300 + platform 9800 → merchant = 9500 + 0 + (200 - 9800) = -100 → reject.
    expect(fn () => app(SettleCommissionAction::class)->settleOrders(
        $ctx['company']->id, [$orderId => 300], [$orderId => 9800], null, 'manual', null, null,
    ))->toThrow(RuntimeException::class);
});

it('rejects a positive platform commission on a sale that has no platform line, without crashing (A3 guard)', function (): void {
    $ctx = settleSeedGraph();
    $orderId = settleSeedSale($ctx, 10000, card: true);
    // A bank-only merchant profile produces card sales with NO platform row;
    // the merchant absorbs what would have been the platform share (bank 300 +
    // merchant 9700 = 10000).
    DB::table('pos_sale_commissions')->where('order_id', $orderId)->where('party_type', 'platform')->delete();
    DB::table('pos_sale_commissions')->where('order_id', $orderId)->where('party_type', 'merchant')->update(['commission_amount' => settleOmr(9700)]);

    // A positive platform override has nowhere to land — it must fail closed with
    // a RuntimeException (→ 422), never a DivisionByZeroError (→ uncaught 500).
    expect(fn () => app(SettleCommissionAction::class)->settleOrders(
        $ctx['company']->id, [$orderId => 300], [$orderId => 400], null, 'manual', null, null,
    ))->toThrow(RuntimeException::class);

    // A 0 override (the default) still settles cleanly; the guard only blocks a
    // positive edit, and Σ(settled) == collected is preserved.
    app(SettleCommissionAction::class)->settleOrders(
        $ctx['company']->id, [$orderId => 300], [$orderId => 0], null, 'manual', null, null,
    );
    expect((string) settleRow($orderId, 'bank')->settled_amount)->toBe('0.300')
        ->and((string) settleRow($orderId, 'merchant')->settled_amount)->toBe('9.700');
    $sumSettled = DB::table('pos_sale_commissions')->where('order_id', $orderId)->sum('settled_amount');
    expect(number_format((float) $sumSettled, 3, '.', ''))->toBe('10.000');
});

it('verifies a CASH sale at bank fee 0 with an editable platform commission (Step 3)', function (): void {
    $ctx = settleSeedGraph();
    $orderId = settleSeedSale($ctx, 10000, card: false); // cash: platform 200, bank 0, merchant 9800

    // Verify at fee 0, trimming the platform commission 200 → 150; the merchant
    // absorbs the variance (+50).
    app(SettleCommissionAction::class)->settleOrders(
        $ctx['company']->id, [$orderId => 0], [$orderId => 150], null, 'manual', null, null,
    );

    expect((string) settleRow($orderId, 'bank')->settled_amount)->toBe('0.000')
        ->and((string) settleRow($orderId, 'platform')->settled_amount)->toBe('0.150')
        ->and((string) settleRow($orderId, 'merchant')->settled_amount)->toBe('9.850')
        ->and(settleRow($orderId, 'merchant')->is_settled)->toBeTrue();

    // Invariant holds: Σ(settled) == collected (10.000).
    $sumSettled = DB::table('pos_sale_commissions')->where('order_id', $orderId)->sum('settled_amount');
    expect(number_format((float) $sumSettled, 3, '.', ''))->toBe('10.000');
});

it('refuses a positive bank fee when verifying a cash sale', function (): void {
    $ctx = settleSeedGraph();
    $orderId = settleSeedSale($ctx, 10000, card: false);
    // The seeded cash sale has a zero-value bank row; delete it to model a
    // profile with no bank line at all (the strictest case).
    DB::table('pos_sale_commissions')->where('order_id', $orderId)->where('party_type', 'bank')->delete();
    DB::table('pos_sale_commissions')->where('order_id', $orderId)->where('party_type', 'merchant')->update(['commission_amount' => settleOmr(9800)]);

    expect(fn () => app(SettleCommissionAction::class)->settleOrders(
        $ctx['company']->id, [$orderId => 300], [$orderId => 200], null, 'manual', null, null,
    ))->toThrow(RuntimeException::class, 'This sale has no card money — the bank fee must be 0.');
});

it('refuses a positive bank fee on a cash sale even when a zero-value bank row exists', function (): void {
    $ctx = settleSeedGraph();
    // settleSeedSale(card: false) seeds the common shape: a profile WITH a bank
    // line → the cash sale carries a ZERO-value bank row. Row presence must not
    // let a fee through — there is no card money to have an acquirer fee on.
    $orderId = settleSeedSale($ctx, 10000, card: false);

    expect(fn () => app(SettleCommissionAction::class)->settleOrders(
        $ctx['company']->id, [$orderId => 300], [$orderId => 200], null, 'manual', null, null,
    ))->toThrow(RuntimeException::class, 'This sale has no card money — the bank fee must be 0.');
});

it('refuses to reverse a settlement whose sales are already billed on an invoice', function (): void {
    $ctx = settleSeedGraph();
    $orderId = settleSeedSale($ctx, 10000, card: false);

    // Verify the cash sale (creates the settlement), then bill it.
    $settlement = app(SettleCommissionAction::class)->settleOrders(
        $ctx['company']->id, [$orderId => 0], [$orderId => 200], null, 'manual', null, null,
    );
    DB::table('pos_sale_commissions')->where('order_id', $orderId)->where('party_type', 'platform')->update(['invoice_id' => 42]);

    // Reversing now would un-verify figures an ISSUED invoice bills — refuse.
    expect(fn () => app(SettleCommissionAction::class)->reverse($settlement, null))
        ->toThrow(RuntimeException::class, 'void the invoice before reversing');

    // The verified figures survive intact.
    expect(settleRow($orderId, 'platform')->is_settled)->toBeTrue()
        ->and((string) settleRow($orderId, 'platform')->settled_amount)->toBe('0.200');
});

it('refuses to re-verify an order already claimed into an invoice', function (): void {
    $ctx = settleSeedGraph();
    $orderId = settleSeedSale($ctx, 10000, card: false);
    // Claimed into a commission invoice — its figures are a frozen bill.
    DB::table('pos_sale_commissions')->where('order_id', $orderId)->where('party_type', 'platform')->update(['invoice_id' => 77]);

    expect(fn () => app(SettleCommissionAction::class)->settleOrders(
        $ctx['company']->id, [$orderId => 0], [$orderId => 200], null, 'manual', null, null,
    ))->toThrow(RuntimeException::class);
});

it('gives the merchant MORE when the actual fee is LOWER', function (): void {
    $ctx = settleSeedGraph();
    $orderId = settleSeedSale($ctx, 10000, card: true);
    [$from, $to] = settleWindow();

    $settlement = app(SettleCommissionAction::class)->settle(
        $ctx['company']->id, $from, $to, null, 100, 'manual', null, null, null, null,
    );

    expect((string) settleRow($orderId, 'bank')->settled_amount)->toBe('0.100')
        ->and((string) settleRow($orderId, 'merchant')->settled_amount)->toBe('9.700') // 9500 + (300 - 100)
        ->and((string) $settlement->variance)->toBe('-0.200');
});

it('leaves the merchant net at the estimate when actual == estimated', function (): void {
    $ctx = settleSeedGraph();
    $orderId = settleSeedSale($ctx, 10000, card: true);
    [$from, $to] = settleWindow();

    app(SettleCommissionAction::class)->settle($ctx['company']->id, $from, $to, null, 300, 'manual', null, null, null, null);

    expect((string) settleRow($orderId, 'merchant')->settled_amount)->toBe('9.500')
        ->and((string) settleRow($orderId, 'bank')->settled_amount)->toBe('0.300');
});

// ─── Allocation across orders ──────────────────────────────────────────────

it('allocates the actual fee across orders proportional to card volume', function (): void {
    $ctx = settleSeedGraph();
    $a = settleSeedSale($ctx, 10000, card: true); // card 10.000
    $b = settleSeedSale($ctx, 30000, card: true); // card 30.000
    [$from, $to] = settleWindow();

    $settlement = app(SettleCommissionAction::class)->settle($ctx['company']->id, $from, $to, null, 800, 'manual', null, null, null, null);

    // 0.800 split 1:3 by card volume → A 0.200, B 0.600.
    expect((string) settleRow($a, 'bank')->settled_amount)->toBe('0.200')
        ->and((string) settleRow($b, 'bank')->settled_amount)->toBe('0.600')
        ->and((string) $settlement->orders_count)->toBe('2')
        ->and((string) $settlement->card_gross)->toBe('40.000')
        ->and((string) $settlement->merchant_net)->toBe('38.400'); // 9.600 + 28.800
});

it('allocates to the baisa with a remainder (Σ == entered total)', function (): void {
    $ctx = settleSeedGraph();
    $a = settleSeedSale($ctx, 10000, card: true);
    $b = settleSeedSale($ctx, 10000, card: true);
    [$from, $to] = settleWindow();

    app(SettleCommissionAction::class)->settle($ctx['company']->id, $from, $to, null, 1, 'manual', null, null, null, null);

    $sumBank = DB::table('pos_sale_commissions')->whereIn('order_id', [$a, $b])->where('party_type', 'bank')->sum('settled_amount');
    expect(number_format((float) $sumBank, 3, '.', ''))->toBe('0.001');
});

// ─── Targeting ─────────────────────────────────────────────────────────────

it('targets only card sales, never cash sales', function (): void {
    $ctx = settleSeedGraph();
    $card = settleSeedSale($ctx, 10000, card: true);
    $cash = settleSeedSale($ctx, 5000, card: false); // bank row = 0 → not targeted
    [$from, $to] = settleWindow();

    $settlement = app(SettleCommissionAction::class)->settle($ctx['company']->id, $from, $to, null, 200, 'manual', null, null, null, null);

    expect((string) $settlement->orders_count)->toBe('1')
        ->and((bool) settleRow($card, 'bank')->is_settled)->toBeTrue()
        ->and((bool) settleRow($cash, 'bank')->is_settled)->toBeFalse()
        ->and(settleRow($cash, 'merchant')->settled_amount)->toBeNull();
});

it('scopes the settlement to a single branch when given', function (): void {
    $ctx = settleSeedGraph();
    $other = Branch::factory()->create(['company_id' => $ctx['company']->id]);
    $inBranch = settleSeedSale($ctx, 10000, card: true);
    $otherBranch = settleSeedSale($ctx, 10000, card: true, branchId: $other->id);
    [$from, $to] = settleWindow();

    $settlement = app(SettleCommissionAction::class)->settle($ctx['company']->id, $from, $to, $ctx['branch']->id, 200, 'manual', null, null, null, null);

    expect((string) $settlement->orders_count)->toBe('1')
        ->and((bool) settleRow($inBranch, 'bank')->is_settled)->toBeTrue()
        ->and((bool) settleRow($otherBranch, 'bank')->is_settled)->toBeFalse();
});

it('throws when there are no unsettled card sales', function (): void {
    $ctx = settleSeedGraph();
    settleSeedSale($ctx, 5000, card: false); // cash only
    [$from, $to] = settleWindow();

    app(SettleCommissionAction::class)->settle($ctx['company']->id, $from, $to, null, 100, 'manual', null, null, null, null);
})->throws(RuntimeException::class, 'No unsettled card sales');

it('refuses an actual fee greater than the card sales total', function (): void {
    $ctx = settleSeedGraph();
    settleSeedSale($ctx, 10000, card: true);
    [$from, $to] = settleWindow();

    app(SettleCommissionAction::class)->settle($ctx['company']->id, $from, $to, null, 10001, 'manual', null, null, null, null);
})->throws(RuntimeException::class, 'cannot exceed the card sales total');

it('refuses an actual fee that would drive the merchant net negative', function (): void {
    // gross/card 10.000, merchant est 9.500. actual == card total passes the
    // global `> card total` guard but would make merchant settled negative.
    $ctx = settleSeedGraph();
    settleSeedSale($ctx, 10000, card: true);
    [$from, $to] = settleWindow();

    app(SettleCommissionAction::class)->settle($ctx['company']->id, $from, $to, null, 10000, 'manual', null, null, null, null);
})->throws(RuntimeException::class, 'merchant net negative');

it('does not re-settle an already-settled order', function (): void {
    $ctx = settleSeedGraph();
    settleSeedSale($ctx, 10000, card: true);
    [$from, $to] = settleWindow();
    $action = app(SettleCommissionAction::class);

    $action->settle($ctx['company']->id, $from, $to, null, 300, 'manual', null, null, null, null);

    expect(fn () => $action->settle($ctx['company']->id, $from, $to, null, 300, 'manual', null, null, null, null))
        ->toThrow(RuntimeException::class, 'No unsettled card sales');
});

it('skips orders already claimed into a payout', function (): void {
    $ctx = settleSeedGraph();
    $orderId = settleSeedSale($ctx, 10000, card: true);
    // A payout claims only the MERCHANT row (as CreatePayoutAction does) — the
    // order must still be excluded from settlement.
    DB::table('pos_sale_commissions')->where('order_id', $orderId)->where('party_type', 'merchant')->update(['payout_id' => 999]);
    [$from, $to] = settleWindow();

    expect(fn () => app(SettleCommissionAction::class)->settle($ctx['company']->id, $from, $to, null, 300, 'manual', null, null, null, null))
        ->toThrow(RuntimeException::class, 'No unsettled card sales');
});

// ─── Reverse ───────────────────────────────────────────────────────────────

it('reverses a settlement back to the estimate', function (): void {
    $ctx = settleSeedGraph();
    $orderId = settleSeedSale($ctx, 10000, card: true);
    [$from, $to] = settleWindow();
    $action = app(SettleCommissionAction::class);

    $settlement = $action->settle($ctx['company']->id, $from, $to, null, 500, 'manual', null, null, null, null);
    $reversed = $action->reverse($settlement, null);

    expect($reversed->status)->toBe(CommissionSettlement::STATUS_REVERSED)
        ->and($reversed->reversed_at)->not->toBeNull()
        ->and((bool) settleRow($orderId, 'bank')->is_settled)->toBeFalse()
        ->and(settleRow($orderId, 'bank')->settled_amount)->toBeNull()
        ->and(settleRow($orderId, 'bank')->settlement_id)->toBeNull();

    // The order is targetable again after reversal.
    $again = $action->settle($ctx['company']->id, $from, $to, null, 300, 'manual', null, null, null, null);
    expect((string) $again->orders_count)->toBe('1');
});

it('refuses to reverse a settlement whose sales are already paid out', function (): void {
    $ctx = settleSeedGraph();
    $orderId = settleSeedSale($ctx, 10000, card: true);
    [$from, $to] = settleWindow();
    $action = app(SettleCommissionAction::class);

    $settlement = $action->settle($ctx['company']->id, $from, $to, null, 500, 'manual', null, null, null, null);
    DB::table('pos_sale_commissions')->where('order_id', $orderId)->where('party_type', 'merchant')->update(['payout_id' => 42]);

    expect(fn () => $action->reverse($settlement, null))
        ->toThrow(RuntimeException::class, 'already been paid out');
});

// ─── HTTP / authorization ──────────────────────────────────────────────────

it('previews the unsettled card summary over HTTP', function (): void {
    settleActingAs($this, PlatformRole::SuperAdmin->value);
    $ctx = settleSeedGraph();
    settleSeedSale($ctx, 10000, card: true);

    $res = $this->getJson('/admin/api/v1/commission-settlements/preview?'.http_build_query([
        'company_uuid' => $ctx['company']->uuid,
        'from' => CarbonImmutable::now()->toDateString(),
        'to' => CarbonImmutable::now()->toDateString(),
    ]))->assertOk();

    expect($res->json('data.orders_count'))->toBe(1)
        ->and($res->json('data.estimated_bank'))->toBe('0.300')
        ->and($res->json('data.card_gross'))->toBe('10.000')
        ->and($res->json('data.merchant_net_estimated'))->toBe('9.500');
});

it('applies a settlement over HTTP and lists it', function (): void {
    settleActingAs($this, PlatformRole::SuperAdmin->value);
    $ctx = settleSeedGraph();
    settleSeedSale($ctx, 10000, card: true);

    $store = $this->postJson('/admin/api/v1/commission-settlements', [
        'company_uuid' => $ctx['company']->uuid,
        'from' => CarbonImmutable::now()->toDateString(),
        'to' => CarbonImmutable::now()->toDateString(),
        'actual_bank' => '0.500',
        'note' => 'Bank Dhofar Jun statement',
    ])->assertCreated();

    expect($store->json('data.merchant_net'))->toBe('9.300')
        ->and($store->json('data.variance'))->toBe('0.200');

    $list = $this->getJson('/admin/api/v1/commission-settlements?company_uuid='.$ctx['company']->uuid)->assertOk();
    expect($list->json('data'))->toHaveCount(1)
        ->and($list->json('data.0.company_name'))->toBe($ctx['company']->name);
});

it('forbids settling without settings.manage', function (): void {
    settleActingAs($this, null); // no role → no permissions
    $ctx = settleSeedGraph();
    settleSeedSale($ctx, 10000, card: true);

    $this->postJson('/admin/api/v1/commission-settlements', [
        'company_uuid' => $ctx['company']->uuid,
        'from' => CarbonImmutable::now()->toDateString(),
        'to' => CarbonImmutable::now()->toDateString(),
        'actual_bank' => '0.500',
    ])->assertForbidden();

    $this->getJson('/admin/api/v1/commission-settlements')->assertForbidden();
});

// ─── Pending settlement drill-down ─────────────────────────────────────────

it('lists merchants and branches with card sales to settle', function (): void {
    settleActingAs($this, PlatformRole::SuperAdmin->value);
    $ctx = settleSeedGraph();
    $other = Branch::factory()->create(['company_id' => $ctx['company']->id]);
    settleSeedSale($ctx, 10000, card: true);                       // default branch
    settleSeedSale($ctx, 5000, card: true, branchId: $other->id);  // other branch
    settleSeedSale($ctx, 5000, card: false);                       // cash → not pending

    $rows = $this->getJson('/admin/api/v1/commission-settlements/pending?'.http_build_query([
        'from' => CarbonImmutable::now()->toDateString(),
        'to' => CarbonImmutable::now()->toDateString(),
    ]))->assertOk()->json('data');

    $merchant = collect($rows)->firstWhere('company_uuid', $ctx['company']->uuid);
    expect($merchant)->not->toBeNull()
        ->and($merchant['pending_orders'])->toBe(2)       // 2 card sales, cash excluded
        ->and($merchant['branches'])->toHaveCount(2);
});

// ─── Order-level reconciliation worklist ───────────────────────────────────

it('lists per-order detail for a branch reconciliation worklist', function (): void {
    settleActingAs($this, PlatformRole::SuperAdmin->value);
    $ctx = settleSeedGraph();
    $o1 = settleSeedSale($ctx, 10000, card: true); // est bank 0.300, merchant 9.500
    settleSeedSale($ctx, 5000, card: true);
    // Bank evidence + a round-up riding o1 (NOT part of the sale/commission).
    DB::table('pos_payments')->where('order_id', $o1)->update(['softpos_auth_code' => 'A123', 'softpos_reference' => 'REF1']);
    $paymentId = (int) DB::table('pos_payments')->where('order_id', $o1)->where('method', 'card')->value('id');
    DB::table('pos_roundup_donations')->insert([
        'uuid' => (string) Str::uuid(), 'company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id,
        'device_id' => $ctx['device']->id, 'order_id' => $o1, 'payment_id' => $paymentId, 'terminal_id' => $ctx['device']->terminal_id,
        'amount' => '0.050', 'bank_response' => json_encode(['status' => 'ok']), 'status' => 'pending', 'source' => 'pos_roundup',
        'occurred_at' => now(), 'forwarded_at' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $rows = $this->getJson('/admin/api/v1/commission-settlements/orders?'.http_build_query([
        'company_uuid' => $ctx['company']->uuid,
        'branch_uuid' => $ctx['branch']->uuid,
        'from' => CarbonImmutable::now()->toDateString(),
        'to' => CarbonImmutable::now()->toDateString(),
    ]))->assertOk()->json('data');

    expect($rows)->toHaveCount(2);
    $big = collect($rows)->firstWhere('card_amount', '10.000');
    expect($big['estimated_bank'])->toBe('0.300')
        ->and($big['estimated_merchant_net'])->toBe('9.500')
        ->and($big['roundup'])->toBe('0.050')          // shown apart from the sale
        ->and($big['is_settled'])->toBeFalse()
        ->and($big['tenders'][0]['auth_code'])->toBe('A123')
        ->and($big['tenders'][0]['terminal_id'])->not->toBeNull()
        // Phase A1 — the order carries its terminal at the top level so the
        // worklist can group card sales per terminal.
        ->and($big['terminal_id'])->toBe($ctx['device']->terminal_id);
});

it('groups a cash sale under its device terminal with the device name + method amounts', function (): void {
    settleActingAs($this, PlatformRole::SuperAdmin->value);
    $ctx = settleSeedGraph();
    settleSeedSale($ctx, 10000, card: true);
    settleSeedSale($ctx, 4000, card: false);  // cash — still rang on the device

    $rows = $this->getJson('/admin/api/v1/commission-settlements/orders?'.http_build_query([
        'company_uuid' => $ctx['company']->uuid,
        'branch_uuid' => $ctx['branch']->uuid,
        'from' => CarbonImmutable::now()->toDateString(),
        'to' => CarbonImmutable::now()->toDateString(),
        'payment_method' => 'all',
    ]))->assertOk()->json('data');

    $card = collect($rows)->firstWhere('card_amount', '10.000');
    $cash = collect($rows)->firstWhere('cash_amount', '4.000');
    // Both group under the SAME device terminal (cash snapshots it too), and
    // both carry the device name for the terminal-tab label.
    expect($card['terminal_id'])->toBe($ctx['device']->terminal_id)
        ->and($cash['terminal_id'])->toBe($ctx['device']->terminal_id)
        ->and($card['device_name'])->toBe('POS-SETTLE-1')
        ->and($cash['device_name'])->toBe('POS-SETTLE-1')
        ->and($cash['card_amount'])->toBe('0.000')
        ->and($card['cash_amount'])->toBe('0.000')
        ->and($card['tenders'][0]['method'])->toBe('card')
        ->and($cash['tenders'][0]['method'])->toBe('cash');
});

it('pre-fills suggested_bank from a bank fee captured during reconciliation (A2)', function (): void {
    settleActingAs($this, PlatformRole::SuperAdmin->value);
    $ctx = settleSeedGraph();
    $withFee = settleSeedSale($ctx, 10000, card: true);   // bank statement told us the real fee
    settleSeedSale($ctx, 5000, card: true);               // no statement yet → estimate only

    // Reconciliation stamped the actual fee onto the card payment.
    DB::table('pos_payments')->where('order_id', $withFee)->where('method', 'card')
        ->update(['bank_fee' => '0.275']);

    $rows = $this->getJson('/admin/api/v1/commission-settlements/orders?'.http_build_query([
        'company_uuid' => $ctx['company']->uuid,
        'branch_uuid' => $ctx['branch']->uuid,
        'from' => CarbonImmutable::now()->toDateString(),
        'to' => CarbonImmutable::now()->toDateString(),
    ]))->assertOk()->json('data');

    $captured = collect($rows)->firstWhere('card_amount', '10.000');
    $estimateOnly = collect($rows)->firstWhere('card_amount', '5.000');
    expect($captured['suggested_bank'])->toBe('0.275')       // surfaced from the statement
        ->and($estimateOnly['suggested_bank'])->toBeNull();  // nothing captured → no suggestion
});

it('lists ONLY pure cash/bank-POS sales with payment_method=cash_bank (the separated workspace)', function (): void {
    settleActingAs($this, PlatformRole::SuperAdmin->value);
    $ctx = settleSeedGraph();
    settleSeedSale($ctx, 10000, card: true);   // card → excluded here
    settleSeedSale($ctx, 4000, card: false);   // pure cash → included

    $q = [
        'company_uuid' => $ctx['company']->uuid,
        'branch_uuid' => $ctx['branch']->uuid,
        'from' => CarbonImmutable::now()->toDateString(),
        'to' => CarbonImmutable::now()->toDateString(),
        'payment_method' => 'cash_bank',
    ];
    $rows = $this->getJson('/admin/api/v1/commission-settlements/orders?'.http_build_query($q))->assertOk()->json('data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['cash_amount'])->toBe('4.000')
        ->and($rows[0]['card_amount'])->toBe('0.000');
});

it('shows only card sales by default but includes cash with payment_method=all', function (): void {
    settleActingAs($this, PlatformRole::SuperAdmin->value);
    $ctx = settleSeedGraph();
    settleSeedSale($ctx, 10000, card: true);  // card → est bank 0.300
    settleSeedSale($ctx, 4000, card: false);  // cash → no bank cut

    $q = [
        'company_uuid' => $ctx['company']->uuid,
        'branch_uuid' => $ctx['branch']->uuid,
        'from' => CarbonImmutable::now()->toDateString(),
        'to' => CarbonImmutable::now()->toDateString(),
    ];

    // Default = card only (the bank-fee to-do); the cash sale is hidden.
    $cardOnly = $this->getJson('/admin/api/v1/commission-settlements/orders?'.http_build_query($q))->assertOk()->json('data');
    expect($cardOnly)->toHaveCount(1)
        ->and($cardOnly[0]['card_amount'])->toBe('10.000')
        ->and($cardOnly[0]['needs_reconciliation'])->toBeTrue();

    // payment_method=all also surfaces the cash sale, flagged review-only.
    $all = $this->getJson('/admin/api/v1/commission-settlements/orders?'.http_build_query($q + ['payment_method' => 'all']))->assertOk()->json('data');
    expect($all)->toHaveCount(2);
    $cash = collect($all)->firstWhere('needs_reconciliation', false);
    expect($cash)->not->toBeNull()
        ->and($cash['estimated_bank'])->toBe('0.000')
        ->and($cash['estimated_merchant_net'])->toBe('3.920'); // 4.000 − 2% platform
});

it('excludes orders already claimed into a payout from the worklist', function (): void {
    settleActingAs($this, PlatformRole::SuperAdmin->value);
    $ctx = settleSeedGraph();
    settleSeedSale($ctx, 10000, card: true);          // open
    $paid = settleSeedSale($ctx, 5000, card: true);   // claimed into a payout
    DB::table('pos_sale_commissions')->where('order_id', $paid)->where('party_type', 'merchant')->update(['payout_id' => 555]);

    $rows = $this->getJson('/admin/api/v1/commission-settlements/orders?'.http_build_query([
        'company_uuid' => $ctx['company']->uuid,
        'branch_uuid' => $ctx['branch']->uuid,
        'from' => CarbonImmutable::now()->toDateString(),
        'to' => CarbonImmutable::now()->toDateString(),
    ]))->assertOk()->json('data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['card_amount'])->toBe('10.000');
});

it('settles selected orders each at its own actual fee (per-order path)', function (): void {
    settleActingAs($this, PlatformRole::SuperAdmin->value);
    $ctx = settleSeedGraph();
    $o1 = settleSeedSale($ctx, 10000, card: true); // est bank 0.300, merchant 9.500
    $o2 = settleSeedSale($ctx, 10000, card: true);

    $res = $this->postJson('/admin/api/v1/commission-settlements/orders', [
        'company_uuid' => $ctx['company']->uuid,
        'branch_uuid' => $ctx['branch']->uuid,
        'orders' => [
            ['order_uuid' => settleOrderUuid($o1), 'actual_bank' => '0.500'], // bank took more
            ['order_uuid' => settleOrderUuid($o2), 'actual_bank' => '0.300'], // matches estimate
        ],
    ])->assertCreated()->json('data');

    expect($res['orders_count'])->toBe(2)
        ->and($res['actual_bank'])->toBe('0.800')
        ->and($res['merchant_net'])->toBe('18.800'); // 9.300 + 9.500

    expect((string) settleRow($o1, 'merchant')->settled_amount)->toBe('9.300')
        ->and((string) settleRow($o1, 'bank')->settled_amount)->toBe('0.500')
        ->and((string) settleRow($o2, 'bank')->settled_amount)->toBe('0.300');
});

it('rejects per-order settle when an order is not settleable', function (): void {
    settleActingAs($this, PlatformRole::SuperAdmin->value);
    $ctx = settleSeedGraph();
    $o1 = settleSeedSale($ctx, 10000, card: true);
    [$from, $to] = settleWindow();
    // Settle it first via the batch path → now ineligible for a second settle.
    app(SettleCommissionAction::class)->settle($ctx['company']->id, $from, $to, null, 300, 'manual', null, null, null, null);

    $this->postJson('/admin/api/v1/commission-settlements/orders', [
        'company_uuid' => $ctx['company']->uuid,
        'branch_uuid' => $ctx['branch']->uuid,
        'orders' => [['order_uuid' => settleOrderUuid($o1), 'actual_bank' => '0.300']],
    ])->assertStatus(422);
});
