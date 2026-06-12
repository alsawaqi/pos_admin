<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P-G6 — messaging: two one-way channels, four tables.
 *
 * CHANNEL 1 — portal → POS devices (staff announcements):
 *
 *   pos_staff_messages       composed in the merchant portal
 *                            (messages.send-gated), targeted at ONE staff
 *                            member, ONE branch, or the whole company
 *                            (target_type = staff | branch | company).
 *                            Delivered to devices through the existing
 *                            /device/config slice (offline catch-up) plus a
 *                            best-effort Reverb nudge on the branch
 *                            channel(s) for live arrival. created_by_name
 *                            is DENORMALIZED (snapshot of the sender's
 *                            portal-user name) because pos_api's test
 *                            schema carries no pos_users table — devices
 *                            render the sender without a join. Soft delete
 *                            = portal retraction; the id surfaces in the
 *                            config delta's deleted map so devices purge.
 *
 *   pos_staff_message_reads  read receipts — "sent is not the same as
 *                            seen". One row per (message, staff); the
 *                            device that marked it is kept for the audit
 *                            trail. Writing a receipt touch()es the parent
 *                            message so the updated read-set resurfaces in
 *                            other devices' config deltas (the
 *                            DeviceCustomersController plate precedent).
 *
 * CHANNEL 2 — portal → portal (internal inbox):
 *
 *   pos_portal_messages      sent by any portal user to ONE user, a ROLE
 *                            group (spatie role name under the company
 *                            team), or everyone scoped to a BRANCH
 *                            (target_type = user | role | branch — the
 *                            branch case resolves against
 *                            pos_users.branch_scope_json at read time, so
 *                            users added later still see it).
 *
 *   pos_portal_message_reads read status per (message, user).
 *
 * Recipients are resolved AT READ TIME from the target columns — there is
 * deliberately no materialized recipients table (new staff/users join the
 * audience automatically; receipts are the per-person state). Both
 * channels are one-way v1 (replies are a possible later extension).
 * Written exclusively by pos_merchant; pos_api reads channel 1 and writes
 * its receipts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_staff_messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();

            // staff | branch | company (app enums gate inputs).
            $table->string('target_type', 16);
            $table->foreignId('target_branch_id')
                ->nullable()
                ->constrained('pos_branches')
                ->cascadeOnDelete();
            $table->foreignId('target_staff_id')
                ->nullable()
                ->constrained('pos_staff')
                ->cascadeOnDelete();

            $table->string('title')->nullable();
            $table->text('body');

            // Sender: FK for the portal, name snapshot for the devices.
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('pos_users')
                ->nullOnDelete();
            $table->string('created_by_name')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Hot paths: the device config slice (company + recency window)
            // and branch-targeted lookups.
            $table->index(['company_id', 'created_at'], 'pos_staff_messages_company_created_idx');
            $table->index(['target_branch_id'], 'pos_staff_messages_branch_idx');
        });

        Schema::create('pos_staff_message_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_message_id')
                ->constrained('pos_staff_messages')
                ->cascadeOnDelete();
            $table->foreignId('staff_id')
                ->constrained('pos_staff')
                ->cascadeOnDelete();
            // Which till marked it (audit; survives device deletion).
            $table->foreignId('device_id')
                ->nullable()
                ->constrained('pos_devices')
                ->nullOnDelete();
            $table->timestamp('read_at');
            $table->timestamps();

            $table->unique(['staff_message_id', 'staff_id'], 'pos_staff_message_reads_unique');
        });

        Schema::create('pos_portal_messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();
            $table->foreignId('sender_user_id')
                ->nullable()
                ->constrained('pos_users')
                ->nullOnDelete();

            // user | role | branch (app enums gate inputs).
            $table->string('target_type', 16);
            $table->foreignId('target_user_id')
                ->nullable()
                ->constrained('pos_users')
                ->cascadeOnDelete();
            // Spatie role NAME under the company team (e.g.
            // merchant_manager) — resolved at read time.
            $table->string('target_role', 64)->nullable();
            $table->foreignId('target_branch_id')
                ->nullable()
                ->constrained('pos_branches')
                ->cascadeOnDelete();

            $table->string('subject')->nullable();
            $table->text('body');

            $table->timestamps();

            $table->index(['company_id', 'created_at'], 'pos_portal_messages_company_created_idx');
        });

        Schema::create('pos_portal_message_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('portal_message_id')
                ->constrained('pos_portal_messages')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('pos_users')
                ->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->timestamps();

            $table->unique(['portal_message_id', 'user_id'], 'pos_portal_message_reads_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_portal_message_reads');
        Schema::dropIfExists('pos_portal_messages');
        Schema::dropIfExists('pos_staff_message_reads');
        Schema::dropIfExists('pos_staff_messages');
    }
};
