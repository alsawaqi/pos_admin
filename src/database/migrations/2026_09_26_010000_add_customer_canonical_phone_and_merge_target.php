<?php

declare(strict_types=1);

use App\Support\CanonicalPhone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_customers', function (Blueprint $table): void {
            $table->string('phone_canonical', 32)->nullable();
            $table->foreignId('merged_into_customer_id')->nullable()->index()->constrained('pos_customers');
            $table->index(['company_id', 'phone_canonical'], 'pos_customers_company_canonical_idx');
        });
        DB::table('pos_customers')->orderBy('id')->chunkById(500, function ($customers): void {
            foreach ($customers as $customer) {
                DB::table('pos_customers')->where('id', $customer->id)->update([
                    'phone_canonical' => CanonicalPhone::of($customer->phone),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_customers', function (Blueprint $table): void {
            $table->dropForeign(['merged_into_customer_id']);
            $table->dropIndex(['merged_into_customer_id']);
            $table->dropIndex('pos_customers_company_canonical_idx');
            $table->dropColumn(['phone_canonical', 'merged_into_customer_id']);
        });
    }
};
