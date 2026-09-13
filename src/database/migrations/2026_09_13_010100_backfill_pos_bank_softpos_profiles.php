<?php

declare(strict_types=1);

use App\Enums\SoftPosProvider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('banks')->orderBy('id')->chunkById(100, function ($banks): void {
            foreach ($banks as $bank) {
                if (DB::table('pos_bank_softpos_profiles')->where('bank_id', $bank->id)->exists()) {
                    continue;
                }

                $provider = preg_match('/dhofar/i', $bank->name) === 1
                    ? SoftPosProvider::Dhofar : SoftPosProvider::None;
                DB::table('pos_bank_softpos_profiles')->insert([
                    'bank_id' => $bank->id,
                    'softpos_provider' => $provider->value,
                    'softpos_package' => $provider->package(),
                    'currency_code' => '0512',
                    'refund_needs_transaction_id' => $provider->refundNeedsTransactionId(),
                    'void_needs_session_id' => $provider->voidNeedsSessionId(),
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                Log::info('PAY-002 bank profile backfilled', [
                    'bank_id' => $bank->id,
                    'softpos_provider' => $provider->value,
                ]);
            }
        });
    }

    public function down(): void
    {
        // Profiles may have been edited or referenced since backfill. Preserve them.
    }
};
