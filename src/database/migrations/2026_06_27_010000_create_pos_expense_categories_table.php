<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Company-level expense categories (v2 #7 — custom expense categories).
 *
 * Replaces the fixed App\Enums\ExpenseCategory set with a per-company,
 * merchant-managed list. pos_expenses.category KEEPS its string `key`
 * value (non-destructive — no FK, no data migration); this table is the
 * per-company allow-list that validation + the POS /device/config bundle
 * read from. Soft-deleted so historical expenses stay resolvable.
 *
 * The up() also BACKFILLS the previous fixed six categories for every
 * existing company so all historical pos_expenses rows remain valid and
 * the merchant RestockAction's auto-logged 'ingredients' expense keeps
 * validating (it was previously a latent cross-app divergence — the
 * merchant accepted 'ingredients' but pos_api/device did not).
 */
return new class extends Migration
{
    /** Previous fixed set: key => [name, name_ar, sort]. */
    private const DEFAULTS = [
        ['key' => 'utilities', 'name' => 'Utilities', 'name_ar' => 'المرافق', 'sort' => 0],
        ['key' => 'supplies', 'name' => 'Supplies', 'name_ar' => 'اللوازم', 'sort' => 1],
        ['key' => 'ingredients', 'name' => 'Ingredients', 'name_ar' => 'المكوّنات', 'sort' => 2],
        ['key' => 'maintenance', 'name' => 'Maintenance', 'name_ar' => 'الصيانة', 'sort' => 3],
        ['key' => 'salaries', 'name' => 'Salaries', 'name_ar' => 'الرواتب', 'sort' => 4],
        ['key' => 'other', 'name' => 'Other', 'name_ar' => 'أخرى', 'sort' => 5],
    ];

    public function up(): void
    {
        Schema::create('pos_expense_categories', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();
            $table->string('name', 64);
            $table->string('name_ar', 64)->nullable();
            // Stable slug stored on pos_expenses.category (the device + merchant
            // submit this key). Generated from the name; unique per company.
            $table->string('key', 32);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'key'], 'pos_expense_categories_company_key_unique');
            $table->index(
                ['company_id', 'is_active', 'sort_order'],
                'pos_expense_categories_company_active_sort_idx',
            );
        });

        $this->backfillExistingCompanies();
    }

    /**
     * Seed the six previous fixed categories for every existing company so
     * historical expenses stay valid. Idempotent (skips a company that
     * already has a matching key). Safe no-op when there are no companies.
     */
    private function backfillExistingCompanies(): void
    {
        if (! Schema::hasTable('pos_companies')) {
            return;
        }

        $now = now();
        foreach (DB::table('pos_companies')->pluck('id') as $companyId) {
            $rows = [];
            foreach (self::DEFAULTS as $d) {
                $exists = DB::table('pos_expense_categories')
                    ->where('company_id', $companyId)
                    ->where('key', $d['key'])
                    ->exists();
                if ($exists) {
                    continue;
                }
                $rows[] = [
                    'uuid' => (string) Str::uuid(),
                    'company_id' => $companyId,
                    'name' => $d['name'],
                    'name_ar' => $d['name_ar'],
                    'key' => $d['key'],
                    'is_active' => true,
                    'sort_order' => $d['sort'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if ($rows !== []) {
                DB::table('pos_expense_categories')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_expense_categories');
    }
};
