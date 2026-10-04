<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * LAUNCH-P5 data contract — the tick list per position (owner decision 1).
 *
 * Writes the pos_company_settings key `position_permissions` for every
 * existing company:
 *
 *   { "<position>": { "actions": { "<key>": true|false, ... },
 *                     "discount_max_percent": 0..100 }, ... }
 *
 * from the fixed defaults (DEFAULTS below — byte-for-byte the "defaults" of
 * the shared fixture position_permissions_defaults.json; a test compares
 * them) and three of today's position lists, exactly as the fixture's
 * migration_from_old_keys says:
 *
 *   manager_approval_positions -> approvals.give: true for exactly the listed
 *                                 positions
 *   reports_positions          -> reports.view: true for exactly the listed
 *                                 positions
 *   kitchen_positions          -> kitchen.screen: true for exactly the listed
 *                                 positions, and always for kitchen
 *   order_cancel_positions     -> NOT mapped (order.void_paid keeps its
 *                                 default)
 *
 * A list that is missing, not a list, or names no known position keeps the
 * default for its action — today the server reads such a list as
 * "managers only", which is that default. A kitchen list that is present but
 * empty means "kitchen only" today, so it maps to exactly that.
 *
 * The four old keys are not touched (old app builds keep reading them; the
 * portal keeps them in sync from the matrix). A company that already has a
 * `position_permissions` row is skipped, so a re-run never overwrites a
 * merchant's ticks. down() deletes the rows; never roll it back once
 * merchants have edited their ticks.
 */
return new class extends Migration
{
    public const KEY = 'position_permissions';

    /** @var list<string> */
    public const POSITIONS = ['cashier', 'waiter', 'kitchen', 'supervisor', 'manager'];

    /** @var list<string> */
    public const ACTIONS = [
        'order.void_unpaid', 'order.void_paid', 'table.cancel_line', 'table.cancel_bill',
        'discount.manual', 'comp', 'gift', 'loyalty.redeem', 'sold_out.toggle',
        'receipt.reprint', 'kitchen.reprint', 'reports.view', 'kitchen.screen',
        'shift.close_other', 'payout', 'stock.waste', 'stock.count', 'training.use', 'approvals.give',
    ];

    /** The shared fixture's defaults (LAUNCH-P5 position_permissions_defaults.json). */
    public const DEFAULTS = [
        'cashier' => [
            'discount_max_percent' => 10,
            'actions' => [
                'order.void_unpaid' => false, 'order.void_paid' => false, 'table.cancel_line' => false, 'table.cancel_bill' => false,
                'discount.manual' => true, 'comp' => false, 'gift' => false, 'loyalty.redeem' => false, 'sold_out.toggle' => false,
                'receipt.reprint' => true, 'kitchen.reprint' => false, 'reports.view' => false, 'kitchen.screen' => false,
                'shift.close_other' => false, 'payout' => false, 'stock.waste' => true, 'stock.count' => true, 'training.use' => true, 'approvals.give' => false,
            ],
        ],
        'waiter' => [
            'discount_max_percent' => 10,
            'actions' => [
                'order.void_unpaid' => false, 'order.void_paid' => false, 'table.cancel_line' => false, 'table.cancel_bill' => false,
                'discount.manual' => true, 'comp' => false, 'gift' => false, 'loyalty.redeem' => false, 'sold_out.toggle' => false,
                'receipt.reprint' => true, 'kitchen.reprint' => false, 'reports.view' => false, 'kitchen.screen' => false,
                'shift.close_other' => false, 'payout' => false, 'stock.waste' => true, 'stock.count' => true, 'training.use' => true, 'approvals.give' => false,
            ],
        ],
        'kitchen' => [
            'discount_max_percent' => 0,
            'actions' => [
                'order.void_unpaid' => false, 'order.void_paid' => false, 'table.cancel_line' => false, 'table.cancel_bill' => false,
                'discount.manual' => false, 'comp' => false, 'gift' => false, 'loyalty.redeem' => false, 'sold_out.toggle' => false,
                'receipt.reprint' => true, 'kitchen.reprint' => false, 'reports.view' => false, 'kitchen.screen' => true,
                'shift.close_other' => false, 'payout' => false, 'stock.waste' => true, 'stock.count' => true, 'training.use' => true, 'approvals.give' => false,
            ],
        ],
        'supervisor' => [
            'discount_max_percent' => 25,
            'actions' => [
                'order.void_unpaid' => true, 'order.void_paid' => false, 'table.cancel_line' => true, 'table.cancel_bill' => false,
                'discount.manual' => true, 'comp' => false, 'gift' => false, 'loyalty.redeem' => true, 'sold_out.toggle' => true,
                'receipt.reprint' => true, 'kitchen.reprint' => true, 'reports.view' => false, 'kitchen.screen' => false,
                'shift.close_other' => true, 'payout' => true, 'stock.waste' => true, 'stock.count' => true, 'training.use' => true, 'approvals.give' => false,
            ],
        ],
        'manager' => [
            'discount_max_percent' => 100,
            'actions' => [
                'order.void_unpaid' => true, 'order.void_paid' => true, 'table.cancel_line' => true, 'table.cancel_bill' => true,
                'discount.manual' => true, 'comp' => true, 'gift' => true, 'loyalty.redeem' => true, 'sold_out.toggle' => true,
                'receipt.reprint' => true, 'kitchen.reprint' => true, 'reports.view' => true, 'kitchen.screen' => true,
                'shift.close_other' => true, 'payout' => true, 'stock.waste' => true, 'stock.count' => true, 'training.use' => true, 'approvals.give' => true,
            ],
        ],
    ];

    /** Old list key => the action it maps to. order_cancel_positions is deliberately absent. */
    public const OLD_KEYS = [
        'manager_approval_positions' => 'approvals.give',
        'reports_positions' => 'reports.view',
        'kitchen_positions' => 'kitchen.screen',
    ];

    public function up(): void
    {
        $now = now();

        DB::table('pos_companies')->orderBy('id')->select('id')->chunk(500, function ($companies) use ($now): void {
            $ids = $companies->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            $existing = DB::table('pos_company_settings')->whereIn('company_id', $ids)->where('key', self::KEY)
                ->pluck('company_id')->map(static fn ($id): int => (int) $id)->all();
            $old = DB::table('pos_company_settings')->whereIn('company_id', $ids)->whereIn('key', array_keys(self::OLD_KEYS))
                ->get(['company_id', 'key', 'value'])->groupBy('company_id');

            $rows = [];
            foreach ($ids as $companyId) {
                if (in_array($companyId, $existing, true)) {
                    continue;
                }
                $lists = [];
                foreach ($old->get($companyId, collect()) as $setting) {
                    $lists[(string) $setting->key] = $setting->value;
                }
                $rows[] = [
                    'company_id' => $companyId,
                    'key' => self::KEY,
                    'value' => json_encode(self::matrix($lists), JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if ($rows !== []) {
                DB::table('pos_company_settings')->insert($rows);
            }
        });
    }

    public function down(): void
    {
        DB::table('pos_company_settings')->where('key', self::KEY)->delete();
    }

    /**
     * The matrix for one company from its raw old list values (JSON text or
     * decoded arrays, keyed by the old setting key).
     *
     * @param  array<string, mixed>  $lists
     * @return array<string, array{actions: array<string, bool>, discount_max_percent: int}>
     */
    public static function matrix(array $lists): array
    {
        $matrix = [];
        foreach (self::POSITIONS as $position) {
            $matrix[$position] = [
                'actions' => self::DEFAULTS[$position]['actions'],
                'discount_max_percent' => self::DEFAULTS[$position]['discount_max_percent'],
            ];
        }

        foreach (self::OLD_KEYS as $key => $action) {
            if (! array_key_exists($key, $lists)) {
                continue;
            }
            $raw = $lists[$key];
            $value = is_string($raw) ? json_decode($raw, true) : $raw;
            if (! is_array($value)) {
                continue;
            }
            $listed = array_values(array_intersect(self::POSITIONS, array_map(
                static fn ($p): string => is_string($p) ? trim($p) : '',
                $value,
            )));
            if ($listed === [] && $action !== 'kitchen.screen') {
                continue;
            }
            foreach (self::POSITIONS as $position) {
                $matrix[$position]['actions'][$action] = in_array($position, $listed, true)
                    || ($action === 'kitchen.screen' && $position === 'kitchen');
            }
        }

        return $matrix;
    }
};
