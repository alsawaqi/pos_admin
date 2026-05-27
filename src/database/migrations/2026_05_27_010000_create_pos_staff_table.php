<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates `pos_staff` — the PIN-authenticated workforce that
 * actually USES the Android POS device (cashiers, waiters,
 * kitchen, supervisors, on-floor managers).
 *
 * Why a brand-new table instead of squeezing into pos_users:
 *
 *   - Auth surface is different: pos_users gets logged into via
 *     email + bcrypt password (admin portal + merchant portal).
 *     pos_staff gets logged into via numeric PIN on a device that
 *     the merchant unlocks once per shift. Different identifier
 *     uniqueness rules (PIN per company, not globally unique
 *     email), different validation (6-digit numeric, not RFC
 *     5322), different mfa story (none here, the device IS the
 *     second factor).
 *
 *   - UserType enum has been hardened to exactly two values
 *     (platform_admin | merchant) by AuthenticatedSessionController
 *     in both apps. Adding a third value would explode 20+ call
 *     sites and risk a PIN-only row satisfying Auth::attempt()
 *     against a leaked password column. Separate table = separate
 *     blast radius.
 *
 *   - pos_users.email is uniquely indexed + required for portal
 *     login. POS staff have no email; either we'd nullify that
 *     index (breaks portal login uniqueness) or generate fake
 *     emails (audit-log poison). Fresh table = neither.
 *
 *   - PII at rest rules (blueprint §9.13.2) already flagged
 *     pos_staff.pin_hash + pos_staff.phone as encrypted columns
 *     in the placeholder comment of
 *     2026_05_26_030000_widen_pii_columns_for_encryption — this
 *     migration delivers on that promise.
 *
 * Soft deletes are on (deleted_at) so terminated staff rows
 * remain reachable for order-history joins (`orders.created_by_staff_id`
 * → pos_staff.id) without us losing accountability. Re-hiring is
 * supported by clearing both `deleted_at` AND `status` ←
 * 'terminated'. PIN must be reset on re-hire (server enforces).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_staff', function (Blueprint $table): void {
            $table->id();

            // UUID for stable URL references — merchants might
            // reorder / rehire staff frequently and we don't want
            // /pos-staff/12 turning into a different person after
            // a hire-cycle.
            $table->uuid('uuid')->unique();

            // Always scoped to a company. Cascade-delete because
            // tearing down a company is rare and means we want
            // every related row gone (orders/staff/devices all go
            // together).
            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();

            // Singular branch assignment — a staff member works at
            // one location. Moves between branches are an explicit
            // update (UpdatePosStaffAction tracks the diff in audit
            // log). Cascade-delete because a branch closure should
            // sweep its staff off the roster; the merchant can
            // re-create them at a new branch on the same hire date.
            $table->foreignId('branch_id')
                ->constrained('pos_branches')
                ->cascadeOnDelete();

            // ---- Identity ----------------------------------------
            $table->string('name');

            // PII: TEXT so the Laravel `encrypted` cast's
            // ciphertext (~3× plaintext) always fits. Same shape
            // as pos_users.phone — see widen_pii_columns_for_encryption.
            $table->text('phone')->nullable();

            // Optional internal identifier (badge number, HR id).
            // Unique within a company when present so two staff at
            // the same merchant can't share a code. The partial
            // unique index is added at the end of this method.
            $table->string('staff_code', 64)->nullable();

            // ---- Authentication ---------------------------------
            // Bcrypt hash of the 6-digit numeric PIN. Bcrypt-not-
            // encrypted intentionally: PINs are credentials, must
            // be one-way; the device sends the PIN and the server
            // does Hash::check(). Per-row salt comes free from
            // bcrypt — different staff at the same company who
            // happen to pick the same PIN produce different hashes
            // (defeats hash collision lookup attacks).
            //
            // We also constrain at the application layer to one
            // PIN per company (two staff cannot share a PIN, even
            // across branches) so the device's "pick a staff by
            // typing the PIN" UX works without ambiguity. The
            // constraint is enforced inside CreatePosStaffAction
            // by re-generating until unique — we cannot put a DB
            // UNIQUE on the hash directly because that would
            // permit two merchants to never share a hash and
            // would also leak info (one merchant's collision tells
            // us another merchant has the same PIN).
            $table->string('pin_hash');

            // ---- Role + lifecycle -------------------------------
            // Enum-as-string. Values defined in App\Enums\StaffPosition:
            //   cashier, waiter, kitchen, manager, supervisor
            $table->string('position', 32)->index();

            // Lifecycle states: active (working), suspended
            // (temporary block — pay disputes, investigation),
            // terminated (employment ended; soft-deleted at same
            // moment via deleted_at).
            $table->string('status', 32)->default('active')->index();

            $table->date('hired_at')->nullable();
            $table->timestamp('terminated_at')->nullable();
            $table->timestamp('last_login_at')->nullable();

            // Audit trail — which portal user enrolled this staff.
            // SET NULL on delete so a removed portal user doesn't
            // cascade-wipe their hires. The audit log still has
            // the original actor_user_id captured at create time.
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('pos_users')
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // ---- Indexes ----------------------------------------
            // Quick "who's working this branch?" lookups for the
            // POS device's staff-picker UI.
            $table->index(['branch_id', 'status'], 'pos_staff_branch_status_idx');

            // Quick "who can clock in at this company?" lookups
            // for the merchant portal's staff list.
            $table->index(['company_id', 'status'], 'pos_staff_company_status_idx');
        });

        // Postgres partial unique — staff_code unique per company
        // ONLY when set. Lets multiple staff have NULL staff_code
        // without colliding. SQLite (test layer) silently accepts
        // multiple NULLs in a normal UNIQUE so we keep one syntax.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement(
                'CREATE UNIQUE INDEX pos_staff_company_code_unique
                 ON pos_staff (company_id, staff_code)
                 WHERE staff_code IS NOT NULL AND deleted_at IS NULL'
            );
        } else {
            Schema::table('pos_staff', function (Blueprint $table): void {
                $table->unique(['company_id', 'staff_code'], 'pos_staff_company_code_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_staff');
    }
};
