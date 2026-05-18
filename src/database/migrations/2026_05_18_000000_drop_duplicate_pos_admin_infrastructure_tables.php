<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the pos_admin_* infrastructure tables that overlap with the
 * charity database's stock Laravel tables.
 *
 * Background: this project originally created its own prefixed copies
 * of every Laravel framework table (sessions, cache, jobs, password
 * reset tokens, personal access tokens). After confirming the charity
 * database that we share already provides each of these with the
 * canonical Laravel names, we point our config back at the defaults
 * and let this migration sweep the now-unused duplicates out of the
 * database so future `migrate:fresh` / `migrate:status` runs are
 * accurate.
 *
 * Down: intentionally a no-op. The originals (e.g. pos_admin_sessions)
 * are no longer created anywhere in this codebase, so there is no
 * sensible way to "restore" them by running the reverse — and trying
 * would conflict with the charity-owned tables. If you actually need
 * the old tables back, fork them with a different name in a fresh
 * migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('pos_admin_sessions');
        Schema::dropIfExists('pos_admin_password_reset_tokens');
        Schema::dropIfExists('pos_admin_cache_locks');
        Schema::dropIfExists('pos_admin_cache');
        Schema::dropIfExists('pos_admin_failed_jobs');
        Schema::dropIfExists('pos_admin_job_batches');
        Schema::dropIfExists('pos_admin_jobs');
        Schema::dropIfExists('pos_admin_personal_access_tokens');
    }

    public function down(): void
    {
        // Intentionally empty — see class doc-block.
    }
};
