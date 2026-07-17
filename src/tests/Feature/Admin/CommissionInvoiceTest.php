<?php

declare(strict_types=1);

/**
 * Phase B — commission INVOICES (merchant owes the platform for cash/bank_pos
 * sales) + the payout leak fix (pure cash/bank_pos no longer swept into payouts).
 *
 *   GET/POST /admin/api/v1/commission-invoices ; .../{uuid}/mark-paid|void|lines ;
 *   .../pending ; .../batch-mark-paid
 *
 * Unlike PayoutsControllerTest (whose synthetic sales seed NO pos_payments, so
 * they exercise the pre-classification path), every sale here seeds a REAL order
 * + its tender payment(s), so the card-vs-cash/bank_pos classification — which
 * keys off pos_payments.method — is exercised for real.
 */

use App\Enums\PlatformRole;
use App\Models\Branch;
use App\Models\CommissionInvoice;
use App\Models\Company;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PlatformRoleSeeder::class);
});

function actingAsInvoiceAdmin(\Tests\TestCase $test): User
{
    /** @var User $user */
    $user = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $user->assignRole(PlatformRole::SuperAdmin->value);
    $test->actingAs($user);

    return $user;
}

function invOmr(int $baisas): string
{
    return number_format($baisas / 1000, 3, '.', '');
}

function invoiceCtx(): array
{
    $company = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $company->id]);

    return ['company' => $company, 'branch' => $branch];
}

/**
 * Seed a paid sale: a real order + its tender payment(s) + commission rows.
 *
 * @param  array<string, int>  $parties  party_type => baisas (platform/bank/other/merchant)
 * @param  list<array{method: string, baisas: int, status?: string}>  $tenders
 */
function seedInvoiceSale(array $ctx, array $parties, array $tenders, ?Branch $branch = null, string $occurredAt = '2026-06-12 10:00:00'): int
{
    $company = $ctx['company'];
    $branch ??= $ctx['branch'];
    $gross = array_sum($parties);

    $orderId = DB::table('pos_orders')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'branch_id' => $branch->id,
        'order_type' => 'quick', 'status' => 'paid', 'source' => 'main_pos',
        'subtotal' => invOmr($gross), 'discount_total' => 0, 'tax_total' => 0, 'grand_total' => invOmr($gross),
        'opened_at' => $occurredAt, 'closed_at' => $occurredAt, 'created_at' => $occurredAt, 'updated_at' => $occurredAt,
    ]);

    foreach ($tenders as $t) {
        DB::table('pos_payments')->insert([
            'uuid' => (string) Str::uuid(), 'order_id' => $orderId, 'method' => $t['method'],
            'amount' => invOmr($t['baisas']), 'status' => $t['status'] ?? 'success', 'pending_reconciliation' => false,
            'captured_at' => $occurredAt, 'created_at' => $occurredAt, 'updated_at' => $occurredAt,
        ]);
    }

    $sort = 0;
    foreach ($parties as $party => $amt) {
        DB::table('pos_sale_commissions')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'branch_id' => $branch->id, 'device_id' => 1,
            'order_id' => $orderId, 'party_type' => $party, 'party_label' => ucfirst($party), 'percent' => 0,
            'gross_amount' => invOmr($gross), 'commission_amount' => invOmr($amt), 'sort_order' => $sort++,
            'occurred_at' => $occurredAt, 'created_at' => $occurredAt, 'updated_at' => $occurredAt,
        ]);
    }

    return $orderId;
}

/**
 * Verify a sale at its estimate — stamps every row settled. For a card sale this
 * satisfies the reconcile-before-payout guard; for a cash/bank_pos sale it is
 * the Step 3 one-by-one verification that makes the sale BILLABLE (invoices are
 * verified-first).
 */
function verifyInvoiceSale(int $orderId): void
{
    DB::table('pos_sale_commissions')->where('order_id', $orderId)->update([
        'is_settled' => true,
        'settled_amount' => DB::raw('commission_amount'),
        'settled_at' => '2026-06-12 11:00:00',
    ]);
}

// ── Issuing an invoice ──────────────────────────────────────────────────────

it('issues an invoice billing platform + other on a pure cash sale', function (): void {
    actingAsInvoiceAdmin($this);
    $ctx = invoiceCtx();
    // Cash sale: platform 0.200 + other 0.100 owed; merchant keeps 9.700.
    $order = seedInvoiceSale($ctx, ['platform' => 200, 'bank' => 0, 'other' => 100, 'merchant' => 9700], [['method' => 'cash', 'baisas' => 10000]]);
    verifyInvoiceSale($order); // Step 3 — only verified sales are billable

    $d = $this->postJson('/admin/api/v1/commission-invoices', [
        'company_uuid' => $ctx['company']->uuid, 'from' => '2026-06-01', 'to' => '2026-06-30',
    ])->assertCreated()->json('data');

    expect($d['status'])->toBe('issued')
        ->and($d['total_owed'])->toBe('0.300')       // platform + other
        ->and($d['platform_amount'])->toBe('0.200')
        ->and($d['other_amount'])->toBe('0.100')
        ->and($d['merchant_amount'])->toBe('9.700')  // what the merchant kept
        ->and($d['gross_amount'])->toBe('10.000')
        ->and($d['cash_gross'])->toBe('10.000')      // Step 4 — received in cash
        ->and($d['bank_pos_gross'])->toBe('0.000')
        ->and($d['sales_count'])->toBe(1);

    // The platform + other rows are claimed; the merchant row is NOT.
    expect(DB::table('pos_sale_commissions')->where('order_id', $order)->whereIn('party_type', ['platform', 'other'])->whereNotNull('invoice_id')->count())->toBe(2)
        ->and(DB::table('pos_sale_commissions')->where('order_id', $order)->where('party_type', 'merchant')->whereNotNull('invoice_id')->count())->toBe(0);
});

it('bills bank_pos exactly like cash (no bank cut, platform owed)', function (): void {
    actingAsInvoiceAdmin($this);
    $ctx = invoiceCtx();
    $order = seedInvoiceSale($ctx, ['platform' => 200, 'merchant' => 9800], [['method' => 'bank_pos', 'baisas' => 10000]]);
    verifyInvoiceSale($order);

    $d = $this->postJson('/admin/api/v1/commission-invoices', [
        'company_uuid' => $ctx['company']->uuid, 'from' => '2026-06-01', 'to' => '2026-06-30',
    ])->assertCreated()->json('data');

    expect($d['total_owed'])->toBe('0.200')->and($d['sales_count'])->toBe(1)
        ->and($d['bank_pos_gross'])->toBe('10.000')  // Step 4 — received on the bank's POS
        ->and($d['cash_gross'])->toBe('0.000');
});

it('refuses to invoice an unverified cash sale (verify first)', function (): void {
    actingAsInvoiceAdmin($this);
    $ctx = invoiceCtx();
    seedInvoiceSale($ctx, ['platform' => 200, 'merchant' => 9800], [['method' => 'cash', 'baisas' => 10000]]);
    // NOT verified — the methodology bills only figures a human signed off.

    $this->postJson('/admin/api/v1/commission-invoices', [
        'company_uuid' => $ctx['company']->uuid, 'from' => '2026-06-01', 'to' => '2026-06-30',
    ])->assertStatus(422)->assertJsonPath('message', 'No verified un-invoiced cash or bank-POS commission for this merchant in the selected period. Verify the sales in the Sales workspace first.');
});

it('excludes card sales from invoicing', function (): void {
    actingAsInvoiceAdmin($this);
    $ctx = invoiceCtx();
    $order = seedInvoiceSale($ctx, ['platform' => 200, 'bank' => 300, 'merchant' => 9500], [['method' => 'card', 'baisas' => 10000]]);
    verifyInvoiceSale($order); // even verified, a CARD sale is never invoice-eligible

    $this->postJson('/admin/api/v1/commission-invoices', [
        'company_uuid' => $ctx['company']->uuid, 'from' => '2026-06-01', 'to' => '2026-06-30',
    ])->assertStatus(422)->assertJsonPath('message', 'No verified un-invoiced cash or bank-POS commission for this merchant in the selected period. Verify the sales in the Sales workspace first.');
});

it('does not invoice a mixed card+cash order (it rides the payout instead)', function (): void {
    actingAsInvoiceAdmin($this);
    $ctx = invoiceCtx();
    // Part card + part cash → has a card tender, so it is NOT invoice-eligible in v1.
    seedInvoiceSale($ctx, ['platform' => 200, 'bank' => 150, 'merchant' => 9650], [
        ['method' => 'card', 'baisas' => 5000],
        ['method' => 'cash', 'baisas' => 5000],
    ]);

    $this->postJson('/admin/api/v1/commission-invoices', [
        'company_uuid' => $ctx['company']->uuid, 'from' => '2026-06-01', 'to' => '2026-06-30',
    ])->assertStatus(422);
});

it('sums cash + bank_pos across a period into one invoice', function (): void {
    actingAsInvoiceAdmin($this);
    $ctx = invoiceCtx();
    verifyInvoiceSale(seedInvoiceSale($ctx, ['platform' => 200, 'merchant' => 9800], [['method' => 'cash', 'baisas' => 10000]]));
    verifyInvoiceSale(seedInvoiceSale($ctx, ['platform' => 100, 'merchant' => 4900], [['method' => 'bank_pos', 'baisas' => 5000]]));

    $d = $this->postJson('/admin/api/v1/commission-invoices', [
        'company_uuid' => $ctx['company']->uuid, 'from' => '2026-06-01', 'to' => '2026-06-30',
    ])->assertCreated()->json('data');

    expect($d['total_owed'])->toBe('0.300')->and($d['sales_count'])->toBe(2)
        // Step 4 — the received split tells the merchant HOW the money arrived.
        ->and($d['cash_gross'])->toBe('10.000')
        ->and($d['bank_pos_gross'])->toBe('5.000');
});

// ── The payout leak fix ─────────────────────────────────────────────────────

it('refuses a payout when the only sales are cash (billed via invoice instead)', function (): void {
    actingAsInvoiceAdmin($this);
    $ctx = invoiceCtx();
    seedInvoiceSale($ctx, ['platform' => 40, 'merchant' => 1960], [['method' => 'cash', 'baisas' => 2000]]);

    $this->postJson('/admin/api/v1/payouts', [
        'company_uuid' => $ctx['company']->uuid, 'from' => '2026-06-01', 'to' => '2026-06-30',
    ])->assertStatus(422)->assertJsonPath('message', 'No unsettled earnings for this merchant in the selected period.');

    // The cash merchant row is NOT claimed into any payout.
    expect(DB::table('pos_sale_commissions')->where('party_type', 'merchant')->whereNotNull('payout_id')->count())->toBe(0);
});

it('pays out only card money, leaving cash for the invoice', function (): void {
    actingAsInvoiceAdmin($this);
    $ctx = invoiceCtx();
    $card = seedInvoiceSale($ctx, ['platform' => 60, 'bank' => 90, 'merchant' => 2850], [['method' => 'card', 'baisas' => 3000]]);
    $cash = seedInvoiceSale($ctx, ['platform' => 40, 'merchant' => 1960], [['method' => 'cash', 'baisas' => 2000]]);
    verifyInvoiceSale($card);

    $d = $this->postJson('/admin/api/v1/payouts', [
        'company_uuid' => $ctx['company']->uuid, 'from' => '2026-06-01', 'to' => '2026-06-30',
    ])->assertCreated()->json('data');

    expect($d['net_amount'])->toBe('2.850')  // card only, NOT 4.810
        ->and($d['sales_count'])->toBe(1);

    // Card merchant row claimed; cash merchant row untouched (goes to an invoice).
    expect((int) DB::table('pos_sale_commissions')->where('order_id', $card)->where('party_type', 'merchant')->value('payout_id'))->toBeGreaterThan(0)
        ->and(DB::table('pos_sale_commissions')->where('order_id', $cash)->where('party_type', 'merchant')->whereNull('payout_id')->count())->toBe(1);
});

// ── Lifecycle + guards ──────────────────────────────────────────────────────

it('refuses to bill the same cash commission twice (double-bill guard)', function (): void {
    actingAsInvoiceAdmin($this);
    $ctx = invoiceCtx();
    verifyInvoiceSale(seedInvoiceSale($ctx, ['platform' => 200, 'merchant' => 9800], [['method' => 'cash', 'baisas' => 10000]]));

    $this->postJson('/admin/api/v1/commission-invoices', ['company_uuid' => $ctx['company']->uuid, 'from' => '2026-06-01', 'to' => '2026-06-30'])->assertCreated();
    $this->postJson('/admin/api/v1/commission-invoices', ['company_uuid' => $ctx['company']->uuid, 'from' => '2026-06-01', 'to' => '2026-06-30'])->assertStatus(422);
});

it('voids an issued invoice and releases its rows for re-billing', function (): void {
    actingAsInvoiceAdmin($this);
    $ctx = invoiceCtx();
    verifyInvoiceSale(seedInvoiceSale($ctx, ['platform' => 200, 'merchant' => 9800], [['method' => 'cash', 'baisas' => 10000]]));
    $uuid = $this->postJson('/admin/api/v1/commission-invoices', ['company_uuid' => $ctx['company']->uuid, 'from' => '2026-06-01', 'to' => '2026-06-30'])->json('data.uuid');

    $this->postJson("/admin/api/v1/commission-invoices/{$uuid}/void")->assertOk()->assertJsonPath('data.status', 'void');

    // Rows released → a fresh invoice for the same period succeeds again.
    expect(DB::table('pos_sale_commissions')->whereNotNull('invoice_id')->count())->toBe(0);
    $this->postJson('/admin/api/v1/commission-invoices', ['company_uuid' => $ctx['company']->uuid, 'from' => '2026-06-01', 'to' => '2026-06-30'])->assertCreated();
});

it('marks an issued invoice paid with a reference and is terminal', function (): void {
    actingAsInvoiceAdmin($this);
    $ctx = invoiceCtx();
    verifyInvoiceSale(seedInvoiceSale($ctx, ['platform' => 200, 'merchant' => 9800], [['method' => 'cash', 'baisas' => 10000]]));
    $uuid = $this->postJson('/admin/api/v1/commission-invoices', ['company_uuid' => $ctx['company']->uuid, 'from' => '2026-06-01', 'to' => '2026-06-30'])->json('data.uuid');

    $res = $this->postJson("/admin/api/v1/commission-invoices/{$uuid}/mark-paid", ['reference' => 'REMIT-42'])->assertOk();
    expect($res->json('data.status'))->toBe('paid')->and($res->json('data.reference'))->toBe('REMIT-42')->and($res->json('data.paid_at'))->not->toBeNull();

    // Terminal — a second mark-paid and a void are both rejected.
    $this->postJson("/admin/api/v1/commission-invoices/{$uuid}/mark-paid")->assertStatus(422);
    $this->postJson("/admin/api/v1/commission-invoices/{$uuid}/void")->assertStatus(422);
});

it('marks several issued invoices paid in one batch, skipping non-issued', function (): void {
    actingAsInvoiceAdmin($this);
    $a = invoiceCtx();
    $b = invoiceCtx();
    verifyInvoiceSale(seedInvoiceSale($a, ['platform' => 200, 'merchant' => 9800], [['method' => 'cash', 'baisas' => 10000]]));
    verifyInvoiceSale(seedInvoiceSale($b, ['platform' => 100, 'merchant' => 4900], [['method' => 'cash', 'baisas' => 5000]]));

    $ia = $this->postJson('/admin/api/v1/commission-invoices', ['company_uuid' => $a['company']->uuid, 'from' => '2026-06-01', 'to' => '2026-06-30'])->json('data.uuid');
    $ib = $this->postJson('/admin/api/v1/commission-invoices', ['company_uuid' => $b['company']->uuid, 'from' => '2026-06-01', 'to' => '2026-06-30'])->json('data.uuid');
    $this->postJson("/admin/api/v1/commission-invoices/{$ia}/mark-paid")->assertOk(); // pre-pay one

    $res = $this->postJson('/admin/api/v1/commission-invoices/batch-mark-paid', ['invoice_uuids' => [$ia, $ib], 'reference' => 'BATCH-9'])->assertOk();
    expect($res->json('data.marked'))->toBe(1)->and($res->json('data.skipped'))->toBe(1);
    expect(CommissionInvoice::query()->where('status', 'paid')->count())->toBe(2);
});

// ── Scope, drill, statement, gates ──────────────────────────────────────────

it('scopes an invoice to a single branch', function (): void {
    actingAsInvoiceAdmin($this);
    $ctx = invoiceCtx();
    $b1 = $ctx['branch'];
    $b2 = Branch::factory()->create(['company_id' => $ctx['company']->id, 'name' => 'Mall']);
    verifyInvoiceSale(seedInvoiceSale($ctx, ['platform' => 200, 'merchant' => 9800], [['method' => 'cash', 'baisas' => 10000]], $b1));
    $order2 = seedInvoiceSale($ctx, ['platform' => 100, 'merchant' => 4900], [['method' => 'cash', 'baisas' => 5000]], $b2);
    verifyInvoiceSale($order2);

    $d = $this->postJson('/admin/api/v1/commission-invoices', [
        'company_uuid' => $ctx['company']->uuid, 'branch_uuid' => $b1->uuid, 'from' => '2026-06-01', 'to' => '2026-06-30',
    ])->assertCreated()->json('data');

    expect($d['total_owed'])->toBe('0.200')->and((int) $d['branch_id'])->toBe($b1->id);
    // Branch 2's platform row stays unclaimed for its own invoice.
    expect(DB::table('pos_sale_commissions')->where('order_id', $order2)->where('party_type', 'platform')->whereNull('invoice_id')->count())->toBe(1);
});

it('lists the merchants/branches with cash commission to bill (the pending drill)', function (): void {
    actingAsInvoiceAdmin($this);
    $ctx = invoiceCtx();
    verifyInvoiceSale(seedInvoiceSale($ctx, ['platform' => 200, 'other' => 100, 'merchant' => 9700], [['method' => 'cash', 'baisas' => 10000]]));

    $rows = $this->getJson('/admin/api/v1/commission-invoices/pending?from=2026-06-01&to=2026-06-30')->assertOk()->json('data');
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['company_uuid'])->toBe($ctx['company']->uuid)
        ->and($rows[0]['pending_owed'])->toBe('0.300')
        ->and($rows[0]['branches'][0]['pending_owed'])->toBe('0.300');
});

it('returns a per-branch invoice statement (the lines)', function (): void {
    actingAsInvoiceAdmin($this);
    $ctx = invoiceCtx();
    verifyInvoiceSale(seedInvoiceSale($ctx, ['platform' => 200, 'other' => 100, 'merchant' => 9700], [['method' => 'cash', 'baisas' => 10000]]));
    $uuid = $this->postJson('/admin/api/v1/commission-invoices', ['company_uuid' => $ctx['company']->uuid, 'from' => '2026-06-01', 'to' => '2026-06-30'])->json('data.uuid');

    $lines = $this->getJson("/admin/api/v1/commission-invoices/{$uuid}/lines")->assertOk()->json('data');
    expect($lines)->toHaveCount(1)
        ->and($lines[0]['total_owed'])->toBe('0.300')
        ->and($lines[0]['platform'])->toBe('0.200')
        ->and($lines[0]['other'])->toBe('0.100')
        ->and($lines[0]['merchant_kept'])->toBe('9.700')
        ->and($lines[0]['num_sales'])->toBe(1);
});

it('lists invoices filtered by company + status', function (): void {
    actingAsInvoiceAdmin($this);
    $ctx = invoiceCtx();
    verifyInvoiceSale(seedInvoiceSale($ctx, ['platform' => 200, 'merchant' => 9800], [['method' => 'cash', 'baisas' => 10000]]));
    $this->postJson('/admin/api/v1/commission-invoices', ['company_uuid' => $ctx['company']->uuid, 'from' => '2026-06-01', 'to' => '2026-06-30'])->assertCreated();

    $rows = $this->getJson("/admin/api/v1/commission-invoices?company_uuid={$ctx['company']->uuid}&status=issued")->assertOk()->json('data');
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['company_name'])->toBe($ctx['company']->name)
        ->and($rows[0]['total_owed'])->toBe('0.200');
});

it('gates reads on reports.view and writes on settings.manage', function (): void {
    $this->actingAs(User::factory()->create()); // no platform role
    $c = Company::factory()->create();

    $this->getJson('/admin/api/v1/commission-invoices')->assertForbidden();
    $this->getJson('/admin/api/v1/commission-invoices/pending?from=2026-06-01&to=2026-06-30')->assertForbidden();
    $this->postJson('/admin/api/v1/commission-invoices', ['company_uuid' => $c->uuid, 'from' => '2026-06-01', 'to' => '2026-06-30'])->assertForbidden();
});
