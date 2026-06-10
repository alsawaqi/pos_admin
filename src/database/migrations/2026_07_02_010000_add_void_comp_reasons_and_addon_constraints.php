<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Phase B (Additions doc §1.2) — restaurant controls schema:
 * void reason codes, comp reasons + order comps, and modifier-group
 * selection constraints.
 *
 * 1. pos_void_reasons — company-scoped master list every void must
 *    pick from (same shape/lifecycle as pos_expense_categories).
 *      affects_inventory — TRUE = the food was actually made
 *        (Quality Issue): the recipe ingredients STAY consumed when
 *        the order is voided, and the loss surfaces in the Loss/
 *        Waste voids breakdown. FALSE = never prepared (Wrong Order
 *        Entry): the void restores inventory (the pre-Phase-B
 *        behaviour). The doc's separate affects_cogs flag is folded
 *        in: in this system COGS comes from recipe snapshots, so
 *        "ingredients consumed" and "cost incurred" are the same
 *        fact.
 *      requires_manager — advisory per-code flag layered on top of
 *        the existing company-wide order_cancel_positions policy.
 *
 * 2. pos_comp_reasons — manager-approved write-off reasons (Long
 *    Wait, Staff Meal…). max_amount caps a single comp (NULL = no
 *    cap). Manager approval is ALWAYS required for comps (doc),
 *    hence no flag.
 *
 * 3. pos_order_comps — one row per comp granted (mirrors
 *    pos_order_discounts): order_item_id NULL = whole-order comp.
 *    Inventory deducts AS IF SOLD (the food went to the customer);
 *    the monetary value reduces what the customer pays but is
 *    reported as comp value, never confused with a discount or a
 *    void. pos_orders.comp_total caches the per-order sum the same
 *    way discount_total does.
 *
 * 4. pos_orders gains void_reason_id + void_reason_label (snapshot
 *    that survives the master row's rename/delete).
 *
 * 5. Modifier constraints (doc "Modifier Groups with Constraints"):
 *    pos_addon_groups.min_selections / max_selections (NULL = no
 *    bound; min >= 1 makes the group REQUIRED at the POS),
 *    pos_addons.is_default (pre-selected in the customize sheet),
 *    pos_addon_group_categories — category-level binding (a group
 *    attached to a category applies to all its products; the
 *    device unions category + product bindings). The doc's
 *    per-option recipe override is the EXISTING addon ingredient
 *    mapping — no new column needed.
 *
 * The up() backfills the doc's default seeded codes for every
 * existing company (idempotent), mirroring the expense-categories
 * migration.
 */
return new class extends Migration
{
    /** Additions doc default void codes: code => [name, name_ar, affects_inventory, requires_manager]. */
    private const VOID_DEFAULTS = [
        ['code' => 'change_of_mind', 'name' => 'Customer Change of Mind', 'name_ar' => 'تغيير رأي الزبون', 'inv' => true, 'mgr' => false],
        ['code' => 'wrong_order_entry', 'name' => 'Wrong Order Entry', 'name_ar' => 'إدخال طلب خاطئ', 'inv' => false, 'mgr' => false],
        ['code' => 'wrong_item_prepared', 'name' => 'Wrong Item Prepared', 'name_ar' => 'تحضير صنف خاطئ', 'inv' => true, 'mgr' => true],
        ['code' => 'quality_issue', 'name' => 'Quality Issue', 'name_ar' => 'مشكلة جودة', 'inv' => true, 'mgr' => true],
        ['code' => 'allergen_dietary', 'name' => 'Allergen / Dietary', 'name_ar' => 'حساسية / نظام غذائي', 'inv' => true, 'mgr' => true],
        ['code' => 'overcooked_undercooked', 'name' => 'Overcooked / Undercooked', 'name_ar' => 'إفراط / نقص في الطهي', 'inv' => true, 'mgr' => true],
        ['code' => 'out_of_stock', 'name' => 'Out of Stock', 'name_ar' => 'نفاد المخزون', 'inv' => false, 'mgr' => true],
        ['code' => 'training', 'name' => 'Training', 'name_ar' => 'تدريب', 'inv' => false, 'mgr' => false],
        ['code' => 'manager_comp', 'name' => 'Manager Comp', 'name_ar' => 'ضيافة الإدارة', 'inv' => true, 'mgr' => true],
    ];

    /** Additions doc default comp reasons: code => [name, name_ar]. */
    private const COMP_DEFAULTS = [
        ['code' => 'long_wait', 'name' => 'Long Wait', 'name_ar' => 'انتظار طويل'],
        ['code' => 'service_recovery', 'name' => 'Service Recovery', 'name_ar' => 'تعويض عن الخدمة'],
        ['code' => 'vip_hospitality', 'name' => 'VIP Hospitality', 'name_ar' => 'ضيافة كبار الزوار'],
        ['code' => 'staff_meal', 'name' => 'Staff Meal', 'name_ar' => 'وجبة موظفين'],
        ['code' => 'tasting', 'name' => 'Tasting', 'name_ar' => 'تذوق'],
        ['code' => 'owner_discretion', 'name' => 'Owner Discretion', 'name_ar' => 'تقدير المالك'],
    ];

    public function up(): void
    {
        Schema::create('pos_void_reasons', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();
            // Stable slug snapshotted onto voided orders.
            $table->string('code', 32);
            $table->string('name', 64);
            $table->string('name_ar', 64)->nullable();
            $table->boolean('affects_inventory')->default(false);
            $table->boolean('requires_manager')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'code'], 'pos_void_reasons_company_code_unique');
            $table->index(['company_id', 'is_active', 'sort_order'], 'pos_void_reasons_company_active_sort_idx');
        });

        Schema::create('pos_comp_reasons', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name', 64);
            $table->string('name_ar', 64)->nullable();
            // Cap for a SINGLE comp under this reason (OMR). NULL = no cap.
            $table->decimal('max_amount', 12, 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'code'], 'pos_comp_reasons_company_code_unique');
            $table->index(['company_id', 'is_active', 'sort_order'], 'pos_comp_reasons_company_active_sort_idx');
        });

        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->foreignId('void_reason_id')
                ->nullable()
                ->after('status')
                ->constrained('pos_void_reasons')
                ->nullOnDelete();
            // Snapshot — survives the master row's rename / delete.
            $table->string('void_reason_label', 64)->nullable()->after('void_reason_id');
            $table->decimal('comp_total', 12, 3)->default(0)->after('discount_total');
        });

        Schema::create('pos_order_comps', function (Blueprint $table): void {
            $table->id();
            // Denormalised tenant + scope, mirroring pos_order_discounts.
            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();
            $table->foreignId('branch_id')
                ->constrained('pos_branches')
                ->cascadeOnDelete();
            $table->foreignId('order_id')
                ->constrained('pos_orders')
                ->cascadeOnDelete();
            // NULL = whole-order comp; set = one specific line.
            $table->foreignId('order_item_id')
                ->nullable()
                ->constrained('pos_order_items')
                ->nullOnDelete();
            $table->foreignId('comp_reason_id')
                ->nullable()
                ->constrained('pos_comp_reasons')
                ->nullOnDelete();
            // Reason snapshot — history survives master edits.
            $table->string('reason_code_snapshot', 32);
            $table->string('reason_name_snapshot', 64);
            // OMR value written off (decimal:3 baisas precision).
            $table->decimal('amount', 12, 3)->default(0);
            // The manager who approved (comps ALWAYS need approval).
            $table->foreignId('approved_by_pos_staff_id')
                ->nullable()
                ->constrained('pos_staff')
                ->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'comp_reason_id'], 'pos_order_comps_company_reason_idx');
            $table->index(['order_id'], 'pos_order_comps_order_idx');
            $table->index(['branch_id', 'applied_at'], 'pos_order_comps_branch_applied_idx');
        });

        Schema::table('pos_addon_groups', function (Blueprint $table): void {
            // NULL = unbounded. min >= 1 makes the group REQUIRED:
            // the POS blocks add-to-cart until satisfied.
            $table->unsignedSmallInteger('min_selections')->nullable()->after('selection_mode');
            $table->unsignedSmallInteger('max_selections')->nullable()->after('min_selections');
        });

        Schema::table('pos_addons', function (Blueprint $table): void {
            $table->boolean('is_default')->default(false)->after('price_delta');
        });

        Schema::create('pos_addon_group_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('add_on_group_id')
                ->constrained('pos_addon_groups')
                ->cascadeOnDelete();
            $table->foreignId('category_id')
                ->constrained('pos_product_categories')
                ->cascadeOnDelete();
            $table->unique(['add_on_group_id', 'category_id'], 'pos_addon_group_categories_unique');
        });

        $this->backfillExistingCompanies();
    }

    /**
     * Seed the doc's default void + comp reason codes for every existing
     * company. Idempotent (skips codes a company already has).
     */
    private function backfillExistingCompanies(): void
    {
        if (! Schema::hasTable('pos_companies')) {
            return;
        }

        $now = now();
        foreach (DB::table('pos_companies')->pluck('id') as $companyId) {
            $voidRows = [];
            foreach (self::VOID_DEFAULTS as $i => $d) {
                $exists = DB::table('pos_void_reasons')
                    ->where('company_id', $companyId)
                    ->where('code', $d['code'])
                    ->exists();
                if ($exists) {
                    continue;
                }
                $voidRows[] = [
                    'uuid' => (string) Str::uuid(),
                    'company_id' => $companyId,
                    'code' => $d['code'],
                    'name' => $d['name'],
                    'name_ar' => $d['name_ar'],
                    'affects_inventory' => $d['inv'],
                    'requires_manager' => $d['mgr'],
                    'is_active' => true,
                    'sort_order' => $i,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if ($voidRows !== []) {
                DB::table('pos_void_reasons')->insert($voidRows);
            }

            $compRows = [];
            foreach (self::COMP_DEFAULTS as $i => $d) {
                $exists = DB::table('pos_comp_reasons')
                    ->where('company_id', $companyId)
                    ->where('code', $d['code'])
                    ->exists();
                if ($exists) {
                    continue;
                }
                $compRows[] = [
                    'uuid' => (string) Str::uuid(),
                    'company_id' => $companyId,
                    'code' => $d['code'],
                    'name' => $d['name'],
                    'name_ar' => $d['name_ar'],
                    'max_amount' => null,
                    'is_active' => true,
                    'sort_order' => $i,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if ($compRows !== []) {
                DB::table('pos_comp_reasons')->insert($compRows);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_addon_group_categories');
        Schema::table('pos_addons', function (Blueprint $table): void {
            $table->dropColumn('is_default');
        });
        Schema::table('pos_addon_groups', function (Blueprint $table): void {
            $table->dropColumn(['min_selections', 'max_selections']);
        });
        Schema::dropIfExists('pos_order_comps');
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('void_reason_id');
            $table->dropColumn(['void_reason_label', 'comp_total']);
        });
        Schema::dropIfExists('pos_comp_reasons');
        Schema::dropIfExists('pos_void_reasons');
    }
};
