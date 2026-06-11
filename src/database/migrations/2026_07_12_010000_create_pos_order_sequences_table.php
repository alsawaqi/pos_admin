<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P-F8 — merchant-defined order numbering: the server-owned counters.
 *
 * One row per numbering SCOPE:
 *   - branch_id NULL  → the company-wide sequence (scope = 'company');
 *     branch_id set   → that branch's own sequence (scope = 'branch').
 *   - seq_date NULL   → a continuous, never-resetting counter;
 *     seq_date set    → that DAY's counter (the merchant turned daily_reset
 *     on — common for call-out numbers that restart each morning).
 *
 * next_number is "the number the NEXT allocation hands out" (starts at 1).
 * pos_api's AllocateOrderNumberAction claims it atomically:
 * insertOrIgnore (INSERT … ON CONFLICT DO NOTHING / INSERT OR IGNORE) to
 * materialise the scope row, then SELECT … FOR UPDATE + increment inside
 * one transaction.
 *
 * UNIQUENESS — the NULL problem: Postgres treats NULLs as distinct in a
 * plain unique constraint, so UNIQUE(company_id, branch_id, seq_date)
 * would happily admit two "company-scope continuous" rows and the
 * insertOrIgnore dedupe would never fire. Instead we create a FUNCTIONAL
 * unique index on (company_id, COALESCE(branch_id, 0),
 * COALESCE(seq_date, '1970-01-01')) — branch id 0 and the epoch date are
 * impossible real values, so the coalesced tuple is unique per scope.
 * Both Postgres (live) and SQLite (test schemas) support expression
 * indexes with this exact shape; on any other driver we fall back to a
 * plain non-unique index and the allocator's transaction still keeps
 * allocation atomic (the unique index is the belt, the row lock the
 * braces).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_order_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();
            // NULL = the company-scope row; set = that branch's row.
            $table->foreignId('branch_id')
                ->nullable()
                ->constrained('pos_branches')
                ->cascadeOnDelete();
            // NULL = continuous counter; set = that day's row (daily reset).
            $table->date('seq_date')->nullable();
            // The number the NEXT allocation returns.
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();

            // Allocator lookup path (company + branch + day equality).
            $table->index(['company_id', 'branch_id', 'seq_date'], 'pos_order_sequences_lookup_idx');
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            // DATE '1970-01-01' keeps the expression unambiguously immutable.
            DB::statement(
                "CREATE UNIQUE INDEX pos_order_sequences_scope_unique ON pos_order_sequences ".
                "(company_id, COALESCE(branch_id, 0), COALESCE(seq_date, DATE '1970-01-01'))"
            );
        } elseif ($driver === 'sqlite') {
            // SQLite has no typed literals; the bare string compares fine
            // against its TEXT-affinity date column.
            DB::statement(
                'CREATE UNIQUE INDEX pos_order_sequences_scope_unique ON pos_order_sequences '.
                "(company_id, COALESCE(branch_id, 0), COALESCE(seq_date, '1970-01-01'))"
            );
        }
        // Other drivers: no portable functional unique index — the plain
        // lookup index above stands and the allocator's transactional
        // lock-and-increment alone enforces atomicity.
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_order_sequences');
    }
};
