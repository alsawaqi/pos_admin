<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Read-only SQL assertions. Only ids/counts are retained; no customer or bank data. */
final class TenantIntegrityChecks
{
    /** Every query returns violating source ids, never a success sentinel. @return array<string, string> */
    public function queries(): array
    {
        $checks = [];
        foreach (['customer' => 'customers', 'table' => 'tables', 'branch' => 'branches', 'device' => 'devices'] as $relation => $table) {
            $checks['order_'.$relation] = "SELECT o.id FROM pos_orders o LEFT JOIN pos_{$table} r ON r.id = o.{$relation}_id
                WHERE o.{$relation}_id IS NOT NULL AND (r.id IS NULL OR r.company_id IS NULL OR r.company_id <> o.company_id)";
        }
        $historicalDevice = 'EXISTS (SELECT 1 FROM pos_device_assignments_history h
            WHERE h.device_id = o.device_id AND h.company_id = o.company_id AND h.branch_id = o.branch_id
            AND h.assigned_at <= o.opened_at AND h.unassigned_at >= o.opened_at)';
        $checks['order_device_unverified_history'] = $checks['order_device'].' AND '.$historicalDevice;
        $checks['order_device'] .= ' AND NOT '.$historicalDevice;
        // Payments have no company_id. Their company is derived from their order.
        // Independently recorded commission/donation/reversal attribution must agree.
        $checks['payment_order_missing'] = 'SELECT p.id FROM pos_payments p LEFT JOIN pos_orders o ON o.id = p.order_id WHERE o.id IS NULL';
        foreach (['commission' => 'sale_commissions', 'roundup' => 'roundup_donations', 'reversal' => 'payment_reversals'] as $name => $table) {
            $paymentColumn = 'payment_id';
            $checks['payment_'.$name] = "SELECT p.id FROM pos_payments p JOIN pos_{$table} r ON r.{$paymentColumn} = p.id
                JOIN pos_orders o ON o.id = p.order_id WHERE r.company_id <> o.company_id OR r.order_id <> o.id";
        }
        foreach (['ingredient' => ['stock_movements', 'ingredients'], 'product' => ['product_stock_movements', 'products']] as $name => [$movements, $items]) {
            // A null branch on ingredient movements denotes the central warehouse;
            // the item's company is the only tenant attribution for that row.
            $checks['stock_'.$name] = "SELECT m.id FROM pos_{$movements} m LEFT JOIN pos_branches b ON b.id = m.branch_id
                LEFT JOIN pos_{$items} i ON i.id = m.{$name}_id
                WHERE i.id IS NULL OR (m.branch_id IS NOT NULL AND (b.id IS NULL OR b.company_id <> i.company_id))";
        }
        $checks['stock_product_company'] = 'SELECT m.id FROM pos_product_stock_movements m JOIN pos_products p ON p.id = m.product_id WHERE m.company_id <> p.company_id';
        // LAUNCH-P3 — a prep item's recipe line and its component belong to one company.
        $checks['prep_recipe_company'] = 'SELECT r.id FROM pos_ingredient_recipes r JOIN pos_ingredients p ON p.id = r.prep_ingredient_id
            JOIN pos_ingredients c ON c.id = r.ingredient_id WHERE p.company_id <> c.company_id';
        // LAUNCH-P3 K4 — a prep waste names a prep item of the waste's own company.
        $checks['waste_prep_company'] = 'SELECT w.id FROM pos_waste_records w JOIN pos_branches b ON b.id = w.branch_id
            JOIN pos_ingredients p ON p.id = w.prep_ingredient_id WHERE p.company_id <> b.company_id';
        foreach (['commission' => 'sale_commissions', 'roundup' => 'roundup_donations'] as $name => $table) {
            $checks[$name.'_order'] = "SELECT r.id FROM pos_{$table} r LEFT JOIN pos_orders o ON o.id = r.order_id
                WHERE o.id IS NULL OR r.company_id <> o.company_id";
        }
        // Aggregate payouts/invoices/settlements link to orders through their commission rows.
        foreach (['payout' => 'payouts', 'invoice' => 'commission_invoices', 'settlement' => 'commission_settlements'] as $name => $table) {
            $checks[$name.'_order'] = "SELECT c.id FROM pos_sale_commissions c LEFT JOIN pos_{$table} r ON r.id = c.{$name}_id
                LEFT JOIN pos_orders o ON o.id = c.order_id WHERE c.{$name}_id IS NOT NULL
                AND (r.id IS NULL OR o.id IS NULL OR r.company_id <> o.company_id OR r.company_id <> c.company_id)";
        }
        // Inclusive boundaries intentionally expose second-resolution overlapping
        // assignments as unverified, instead of guessing which assignment won.
        $window = 'h.device_id = s.device_id AND h.assigned_at <= s.server_received_at
            AND (h.unassigned_at IS NULL OR h.unassigned_at >= s.server_received_at)';
        $checks['sync_assignment_unverified'] = "SELECT s.id FROM pos_sync_events s WHERE s.company_id IS NULL OR s.branch_id IS NULL
            OR (SELECT COUNT(*) FROM pos_device_assignments_history h WHERE {$window}) <> 1";
        $checks['sync_assignment_mismatch'] = "SELECT s.id FROM pos_sync_events s
            WHERE (SELECT COUNT(*) FROM pos_device_assignments_history h WHERE {$window}) = 1
            AND EXISTS (SELECT 1 FROM pos_device_assignments_history h WHERE {$window}
                AND (h.company_id IS NULL OR h.branch_id IS NULL OR h.company_id <> s.company_id OR h.branch_id <> s.branch_id))";

        return $checks;
    }

    /** @return array<string, array{count: int, sample_ids: list<int>}> */
    public function run(): array
    {
        $results = [];
        foreach ($this->queries() as $name => $sql) {
            $count = (int) DB::selectOne('SELECT COUNT(DISTINCT id) AS total FROM ('.$sql.') violations')->total;
            $ids = $count === 0 ? [] : array_map(static fn (object $row): int => (int) $row->id,
                DB::select('SELECT DISTINCT id FROM ('.$sql.') violations ORDER BY id LIMIT 20'));
            $results[$name] = ['count' => $count, 'sample_ids' => $ids,
                'classification' => in_array($name, ['sync_assignment_unverified', 'order_device_unverified_history'], true)
                    ? 'unverified_history' : 'violation'];
        }

        return $results;
    }
}
