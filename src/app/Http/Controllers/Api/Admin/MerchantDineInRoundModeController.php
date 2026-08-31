<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * QR-002 S5 — the per-merchant dine-in QR round acceptance policy.
 *
 *   GET /admin/api/v1/merchants/{merchant:uuid}/dine-in-round-mode
 *   PUT /admin/api/v1/merchants/{merchant:uuid}/dine-in-round-mode
 *
 * The 2026-06-29 migration describes pos_company_settings as admin-owned,
 * while the 2026-08-04 test-schema migration describes it as merchant-owned.
 * MerchantAudienceController is the established precedent for pos_admin
 * writing one company policy key here; this controller follows that boundary.
 */
class MerchantDineInRoundModeController extends Controller
{
    private const DEFAULT_MODE = 'kitchen_direct';

    private const KEY = 'dine_in_round_mode';

    private const MODES = [
        'kitchen_direct',
        'staff_confirm',
    ];

    public function show(Company $merchant): JsonResponse
    {
        $this->authorize('view', $merchant);

        return response()->json(['data' => ['mode' => $this->mode($merchant)]]);
    }

    public function update(Request $request, Company $merchant): JsonResponse
    {
        $this->authorize('update', $merchant);

        $validated = $request->validate([
            'mode' => ['required', 'string', Rule::in(self::MODES)],
        ]);
        $mode = (string) $validated['mode'];
        $encodedMode = json_encode($mode, JSON_THROW_ON_ERROR);

        $exists = DB::table('pos_company_settings')
            ->where('company_id', $merchant->id)
            ->where('key', self::KEY)
            ->exists();

        if ($exists) {
            DB::table('pos_company_settings')
                ->where('company_id', $merchant->id)
                ->where('key', self::KEY)
                ->update(['value' => $encodedMode, 'updated_at' => now()]);
        } else {
            DB::table('pos_company_settings')->insert([
                'company_id' => $merchant->id,
                'key' => self::KEY,
                'value' => $encodedMode,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json(['data' => ['mode' => $mode]]);
    }

    private function mode(Company $merchant): string
    {
        $raw = DB::table('pos_company_settings')
            ->where('company_id', $merchant->id)
            ->where('key', self::KEY)
            ->value('value');

        $value = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_string($value) && in_array($value, self::MODES, true)
            ? $value
            : self::DEFAULT_MODE;
    }
}
