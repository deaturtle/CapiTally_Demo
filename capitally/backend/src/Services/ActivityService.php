<?php
declare(strict_types=1);

namespace CapiTally\Services;

use CapiTally\Database;
use CapiTally\Support\Validator;

/** Real system activity only, merged from the source tables and sorted newest first. */
final class ActivityService
{
    /** type key => SQL template. {s},{f},{t} are replaced with unique parameter names per subquery. */
    private const SOURCES = [
        'sale' => "SELECT 'sale' AS event_type, s.id AS event_id, s.sale_date AS event_at, s.total_amount AS amount,
                          s.payment_method, s.status, s.customer_name AS label, s.sale_no AS reference_no
                     FROM sales s WHERE s.store_id = :{s} AND s.sale_date >= :{f} AND s.sale_date <= :{t}",
        'purchase' => "SELECT 'purchase', pu.id, pu.created_at, pu.total_cost, NULL, pu.status, su.name, pu.reference_no
                         FROM purchases pu LEFT JOIN suppliers su ON su.id = pu.supplier_id
                        WHERE pu.store_id = :{s} AND pu.created_at >= :{f} AND pu.created_at <= :{t}",
        'adjustment' => "SELECT 'inventory_adjustment', a.id, a.created_at, NULL, NULL, a.adjustment_type, a.note, NULL
                           FROM stock_adjustments a WHERE a.store_id = :{s} AND a.created_at >= :{f} AND a.created_at <= :{t}",
        'return' => "SELECT sr.return_type, sr.id, sr.created_at, sr.total_value, sr.refund_method, sr.return_type, sr.reason, sl.sale_no
                       FROM sale_returns sr JOIN sales sl ON sl.id = sr.sale_id
                      WHERE sr.store_id = :{s} AND sr.created_at >= :{f} AND sr.created_at <= :{t}",
        'credit_payment' => "SELECT 'credit_payment', cp.id, cp.paid_at, cp.amount, cp.payment_method,
                                    CASE WHEN cp.is_voided = 1 THEN 'voided' ELSE 'active' END, c.customer_name, NULL
                               FROM credit_payments cp JOIN credits c ON c.id = cp.credit_id
                              WHERE cp.store_id = :{s} AND cp.paid_at >= :{f} AND cp.paid_at <= :{t}",
        'expense' => "SELECT 'expense', e.id, e.created_at, e.amount, e.payment_method,
                             CASE WHEN e.is_voided = 1 THEN 'voided' ELSE 'active' END, ec.name, NULL
                        FROM expenses e JOIN expense_categories ec ON ec.id = e.category_id
                       WHERE e.store_id = :{s} AND e.created_at >= :{f} AND e.created_at <= :{t}",
        'withdrawal' => "SELECT 'withdrawal', w.id, w.withdrawn_at, w.amount, NULL,
                                CASE WHEN w.is_voided = 1 THEN 'voided' ELSE 'active' END, w.note, NULL
                           FROM withdrawals w WHERE w.store_id = :{s} AND w.withdrawn_at >= :{f} AND w.withdrawn_at <= :{t}",
        'supplier_payment' => "SELECT 'supplier_payment', sp.id, sp.paid_at, sp.amount, sp.payment_method,
                                      CASE WHEN sp.is_voided = 1 THEN 'voided' ELSE 'active' END, su.name, pu.reference_no
                                 FROM supplier_payments sp JOIN purchases pu ON pu.id = sp.purchase_id
                                 LEFT JOIN suppliers su ON su.id = pu.supplier_id
                                WHERE sp.store_id = :{s} AND sp.paid_at >= :{f} AND sp.paid_at <= :{t}",
    ];


    /** Column holding the user who recorded the event (used to limit staff to their own activity). */
    private const OWNER_COLUMN = [
        'sale' => 's.user_id', 'purchase' => 'pu.user_id', 'adjustment' => 'a.user_id', 'return' => 'sr.user_id',
        'credit_payment' => 'cp.user_id', 'expense' => 'e.user_id', 'withdrawal' => 'w.user_id', 'supplier_payment' => 'sp.user_id',
    ];

    /** Which events involve a product, and how. Sources not listed here cannot match a product filter. */
    private const PRODUCT_CONDITION = [
        'sale'       => 'EXISTS (SELECT 1 FROM sale_items x WHERE x.sale_id = s.id AND x.product_id = :p{i})',
        'purchase'   => 'EXISTS (SELECT 1 FROM purchase_items x WHERE x.purchase_id = pu.id AND x.product_id = :p{i})',
        'adjustment' => 'EXISTS (SELECT 1 FROM stock_adjustment_items x WHERE x.adjustment_id = a.id AND x.product_id = :p{i})',
        'return'     => 'EXISTS (SELECT 1 FROM sale_return_items x WHERE x.sale_return_id = sr.id AND x.product_id = :p{i})',
    ];

    /** Which events carry a payment method. Sources not listed here cannot match a payment-method filter. */
    private const METHOD_CONDITION = [
        'sale' => 's.payment_method = :m{i}', 'return' => 'sr.refund_method = :m{i}', 'credit_payment' => 'cp.payment_method = :m{i}',
        'expense' => 'e.payment_method = :m{i}', 'supplier_payment' => 'sp.payment_method = :m{i}',
    ];

    public static function types(): array
    {
        return array_keys(self::SOURCES);
    }

    /**
     * @param int|null    $userId    only events recorded by this user (staff see their own activity)
     * @param int|null    $productId only events involving this product
     * @param string|null $method    only events paid with this method (cash, gcash, maya, credit)
     */
    public static function feed(
        int $storeId, ?string $from, ?string $to, ?string $type, int $limit, int $offset,
        ?int $userId = null, ?int $productId = null, ?string $method = null
    ): array {
        if ($type !== null && !isset(self::SOURCES[$type])) {
            throw Validator::fail('Activity type is not valid.');
        }
        if ($method !== null && !in_array($method, ['cash', 'gcash', 'maya', 'credit'], true)) {
            throw Validator::fail('Payment method is not valid.');
        }
        $from = ($from ?? '1970-01-01') . ' 00:00:00';
        $to   = ($to ?? '2999-12-31') . ' 23:59:59';

        $parts  = [];
        $params = [];
        $i = 0;
        foreach (self::SOURCES as $key => $sql) {
            if ($type !== null && $type !== $key) {
                continue;
            }
            if (($productId !== null && !isset(self::PRODUCT_CONDITION[$key])) || ($method !== null && !isset(self::METHOD_CONDITION[$key]))) {
                continue;   // this kind of event cannot match the requested filter
            }
            $i++;
            $sql = strtr($sql, ['{s}' => "s{$i}", '{f}' => "f{$i}", '{t}' => "t{$i}"]);
            $params["s{$i}"] = $storeId;
            $params["f{$i}"] = $from;
            $params["t{$i}"] = $to;
            if ($userId !== null) {
                $sql .= ' AND ' . self::OWNER_COLUMN[$key] . " = :u{$i}";
                $params["u{$i}"] = $userId;
            }
            if ($productId !== null) {
                $sql .= ' AND ' . str_replace('{i}', (string) $i, self::PRODUCT_CONDITION[$key]);
                $params["p{$i}"] = $productId;
            }
            if ($method !== null) {
                $sql .= ' AND ' . str_replace('{i}', (string) $i, self::METHOD_CONDITION[$key]);
                $params["m{$i}"] = $method;
            }
            $parts[] = '(' . $sql . ')';
        }
        if ($parts === []) {
            return [];
        }
        $limit  = max(1, min(100, $limit));
        $offset = max(0, $offset);

        return Database::fetchAll(
            implode(' UNION ALL ', $parts) . " ORDER BY event_at DESC, event_id DESC LIMIT {$limit} OFFSET {$offset}",
            $params
        );
    }
}
