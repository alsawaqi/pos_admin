<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the single-owner columns on `pos_companies` with a proper
 * one-to-many `pos_company_owners` table.
 *
 * Why:
 *   The original Oman-compliance migration stuffed the owner identity
 *   into a flat set of columns (owner_full_name_en, owner_civil_id,
 *   etc.). In practice many merchant CRs list more than one owner —
 *   partnerships, family-run cafés, holding companies — so we need a
 *   real child table.
 *
 * Strategy:
 *   1. Create the new `pos_company_owners` table.
 *   2. Copy each existing company's owner_* fields into a single row
 *      flagged is_primary = true. Skip companies with no owner name.
 *   3. Drop the legacy owner_* columns from `pos_companies`.
 *
 * Down migration restores the columns and copies the primary owner
 * back so we can run forward → backward → forward cleanly.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- 1. Create the new child table -----------------------------
        Schema::create('pos_company_owners', function (Blueprint $table): void {
            $table->id();

            // Cascade so deleting a company removes its owner rows.
            // The Company itself uses softDeletes, so this only fires
            // on a real hard delete (which the admin UI does not
            // expose — kept here purely for FK consistency).
            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();

            // Bilingual name. EN required, AR optional.
            $table->string('full_name_en');
            $table->string('full_name_ar')->nullable();

            // Government identifier + ISO-2 nationality.
            $table->string('civil_id')->nullable();
            $table->string('nationality', 2)->nullable();

            // Contact details. Encrypted at rest happens at the model
            // cast layer — see blueprint §9.13.2.
            $table->string('phone')->nullable();
            $table->string('email')->nullable();

            // Exactly ONE owner per company should carry is_primary
            // = true. Enforced at the application layer (the
            // FormRequest and Action both check). A partial unique
            // index is not used because Postgres syntax for it is
            // engine-specific and we'd rather keep the migration
            // portable to MySQL/SQLite for tests.
            $table->boolean('is_primary')->default(false);

            // Optional ownership percentage for partnerships
            // (0.00–100.00). Sum across owners is NOT enforced — a
            // merchant may legitimately leave this blank entirely.
            $table->decimal('ownership_percentage', 5, 2)->nullable();

            $table->timestamps();

            // Queries: "give me the primary owner of company X" hits
            // this composite index directly.
            $table->index(['company_id', 'is_primary'], 'pos_company_owners_company_primary_index');
        });

        // --- 2. Migrate existing single-owner rows ---------------------
        // Only carry over companies that actually have a name set —
        // empty/legacy rows are skipped so the new table doesn't get
        // junk seed data.
        DB::table('pos_companies')
            ->whereNotNull('owner_full_name_en')
            ->orderBy('id')
            ->chunkById(200, function ($companies): void {
                foreach ($companies as $company) {
                    DB::table('pos_company_owners')->insert([
                        'company_id' => $company->id,
                        'full_name_en' => $company->owner_full_name_en,
                        'full_name_ar' => $company->owner_full_name_ar,
                        'civil_id' => $company->owner_civil_id,
                        'nationality' => $company->owner_nationality,
                        'phone' => $company->owner_phone,
                        'email' => $company->owner_email,
                        'is_primary' => true,
                        'ownership_percentage' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });

        // --- 3. Drop the legacy owner_* columns -----------------------
        Schema::table('pos_companies', function (Blueprint $table): void {
            $table->dropColumn([
                'owner_full_name_en',
                'owner_full_name_ar',
                'owner_civil_id',
                'owner_nationality',
                'owner_phone',
                'owner_email',
            ]);
        });
    }

    public function down(): void
    {
        // 1. Re-add the legacy columns to pos_companies.
        Schema::table('pos_companies', function (Blueprint $table): void {
            $table->string('owner_full_name_en')->nullable()->after('contact_email');
            $table->string('owner_full_name_ar')->nullable()->after('owner_full_name_en');
            $table->string('owner_civil_id')->nullable()->after('owner_full_name_ar');
            $table->string('owner_nationality', 2)->nullable()->after('owner_civil_id');
            $table->string('owner_phone')->nullable()->after('owner_nationality');
            $table->string('owner_email')->nullable()->after('owner_phone');
        });

        // 2. Copy the primary owner from each company back to the
        //    legacy columns. Non-primary owners are intentionally
        //    discarded — the legacy schema only supported one.
        DB::table('pos_company_owners')
            ->where('is_primary', true)
            ->orderBy('company_id')
            ->chunkById(200, function ($owners): void {
                foreach ($owners as $owner) {
                    DB::table('pos_companies')
                        ->where('id', $owner->company_id)
                        ->update([
                            'owner_full_name_en' => $owner->full_name_en,
                            'owner_full_name_ar' => $owner->full_name_ar,
                            'owner_civil_id' => $owner->civil_id,
                            'owner_nationality' => $owner->nationality,
                            'owner_phone' => $owner->phone,
                            'owner_email' => $owner->email,
                        ]);
                }
            });

        // 3. Drop the new table.
        Schema::dropIfExists('pos_company_owners');
    }
};
