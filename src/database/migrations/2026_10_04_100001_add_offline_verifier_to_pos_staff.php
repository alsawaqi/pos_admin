<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH-P5 data contract — the offline approval verifier of a staff PIN
 * (owner decision 7: a manager approves on the device by typing their PIN,
 * offline too).
 *
 *   pin_offline_key         K = PBKDF2-HMAC-SHA256(PIN, salt, iterations,
 *                           32 bytes), stored as 64 lowercase hex characters.
 *                           The server proves an approval with it
 *                           (HMAC-SHA256(K, canonical)). Never sent to a
 *                           device, never logged.
 *   pin_offline_salt        16 random bytes as 32 hex characters.
 *   pin_offline_iterations  the PBKDF2 iteration count used for this row
 *                           (config pos.approver_kdf_iterations when made), so
 *                           a later change of the setting stays safe.
 *
 * Written whenever a server holds the plaintext PIN: pos_merchant at PIN mint
 * and reset, pos_api at a successful staff login or manager-PIN check when the
 * row has none yet. All three stay NULL until then. Hidden on every model.
 * pos_api and pos_merchant mirror the columns in their test schemas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_staff', function (Blueprint $table): void {
            $table->text('pin_offline_key')->nullable();
            $table->string('pin_offline_salt', 64)->nullable();
            $table->integer('pin_offline_iterations')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pos_staff', function (Blueprint $table): void {
            $table->dropColumn(['pin_offline_key', 'pin_offline_salt', 'pin_offline_iterations']);
        });
    }
};
