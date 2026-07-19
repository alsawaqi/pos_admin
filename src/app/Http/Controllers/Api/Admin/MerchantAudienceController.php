<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Marketing #46 — the per-merchant audience-measurement consent.
 *
 *   GET /admin/api/v1/merchants/{merchant:uuid}/audience-measurement
 *   PUT /admin/api/v1/merchants/{merchant:uuid}/audience-measurement
 *
 * Writes the `audience_measurement` key of pos_company_settings (shared
 * charity_db; table owned by pos_merchant). pos_api serves the flag to the
 * merchant's devices via /device/config meta — true = the customer-screen
 * camera may count ad viewers (anonymous aggregates only), anything else =
 * the camera stays off. Default OFF; this toggle supersedes the
 * device-local settings switch.
 */
class MerchantAudienceController extends Controller
{
    private const KEY = 'audience_measurement';

    public function show(Company $merchant): JsonResponse
    {
        $this->authorize('view', $merchant);

        return response()->json(['data' => ['enabled' => $this->enabled($merchant)]]);
    }

    public function update(Request $request, Company $merchant): JsonResponse
    {
        $this->authorize('update', $merchant);

        $validated = $request->validate(['enabled' => ['required', 'boolean']]);
        $enabled = (bool) $validated['enabled'];

        $exists = DB::table('pos_company_settings')
            ->where('company_id', $merchant->id)
            ->where('key', self::KEY)
            ->exists();

        if ($exists) {
            DB::table('pos_company_settings')
                ->where('company_id', $merchant->id)
                ->where('key', self::KEY)
                ->update(['value' => json_encode($enabled), 'updated_at' => now()]);
        } else {
            DB::table('pos_company_settings')->insert([
                'company_id' => $merchant->id,
                'key' => self::KEY,
                'value' => json_encode($enabled),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json(['data' => ['enabled' => $enabled]]);
    }

    private function enabled(Company $merchant): bool
    {
        $raw = DB::table('pos_company_settings')
            ->where('company_id', $merchant->id)
            ->where('key', self::KEY)
            ->value('value');

        $value = is_string($raw) ? json_decode($raw, true) : $raw;

        return $value === true;
    }
}
