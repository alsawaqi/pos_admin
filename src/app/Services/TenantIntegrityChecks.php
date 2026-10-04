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
        // LAUNCH-P4 — a combo slot belongs to its combo's company; an option
        // belongs to its slot's company and offers a STANDARD product of that
        // company; a sold-out row belongs to its branch's and product's
        // company; a combo child line sits on its parent's order.
        $checks['combo_slot_company'] = 'SELECT s.id FROM pos_combo_slots s JOIN pos_products p ON p.id = s.combo_product_id
            WHERE s.company_id <> p.company_id';
        $checks['combo_option_company'] = 'SELECT o.id FROM pos_combo_slot_options o JOIN pos_combo_slots s ON s.id = o.slot_id
            JOIN pos_products p ON p.id = o.product_id WHERE o.company_id <> s.company_id OR p.company_id <> s.company_id';
        $checks['combo_option_type'] = "SELECT o.id FROM pos_combo_slot_options o JOIN pos_products p ON p.id = o.product_id
            WHERE p.product_type <> 'standard'";
        $checks['sold_out_company'] = 'SELECT x.id FROM pos_product_sold_out x JOIN pos_branches b ON b.id = x.branch_id
            JOIN pos_products p ON p.id = x.product_id WHERE x.company_id <> b.company_id OR x.company_id <> p.company_id';
        $checks['combo_child_order'] = 'SELECT c.id FROM pos_order_items c JOIN pos_order_items p ON p.id = c.parent_order_item_id
            WHERE c.order_id <> p.order_id';
        // LAUNCH-P5 — a staff/branch pivot row belongs to its staff's and its
        // branch's company, and every staff member's home branch is in it; an
        // approval's branch, actor and approver belong to its company; an
        // attendance row's staff and branch belong to its company; an order's
        // voider / void approver and a shift's closer belong to its company.
        $checks['staff_branch_company'] = 'SELECT x.id FROM pos_staff_branches x JOIN pos_staff s ON s.id = x.staff_id
            JOIN pos_branches b ON b.id = x.branch_id WHERE x.company_id <> s.company_id OR x.company_id <> b.company_id';
        $checks['staff_home_branch_missing'] = 'SELECT s.id FROM pos_staff s WHERE NOT EXISTS (SELECT 1 FROM pos_staff_branches x
            WHERE x.staff_id = s.id AND x.branch_id = s.branch_id)';
        $checks['approval_company'] = 'SELECT a.id FROM pos_approvals a JOIN pos_branches b ON b.id = a.branch_id
            LEFT JOIN pos_staff actor ON actor.id = a.actor_staff_id LEFT JOIN pos_staff approver ON approver.id = a.approver_staff_id
            WHERE b.company_id <> a.company_id OR actor.company_id <> a.company_id OR approver.company_id <> a.company_id';
        $checks['attendance_company'] = 'SELECT a.id FROM pos_staff_attendance a JOIN pos_staff s ON s.id = a.staff_id
            JOIN pos_branches b ON b.id = a.branch_id WHERE a.company_id <> s.company_id OR a.company_id <> b.company_id';
        $checks['order_void_staff_company'] = 'SELECT o.id FROM pos_orders o LEFT JOIN pos_staff v ON v.id = o.voided_by_staff_id
            LEFT JOIN pos_staff a ON a.id = o.void_approved_by_staff_id WHERE v.company_id <> o.company_id OR a.company_id <> o.company_id';
        $checks['shift_closer_company'] = 'SELECT sh.id FROM pos_shifts sh JOIN pos_staff s ON s.id = sh.closed_by_staff_id
            WHERE s.company_id <> sh.company_id';
        $checks['expense_shift_company'] = 'SELECT e.id FROM pos_expenses e JOIN pos_shifts sh ON sh.id = e.shift_id
            WHERE sh.company_id <> e.company_id';
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
                'classification' => match (true) {
                    in_array($name, ['sync_assignment_unverified', 'order_device_unverified_history'], true) => 'unverified_history',
                    // LAUNCH-P5 — a home branch missing from pos_staff_branches is a
                    // data gap to repair (a staff row written before the portal keeps
                    // the table), not a tenant violation: reported, never failing.
                    $name === 'staff_home_branch_missing' => 'data_gap',
                    default => 'violation',
                }];
        }

        return $results;
    }
}
