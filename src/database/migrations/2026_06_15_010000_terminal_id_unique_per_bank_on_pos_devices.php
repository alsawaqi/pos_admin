<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Terminal IDs are unique PER BANK, not globally.
 *
 * A bank-issued terminal_id is only unique within the issuing bank's estate —
 * two different acquiring banks can each hand out terminal "0001". The
 * original 2026_05_25_020000 migration made terminal_id globally unique
 * (constraint `pos_devices_terminal_id_unique`), which wrongly rejects a
 * legitimate (bank B, "0001") once (bank A, "0001") exists.
 *
 * This swaps the single-column unique for a composite unique on
 * (bank_id, terminal_id). Both columns are nullable — a freshly registered
 * device has NEITHER until it is ASSIGNED to a merchant (terminal_id + bank
 * moved from registration to assignment, this same sprint) — and both SQLite
 * and Postgres treat NULLs as distinct in a unique index, so any number of
 * unassigned devices (NULL bank, NULL terminal) coexist freely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_devices', function (Blueprint $table): void {
            // Drop the global unique from 2026_05_25_020000 by its explicit name.
            $table->dropUnique('pos_devices_terminal_id_unique');
            // Same terminal_id allowed across different banks; never twice
            // within one bank.
            $table->unique(['bank_id', 'terminal_id'], 'pos_devices_bank_terminal_unique');
        });
    }

    public function down(): void
    {
        Schema::table('pos_devices', function (Blueprint $table): void {
            $table->dropUnique('pos_devices_bank_terminal_unique');
            $table->unique('terminal_id', 'pos_devices_terminal_id_unique');
        });
    }
};
