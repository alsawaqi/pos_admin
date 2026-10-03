<?php

declare(strict_types=1);

/*
 * LAUNCH-P4 — the admin payment-reversal copy and the VAT onboarding rule.
 *
 *  - A1: the reversal copy walks combo children like pos_api: a void restores
 *    each chosen item's own stock (shelf, recipe, add-ons), the combo line
 *    itself moves nothing; a refund returns the shelf of the combo children
 *    named by the refund's derived child lines (pos_api writes them,
 *    proportional to the refunded combo quantity).
 *  - A2: marking a company VAT-registered gives it "VAT / ضريبة القيمة
 *    المضافة 5%" when it has no tax row; a company with taxes keeps them.
 */

use App\Actions\Admin\CreateCompanyAction;
use App\Actions\Admin\UpdateCompanyAction;
use App\Actions\Payments\ReturnRefundedUnitStockAction;
use App\Data\Admin\CompanyComplianceData;
use App\Data\Admin\CompanyContactData;
use App\Data\Admin\CompanyOwnerData;
use App\Data\Admin\CreateCompanyData;
use App\Data\Admin\UpdateCompanyData;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Device;
use App\Models\PaymentReversal;
use App\Support\Reversals\ConsumeInventoryAction;
use App\Support\Reversals\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\LaravelData\DataCollection;

uses(RefreshDatabase::class);

/** A paid order of 2 × "Burger meal": per meal 1 × Fries (shelf) and 1 × Burger (made to order, 100 g beef + extra cheese). */
function p4ComboOrder(): array
{
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $product = fn (string $name, string $mode, array $extra = []): int => (int) DB::table('pos_products')->insertGetId($extra + [
        'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'name' => $name, 'base_price' => '1.000',
        'stock_mode' => $mode, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $meal = $product('Burger meal', 'untracked', ['product_type' => 'combo']);
    $fries = $product('Fries', 'unit');
    $burger = $product('Burger', 'ingredient');
    $beef = (int) DB::table('pos_ingredients')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'name' => 'Beef', 'unit' => 'g',
        'default_unit_cost' => '0.004000', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $cheese = (int) DB::table('pos_ingredients')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'name' => 'Cheese', 'unit' => 'g',
        'default_unit_cost' => '0.006000', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('pos_branch_product')->insert(['branch_id' => $branch->id, 'product_id' => $fries, 'is_available' => true, 'stock_qty' => '8.000', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('pos_branch_stock')->insert(['branch_id' => $branch->id, 'ingredient_id' => $beef, 'quantity' => '1000.0000', 'last_movement_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('pos_branch_stock')->insert(['branch_id' => $branch->id, 'ingredient_id' => $cheese, 'quantity' => '500.0000', 'last_movement_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    $orderId = (int) DB::table('pos_orders')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'branch_id' => $branch->id,
        'order_type' => 'quick', 'source' => 'pos', 'status' => 'paid', 'subtotal' => '6.000', 'tax_total' => '0.286',
        'grand_total' => '6.000', 'prices_include_tax' => true, 'opened_at' => '2026-10-03 09:00:00', 'closed_at' => '2026-10-03 09:05:00',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $parent = (int) DB::table('pos_order_items')->insertGetId([
        'order_id' => $orderId, 'product_id' => $meal, 'product_name_snapshot' => 'Burger meal', 'qty' => '2.000',
        'unit_price_snapshot' => '3.000', 'line_total' => '6.000', 'recipe_snapshot_json' => null, 'component_snapshot_json' => '[]',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $friesItem = (int) DB::table('pos_order_items')->insertGetId([
        'order_id' => $orderId, 'product_id' => $fries, 'product_name_snapshot' => 'Fries', 'qty' => '2.000',
        'unit_price_snapshot' => '0.000', 'line_total' => '0.000', 'component_snapshot_json' => '[]',
        'parent_order_item_id' => $parent, 'combo_slot_id' => 2, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $burgerItem = (int) DB::table('pos_order_items')->insertGetId([
        'order_id' => $orderId, 'product_id' => $burger, 'product_name_snapshot' => 'Burger', 'qty' => '2.000',
        'unit_price_snapshot' => '0.000', 'line_total' => '0.000', 'component_snapshot_json' => '[]',
        'recipe_snapshot_json' => json_encode([['ingredient_id' => $beef, 'qty' => 100, 'unit' => 'g', 'unit_cost' => 0.004]]),
        'parent_order_item_id' => $parent, 'combo_slot_id' => 1, 'combo_extra_price' => '0.300', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $group = (int) DB::table('pos_addon_groups')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'name' => 'Extras', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $addOn = (int) DB::table('pos_addons')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'add_on_group_id' => $group, 'name' => 'Extra cheese',
        'price_delta' => '0.200', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('pos_order_item_addons')->insert([
        'order_item_id' => $burgerItem, 'add_on_id' => $addOn, 'add_on_name_snapshot' => 'Extra cheese', 'price_delta_snapshot' => '0.200',
        'ingredient_snapshot_json' => json_encode(['ingredient_id' => $cheese, 'qty' => 20, 'unit_cost' => 0.006]),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return compact('company', 'branch', 'meal', 'fries', 'burger', 'beef', 'cheese', 'orderId', 'parent', 'friesItem', 'burgerItem');
}

it('restores each combo child\'s own stock on a reversal and never moves the combo line itself', function (): void {
    $f = p4ComboOrder();

    (new ConsumeInventoryAction)->reverse(Order::query()->findOrFail($f['orderId']));

    expect((float) DB::table('pos_branch_product')->where('product_id', $f['fries'])->value('stock_qty'))->toBe(10.0)
        ->and((float) DB::table('pos_branch_stock')->where('ingredient_id', $f['beef'])->value('quantity'))->toBe(1200.0)
        ->and((float) DB::table('pos_branch_stock')->where('ingredient_id', $f['cheese'])->value('quantity'))->toBe(540.0)
        ->and(DB::table('pos_product_stock_movements')->where('product_id', $f['meal'])->exists())->toBeFalse()
        ->and(DB::table('pos_product_stock_movements')->where('product_id', $f['fries'])->sum('quantity'))->toEqual(2);
});

it('returns the shelf of combo children named by a partial refund\'s derived child lines', function (): void {
    $f = p4ComboOrder();
    DB::table('banks')->insert(['id' => 1, 'name' => 'Synthetic bank', 'short_name' => 'Synthetic bank', 'is_active' => true,
        'created_at' => now(), 'updated_at' => now()]);
    $device = Device::factory()->create(['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id, 'bank_id' => 1]);
    $paymentId = (int) DB::table('pos_payments')->insertGetId([
        'uuid' => (string) Str::uuid(), 'order_id' => $f['orderId'], 'method' => 'card', 'amount' => '6.000',
        'status' => 'success', 'pending_reconciliation' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $staff = (int) DB::table('pos_staff')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $f['company']->id, 'branch_id' => $f['branch']->id,
        'name' => 'Manager', 'pin_hash' => 'unused', 'position' => 'manager',
    ]);
    $reversalId = (int) DB::table('pos_payment_reversals')->insertGetId([
        'approved_by_staff_id' => $staff,
        'uuid' => (string) Str::uuid(), 'company_id' => $f['company']->id, 'branch_id' => $f['branch']->id,
        'order_id' => $f['orderId'], 'payment_id' => $paymentId, 'kind' => 'refund', 'amount' => '3.000',
        'amount_baisas' => 3000, 'currency_code' => '0512', 'status' => 'approved', 'softpos_provider' => 'mosambee_dhofar',
        'softpos_package' => 'com.mosambee.dhofar.softpos', 'bank_id' => 1, 'device_id' => $device->id,
        'client_request_id' => (string) Str::uuid(), 'request_fingerprint' => str_repeat('a', 64), 'attempted_at' => now(),
    ]);
    // One of the two meals is refunded: the combo line carries the money,
    // its children the stock (pos_api derives them in proportion).
    DB::table('pos_payment_reversal_lines')->insert([
        ['reversal_id' => $reversalId, 'order_item_id' => $f['parent'], 'qty' => 1, 'amount' => '3.000', 'amount_baisas' => 3000,
            'stock_mode_at_refund' => 'untracked', 'returned_to_stock' => false],
        ['reversal_id' => $reversalId, 'order_item_id' => $f['friesItem'], 'qty' => 1, 'amount' => '0.000', 'amount_baisas' => 0,
            'stock_mode_at_refund' => 'unit', 'returned_to_stock' => false],
        ['reversal_id' => $reversalId, 'order_item_id' => $f['burgerItem'], 'qty' => 1, 'amount' => '0.000', 'amount_baisas' => 0,
            'stock_mode_at_refund' => 'ingredient', 'returned_to_stock' => false],
    ]);

    (new ReturnRefundedUnitStockAction)->handle(PaymentReversal::query()->findOrFail($reversalId));

    expect((float) DB::table('pos_branch_product')->where('product_id', $f['fries'])->value('stock_qty'))->toBe(9.0)
        ->and((bool) DB::table('pos_payment_reversal_lines')->where('order_item_id', $f['friesItem'])->value('returned_to_stock'))->toBeTrue()
        ->and((bool) DB::table('pos_payment_reversal_lines')->where('order_item_id', $f['parent'])->value('returned_to_stock'))->toBeFalse();
});

function p4CompanyData(?string $vatRegisteredAt, string $cr): CreateCompanyData
{
    return new CreateCompanyData(
        name: 'Qahwa '.$cr, nameAr: null, legalName: null, legalNameAr: null,
        compliance: new CompanyComplianceData(crNumber: $cr, vatNumber: $vatRegisteredAt === null ? null : 'OM55'.$cr, vatRegisteredAt: $vatRegisteredAt),
        contact: new CompanyContactData,
        owners: new DataCollection(CompanyOwnerData::class, [['fullNameEn' => 'Owner', 'isPrimary' => true]]),
    );
}

/** @return list<array{name: string, name_ar: ?string, rate: float, active: bool}> */
function p4Taxes(int $companyId): array
{
    return DB::table('pos_taxes')->where('company_id', $companyId)->whereNull('deleted_at')->orderBy('id')->get()
        ->map(fn ($t): array => ['name' => $t->name, 'name_ar' => $t->name_ar, 'rate' => (float) $t->rate_percent, 'active' => (bool) $t->is_active])
        ->all();
}

it('gives a company onboarded as VAT-registered its VAT 5% row, and an unregistered one none', function (): void {
    $registered = app(CreateCompanyAction::class)->handle(p4CompanyData('2026-01-01', '7000001'));
    $unregistered = app(CreateCompanyAction::class)->handle(p4CompanyData(null, '7000002'));

    expect(p4Taxes($registered->id))->toBe([['name' => 'VAT', 'name_ar' => 'ضريبة القيمة المضافة', 'rate' => 5.0, 'active' => true]])
        ->and(p4Taxes($unregistered->id))->toBe([])
        ->and(AuditLog::query()->where('event', 'company.vat_tax.created')->where('company_id', $registered->id)->exists())->toBeTrue();
});

it('creates the VAT row when admin registers an existing company, never twice, and never over the merchant\'s own taxes', function (): void {
    $company = app(CreateCompanyAction::class)->handle(p4CompanyData(null, '7000003'));
    $register = fn (Company $c, ?string $at) => app(UpdateCompanyAction::class)->handle($c->refresh(), UpdateCompanyData::from([
        'compliance' => ['cr_number' => (string) $c->cr_number, 'vat_number' => 'OM551', 'vat_registered_at' => $at],
    ]));

    $register($company, '2026-09-01');
    expect(p4Taxes($company->id))->toHaveCount(1);

    // The merchant deletes it on the Taxes page; re-saving the registration keeps their choice.
    DB::table('pos_taxes')->where('company_id', $company->id)->update(['deleted_at' => now()]);
    $register($company, '2026-09-01');
    expect(p4Taxes($company->id))->toBe([]);

    // Registering again after deregistering brings the deleted VAT row back.
    $register($company, null);
    $register($company, '2026-10-01');
    expect(p4Taxes($company->id))->toBe([['name' => 'VAT', 'name_ar' => 'ضريبة القيمة المضافة', 'rate' => 5.0, 'active' => true]])
        ->and(DB::table('pos_taxes')->where('company_id', $company->id)->count())->toBe(1);

    $own = app(CreateCompanyAction::class)->handle(p4CompanyData(null, '7000004'));
    DB::table('pos_taxes')->insert(['uuid' => (string) Str::uuid(), 'company_id' => $own->id, 'name' => 'Municipality', 'rate_percent' => '2.00',
        'is_active' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $register($own, '2026-10-01');
    expect(p4Taxes($own->id))->toBe([['name' => 'Municipality', 'name_ar' => null, 'rate' => 2.0, 'active' => true]]);
});
