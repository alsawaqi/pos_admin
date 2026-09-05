<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Read-only since QR-003 T0: the merchant owns dine-in round policy writes.
 *
 *   GET /admin/api/v1/merchants/{merchant:uuid}/dine-in-round-mode
 *
 * pos_merchant writes the company default in pos_company_settings and the
 * per-branch overrides in pos_branch_settings. The admin card displays both.
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

        $branches = Branch::query()
            ->where('company_id', $merchant->id)
            ->select(['uuid', 'name'])
            ->addSelect([
                'round_mode_value' => DB::table('pos_branch_settings')
                    ->select('value')
                    ->whereColumn('branch_id', 'pos_branches.id')
                    ->where('company_id', $merchant->id)
                    ->where('key', self::KEY),
            ])
            ->orderBy('name')
            ->get()
            ->flatMap(static function (Branch $branch): array {
                $raw = $branch->getAttribute('round_mode_value');
                $value = is_string($raw) ? json_decode($raw, true) : $raw;

                return is_string($value) && in_array($value, self::MODES, true)
                    ? [['uuid' => $branch->uuid, 'name' => $branch->name, 'mode' => $value]]
                    : [];
            })
            ->values()
            ->all();

        return response()->json(['data' => [
            'mode' => $this->mode($merchant),
            'branches' => $branches,
        ]]);
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
