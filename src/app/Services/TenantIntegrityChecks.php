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
        // LAUNCH review add-on — a container (pos_ingredient_units) and a pack
        // belong to their item's company and hold a container / pack of the
        // SAME item; an item's count container is one of its own containers;
        // a barcode's item belongs to its company and its container / pack to
        // that item; a breakdown balance or ledger row belongs to its item's
        // (and branch's) company and names a container of that item.
        $checks['ingredient_container_company'] = 'SELECT u.id FROM pos_ingredient_units u JOIN pos_ingredients i ON i.id = u.ingredient_id
            WHERE u.company_id <> i.company_id';
        $checks['ingredient_container_contains_item'] = 'SELECT u.id FROM pos_ingredient_units u
            JOIN pos_ingredient_units c ON c.id = u.contains_unit_id WHERE c.ingredient_id <> u.ingredient_id';
        $checks['ingredient_count_container_item'] = 'SELECT i.id FROM pos_ingredients i JOIN pos_ingredient_units u ON u.id = i.count_container_id
            WHERE u.ingredient_id <> i.id';
        $checks['product_pack_company'] = 'SELECT k.id FROM pos_product_packs k JOIN pos_products p ON p.id = k.product_id
            WHERE k.company_id <> p.company_id';
        $checks['product_pack_contains_item'] = 'SELECT k.id FROM pos_product_packs k JOIN pos_product_packs c ON c.id = k.contains_pack_id
            WHERE c.product_id <> k.product_id';
        $checks['barcode_item_company'] = 'SELECT b.id FROM pos_item_barcodes b LEFT JOIN pos_ingredients i ON i.id = b.ingredient_id
            LEFT JOIN pos_products p ON p.id = b.product_id LEFT JOIN pos_ingredient_units u ON u.id = b.container_id
            LEFT JOIN pos_product_packs k ON k.id = b.pack_id
            WHERE i.company_id <> b.company_id OR p.company_id <> b.company_id OR u.ingredient_id <> b.ingredient_id
            OR k.product_id <> b.product_id';
        foreach (['balance' => 'pos_stock_container_balances', 'movement' => 'pos_stock_container_movements'] as $name => $table) {
            $checks['container_'.$name.'_company'] = "SELECT x.id FROM {$table} x JOIN pos_ingredients i ON i.id = x.ingredient_id
                JOIN pos_ingredient_units u ON u.id = x.container_id LEFT JOIN pos_branches b ON b.id = x.branch_id
                WHERE x.company_id <> i.company_id OR u.ingredient_id <> x.ingredient_id OR u.company_id <> x.company_id
                OR b.company_id <> x.company_id";
        }
        // Fix order A-1 (L5) — the breakdown is kept in LEAF containers only
        // (tester call 7), and a container named on a stock document belongs
        // to that document line's item and to the document's company.
        $checks['container_balance_not_leaf'] = 'SELECT x.id FROM pos_stock_container_balances x
            JOIN pos_ingredient_units u ON u.id = x.container_id WHERE u.contains_unit_id IS NOT NULL';
        $checks['transfer_line_container_item'] = 'SELECT x.id FROM pos_branch_transfer_line_containers x
            JOIN pos_branch_transfer_lines l ON l.id = x.branch_transfer_line_id JOIN pos_branch_transfers t ON t.id = l.branch_transfer_id
            JOIN pos_ingredient_units u ON u.id = x.container_id
            WHERE u.ingredient_id <> l.ingredient_id OR u.company_id <> x.company_id OR x.company_id <> t.company_id';
        $checks['count_line_container_item'] = 'SELECT x.id FROM pos_stock_count_line_containers x
            JOIN pos_stock_count_lines l ON l.id = x.stock_count_line_id JOIN pos_stock_counts c ON c.id = l.stock_count_id
            JOIN pos_ingredient_units u ON u.id = x.container_id
            WHERE u.ingredient_id <> l.ingredient_id OR u.company_id <> x.company_id OR x.company_id <> c.company_id';
        $checks['receipt_line_container_item'] = 'SELECT l.id FROM pos_purchase_receipt_lines l
            JOIN pos_purchase_receipts r ON r.id = l.purchase_receipt_id
            LEFT JOIN pos_ingredient_units u ON u.id = l.container_id LEFT JOIN pos_product_packs k ON k.id = l.pack_id
            WHERE (l.container_id IS NOT NULL AND (l.ingredient_id IS NULL OR u.ingredient_id <> l.ingredient_id OR u.company_id <> r.company_id))
            OR (l.pack_id IS NOT NULL AND (l.product_id IS NULL OR k.product_id <> l.product_id OR k.company_id <> r.company_id))';
        $checks['waste_container_item'] = 'SELECT w.id FROM pos_waste_records w JOIN pos_branches b ON b.id = w.branch_id
            JOIN pos_ingredient_units u ON u.id = w.container_id WHERE u.ingredient_id <> w.ingredient_id OR u.company_id <> b.company_id';
        $checks['restock_line_container_item'] = 'SELECT l.id FROM pos_restock_request_lines l
            JOIN pos_restock_requests r ON r.id = l.restock_request_id JOIN pos_ingredient_units u ON u.id = l.container_id
            WHERE u.ingredient_id <> l.ingredient_id OR u.company_id <> r.company_id';
        // Fix order A-1 — a main slot is a single pick (tester call 15).
        $checks['combo_main_slot_not_single'] = 'SELECT s.id FROM pos_combo_slots s
            WHERE s.is_main AND (s.min_choices <> 1 OR s.max_choices <> 1)';
        // LAUNCH review add-on — tap lists: a Remove option names an ingredient
        // of its own company; a Remove group is owned by its product; Remove
        // and quick-instruction options are free.
        $checks['addon_removes_ingredient_company'] = 'SELECT a.id FROM pos_addons a JOIN pos_ingredients i ON i.id = a.removes_ingredient_id
            WHERE i.company_id <> a.company_id';
        $checks['addon_remove_group_unowned'] = "SELECT g.id FROM pos_addon_groups g WHERE g.kind = 'remove' AND g.owner_product_id IS NULL";
        $checks['addon_remove_option_priced'] = "SELECT a.id FROM pos_addons a JOIN pos_addon_groups g ON g.id = a.add_on_group_id
            WHERE g.kind = 'remove' AND a.price_delta <> 0";
        $checks['addon_instruction_option_priced'] = "SELECT a.id FROM pos_addons a JOIN pos_addon_groups g ON g.id = a.add_on_group_id
            WHERE g.kind = 'instructions' AND a.price_delta <> 0";
        // Fix order A-1 (L1) — only a Remove option may remove a recipe
        // ingredient; (part C review) a Remove or quick-instruction option
        // carries no stock: no legacy ingredient fields, no consumption lines,
        // no linked product.
        $checks['addon_removes_ingredient_not_remove_group'] = "SELECT a.id FROM pos_addons a
            JOIN pos_addon_groups g ON g.id = a.add_on_group_id WHERE a.removes_ingredient_id IS NOT NULL AND g.kind <> 'remove'";
        $checks['addon_tap_list_option_with_stock'] = "SELECT a.id FROM pos_addons a JOIN pos_addon_groups g ON g.id = a.add_on_group_id
            WHERE g.kind IN ('remove', 'instructions') AND (a.ingredient_id IS NOT NULL OR a.ingredient_qty IS NOT NULL
            OR a.ingredient_unit IS NOT NULL OR a.linked_product_id IS NOT NULL
            OR EXISTS (SELECT 1 FROM pos_addon_consumptions c WHERE c.add_on_id = a.id))";
        // LAUNCH packaging add-on — the same item on two lines of one product
        // (or one add-on option and direction) only with disjoint "Used for"
        // ticks (tester call 3; the per-bit partial uniques refuse an overlap,
        // this reports any that slipped in); a per-order packaging line names
        // an item of its own company and never a prep item; an order's frozen
        // packaging names items of the order's company.
        $overlap = '(a.order_types & b.order_types) <> 0 AND a.id <> b.id';
        $checks['recipe_line_ticks_overlap'] = "SELECT a.id FROM pos_product_recipes a JOIN pos_product_recipes b
            ON b.product_id = a.product_id AND b.ingredient_id = a.ingredient_id AND {$overlap}";
        $checks['component_line_ticks_overlap'] = "SELECT a.id FROM pos_product_components a JOIN pos_product_components b
            ON b.product_id = a.product_id AND b.component_product_id = a.component_product_id AND {$overlap}";
        $checks['addon_stock_line_ticks_overlap'] = "SELECT a.id FROM pos_addon_consumptions a JOIN pos_addon_consumptions b
            ON b.add_on_id = a.add_on_id AND b.direction = a.direction AND {$overlap}
            AND (b.ingredient_id = a.ingredient_id OR b.component_product_id = a.component_product_id)";
        $checks['order_packaging_ref_company'] = 'SELECT l.id FROM pos_order_packaging_lines l
            LEFT JOIN pos_ingredients i ON i.id = l.ingredient_id LEFT JOIN pos_products p ON p.id = l.product_id
            WHERE i.company_id <> l.company_id OR p.company_id <> l.company_id';
        $checks['order_packaging_prep_item'] = 'SELECT l.id FROM pos_order_packaging_lines l
            JOIN pos_ingredients i ON i.id = l.ingredient_id WHERE i.is_prep';
        $checks['order_packaging_snapshot_company'] = DB::getDriverName() === 'pgsql'
            ? "SELECT o.id FROM pos_orders o
                CROSS JOIN LATERAL json_array_elements(CASE WHEN json_typeof(o.packaging_snapshot_json->'lines') = 'array'
                    THEN o.packaging_snapshot_json->'lines' ELSE '[]'::json END) l
                LEFT JOIN pos_ingredients i ON i.id = (l->>'ingredient_id')::bigint
                LEFT JOIN pos_products p ON p.id = (l->>'product_id')::bigint
                WHERE o.packaging_snapshot_json IS NOT NULL AND (i.company_id <> o.company_id OR p.company_id <> o.company_id)"
            : "SELECT o.id FROM pos_orders o, json_each(o.packaging_snapshot_json, '$.lines') l
                LEFT JOIN pos_ingredients i ON i.id = json_extract(l.value, '$.ingredient_id')
                LEFT JOIN pos_products p ON p.id = json_extract(l.value, '$.product_id')
                WHERE o.packaging_snapshot_json IS NOT NULL AND (i.company_id <> o.company_id OR p.company_id <> o.company_id)";
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
