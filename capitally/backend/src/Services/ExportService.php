<?php
declare(strict_types=1);

namespace CapiTally\Services;

use CapiTally\Database;
use CapiTally\Http\Response;
use CapiTally\Support\Audit;
use CapiTally\Support\Validator;

/** CSV exports of the underlying records. Capped at 50,000 rows per file. */
final class ExportService
{
    private const LIMIT = 50000;

    public static function types(): array
    {
        return ['sales', 'purchases', 'expenses', 'products', 'credits', 'cash-ledger', 'inventory-movements'];
    }

    public static function download(int $storeId, int $userId, string $type, string $from, string $to): never
    {
        $p = ['s' => $storeId];
        $range = ['s' => $storeId, 'from' => $from . ' 00:00:00', 'to' => $to . ' 23:59:59'];
        $day   = ['s' => $storeId, 'from' => $from, 'to' => $to];
        $lim   = self::LIMIT;

        [$header, $rows] = match ($type) {
            'sales' => [
                ['Sale no', 'Date', 'Status', 'Paid with', 'Customer', 'Product', 'Quantity', 'Unit price', 'Line total', 'Unit cost at sale', 'Cost of line', 'Quantity returned'],
                Database::fetchAll("SELECT s.sale_no, s.sale_date, s.status, s.payment_method, s.customer_name, si.product_name, si.quantity, si.unit_price, si.line_total, si.unit_cost, si.line_cost, si.returned_quantity
                                      FROM sales s JOIN sale_items si ON si.sale_id = s.id
                                     WHERE s.store_id = :s AND s.sale_date BETWEEN :from AND :to ORDER BY s.sale_date, s.id, si.id LIMIT {$lim}", $range),
            ],
            'purchases' => [
                ['Purchase no', 'Date', 'Status', 'Supplier', 'Reference', 'Product', 'Quantity', 'Cost each', 'Line total'],
                Database::fetchAll("SELECT pu.id, pu.purchase_date, pu.status, su.name, pu.reference_no, p.name, pi.quantity, pi.unit_cost, pi.line_total
                                      FROM purchases pu JOIN purchase_items pi ON pi.purchase_id = pu.id JOIN products p ON p.id = pi.product_id
                                      LEFT JOIN suppliers su ON su.id = pu.supplier_id
                                     WHERE pu.store_id = :s AND pu.purchase_date BETWEEN :from AND :to ORDER BY pu.purchase_date, pu.id, pi.id LIMIT {$lim}", $day),
            ],
            'expenses' => [
                ['Date', 'Category', 'Amount', 'Details', 'Paid with', 'Voided'],
                Database::fetchAll("SELECT e.expense_date, c.name, e.amount, e.description, e.payment_method, IF(e.is_voided = 1, 'yes', 'no')
                                      FROM expenses e JOIN expense_categories c ON c.id = e.category_id
                                     WHERE e.store_id = :s AND e.expense_date BETWEEN :from AND :to ORDER BY e.expense_date, e.id LIMIT {$lim}", $day),
            ],
            'products' => [
                ['Product', 'Barcode', 'Category', 'Supplier', 'Unit', 'In stock', 'Average cost', 'Selling price', 'Stock value', 'Reorder at', 'Active'],
                Database::fetchAll("SELECT p.name, p.barcode, c.name, su.name, p.unit, p.quantity_on_hand, p.cost_price, p.selling_price,
                                           ROUND(GREATEST(p.quantity_on_hand, 0) * p.cost_price, 2), p.reorder_point, IF(p.is_active = 1, 'yes', 'no')
                                      FROM products p LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN suppliers su ON su.id = p.supplier_id
                                     WHERE p.store_id = :s ORDER BY p.name LIMIT {$lim}", $p),
            ],
            'credits' => [
                ['Customer', 'Phone', 'Sale no', 'Sale date', 'Utang', 'Paid', 'Returned', 'Balance', 'Due date', 'Status'],
                Database::fetchAll("SELECT v.customer_name, v.customer_phone, s.sale_no, s.sale_date, v.original_amount, v.amount_paid, v.returned_offset, v.balance, v.due_date, v.status
                                      FROM v_credit_balances v JOIN sales s ON s.id = v.sale_id WHERE v.store_id = :s ORDER BY v.credit_id LIMIT {$lim}", $p),
            ],
            'cash-ledger' => [
                ['Date', 'Type', 'Channel', 'Amount'],
                Database::fetchAll("SELECT entry_date, entry_type, payment_method, amount FROM cash_ledger
                                     WHERE store_id = :s AND entry_date BETWEEN :from AND :to ORDER BY id LIMIT {$lim}", $range),
            ],
            'inventory-movements' => [
                ['Date', 'Product', 'Type', 'Reason', 'Quantity change', 'Quantity after', 'By'],
                Database::fetchAll("SELECT m.created_at, p.name, m.movement_type, m.reason_code, m.quantity_change, m.quantity_after, u.full_name
                                      FROM inventory_movements m JOIN products p ON p.id = m.product_id JOIN users u ON u.id = m.user_id
                                     WHERE m.store_id = :s AND m.created_at BETWEEN :from AND :to ORDER BY m.id LIMIT {$lim}", $range),
            ],
            default => throw Validator::fail('Unknown export type.'),
        };

        Audit::log($storeId, $userId, 'export.csv', 'export', null, null, ['type' => $type, 'rows' => count($rows)]);
        Response::csv("capitally-{$type}-" . date('Ymd') . '.csv', $header, array_map('array_values', $rows));
    }
}
