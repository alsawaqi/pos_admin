<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends `pos_users` with the fields the admin needs to invite and
 * manage MERCHANT PORTAL users (blueprint §4.5).
 *
 * Why we reuse pos_users instead of creating a new table:
 *   The schema was originally designed to host both audiences — the
 *   user_type column defaults to `merchant` and the company_id FK
 *   is nullable so admin users can leave it blank. Splitting into
 *   two tables would duplicate auth + audit infrastructure for no
 *   real isolation benefit (both audiences live behind separate
 *   guards anyway). When pos_merchant ships, it will read this same
 *   table and filter by user_type='merchant' + company_id.
 *
 * Added columns:
 *
 *   setup_token_hash      — SHA-256 hash of the one-time token sent
 *                           in the welcome email. We store the hash
 *                           (never the raw token) so a DB dump leak
 *                           cannot let an attacker complete account
 *                           setup. The raw token only ever exists in
 *                           the email body.
 *
 *   setup_token_expires_at — Wall-clock expiry for the token. The
 *                           setup endpoint refuses an expired token
 *                           and the admin can re-issue via "Resend
 *                           invite".
 *
 *   branch_scope_json     — Array of branch ids the user can act on.
 *                           NULL means "all branches" (the implicit
 *                           default for the merchant Super Admin).
 *                           Empty array means "none" (effectively
 *                           locked out). Specific subset = restricted
 *                           access per blueprint §5.1.2.
 *
 *   invited_at            — When the welcome email was first sent.
 *                           Lets the admin distinguish "invited but
 *                           hasn't logged in yet" from "active user
 *                           who hasn't logged in lately".
 *
 *   invited_by_admin_id   — Which platform admin clicked "Invite"
 *                           on the merchant detail page. References
 *                           pos_users itself (self FK).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_users', function (Blueprint $table): void {
            // 64 hex chars = SHA-256 output. Unique so the setup
            // endpoint can look the user up by their token hash.
            $table->string('setup_token_hash', 64)->nullable()->unique('pos_users_setup_token_hash_unique')->after('password');
            $table->timestamp('setup_token_expires_at')->nullable()->after('setup_token_hash');

            // JSON list of branch_id ints OR null (= all branches).
            $table->json('branch_scope_json')->nullable()->after('user_type');

            // Invite metadata. invited_by_admin_id self-references
            // pos_users; nullOnDelete because removing the admin
            // shouldn't orphan the merchant they invited.
            $table->timestamp('invited_at')->nullable()->after('branch_scope_json');
            $table->foreignId('invited_by_admin_id')->nullable()->after('invited_at')
                ->constrained('pos_users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pos_users', function (Blueprint $table): void {
            // Drop the FK constraint first, then the column.
            $table->dropConstrainedForeignId('invited_by_admin_id');

            $table->dropUnique('pos_users_setup_token_hash_unique');

            $table->dropColumn([
                'setup_token_hash',
                'setup_token_expires_at',
                'branch_scope_json',
                'invited_at',
            ]);
        });
    }
};
