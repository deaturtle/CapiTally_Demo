<?php
declare(strict_types=1);

namespace CapiTally\Services;

use CapiTally\Database;
use CapiTally\Support\Decimal;
use CapiTally\Support\Validator;
use DateTimeImmutable;

/**
 * Reports are derived from the underlying records; nothing here is stored.
 *
 *   Revenue      = sales in the period - value of returns/cancellations in the period
 *   Cost of sales = snapshotted sale costs - cost of returned goods that went back on the shelf
 *   Gross Profit = Revenue - Cost of sales
 *   Net Profit   = Gross Profit - Expenses
 * Returned goods that were NOT restocked keep their cost in Cost of sales (the loss stays visible).
 * Expenses are never part of Cost of sales.
 */
final class ReportService
{
    /** @return array{0:string,1:string} validated [from, to]; defaults to today. */
    public static function range(?string $from, ?string $to): array
    {
        $today = date('Y-m-d');
        $from  = Validator::date(['from' => $from ?? $to ?? $today], 'from', 'From date');
        $to    = Validator::date(['to' => $to ?? $from], 'to', 'To date');
        if ($from > $to) {
            throw Validator::fail('The start date must not be after the end date.');
        }
        $days = (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days;
        if ($days > 366) {
            throw Validator::fail('Please choose a period of one year or less.');
        }
        return [$from, $to];
    }

    /** Revenue, cost of sales, gross profit, expenses and net profit for a period. */
    public static function periodSummary(int $storeId, string $from, string $to): array
    {
        $p = ['s' => $storeId, 'from' => $from . ' 00:00:00', 'to' => $to . ' 23:59:59'];

        $sales = Database::fetchOne(
            'SELECT COALESCE(SUM(total_amount), 0) AS revenue, COALESCE(SUM(total_cost), 0) AS cost, COUNT(*) AS n
               FROM sales WHERE store_id = :s AND sale_date BETWEEN :from AND :to',
            $p
        );
        $returns = self::money(Database::fetchValue(
            'SELECT COALESCE(SUM(total_value), 0) FROM sale_returns WHERE store_id = :s AND created_at BETWEEN :from AND :to', $p
        ));
        $restockedCost = self::money(Database::fetchValue(
            'SELECT COALESCE(SUM(ROUND(sri.quantity * sri.unit_cost, 2)), 0)
               FROM sale_return_items sri JOIN sale_returns sr ON sr.id = sri.sale_return_id
              WHERE sr.store_id = :s AND sr.goods_restocked = 1 AND sr.created_at BETWEEN :from AND :to', $p
        ));
        $expenses = self::money(Database::fetchValue(
            'SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE store_id = :s AND is_voided = 0 AND expense_date BETWEEN :f AND :t',
            ['s' => $storeId, 'f' => $from, 't' => $to]
        ));

        $byMethod = [];
        foreach (Database::fetchAll(
            'SELECT payment_method, COALESCE(SUM(total_amount), 0) AS revenue, COUNT(*) AS n
               FROM sales WHERE store_id = :s AND sale_date BETWEEN :from AND :to GROUP BY payment_method', $p
        ) as $r) {
            $byMethod[$r['payment_method']] = ['sales_count' => (int) $r['n'], 'gross_sales' => $r['revenue']];
        }

        $m = self::profit(self::money($sales['revenue']), $returns, self::money($sales['cost']), $restockedCost, $expenses);
        $f = static fn (int $u): string => Decimal::format($u, Decimal::SCALE_MONEY);

        return [
            'from'              => $from,
            'to'                => $to,
            'sales_count'       => (int) $sales['n'],
            'gross_sales'       => $f(self::money($sales['revenue'])),
            'returns_value'     => $f($returns),
            'revenue'           => $f($m['revenue']),
            'cost_of_sales'     => $f($m['cogs']),
            'gross_profit'      => $f($m['gross_profit']),
            'expenses'          => $f($m['expenses']),
            'net_profit'        => $f($m['net_profit']),
            'sales_by_method'   => $byMethod,
        ];
    }

    /**
     * Pure profit arithmetic in centavos (kept separate so it can be verified on its own).
     * @return array{revenue:int,cogs:int,gross_profit:int,expenses:int,net_profit:int}
     */
    public static function profit(int $grossSales, int $returnsValue, int $saleCosts, int $restockedReturnCost, int $expenses): array
    {
        $revenue = $grossSales - $returnsValue;
        $cogs    = $saleCosts - $restockedReturnCost;
        $gross   = $revenue - $cogs;
        return ['revenue' => $revenue, 'cogs' => $cogs, 'gross_profit' => $gross,
                'expenses' => $expenses, 'net_profit' => $gross - $expenses];
    }

    /** Daily or weekly (Monday start) sales series. */
    public static function salesSeries(int $storeId, string $from, string $to, string $granularity): array
    {
        $bucket = static fn (string $col): string => $granularity === 'week'
            ? "DATE_SUB(DATE({$col}), INTERVAL WEEKDAY({$col}) DAY)"
            : "DATE({$col})";
        $p = ['s' => $storeId, 'from' => $from . ' 00:00:00', 'to' => $to . ' 23:59:59'];

        $rows = [];
        foreach (Database::fetchAll(
            'SELECT ' . $bucket('sale_date') . ' AS bucket, COUNT(*) AS n, COALESCE(SUM(total_amount), 0) AS gross
               FROM sales WHERE store_id = :s AND sale_date BETWEEN :from AND :to GROUP BY bucket', $p
        ) as $r) {
            $rows[$r['bucket']] = ['period_start' => $r['bucket'], 'sales_count' => (int) $r['n'],
                                   'gross_sales' => self::money($r['gross']), 'returns_value' => 0];
        }
        foreach (Database::fetchAll(
            'SELECT ' . $bucket('created_at') . ' AS bucket, COALESCE(SUM(total_value), 0) AS v
               FROM sale_returns WHERE store_id = :s AND created_at BETWEEN :from AND :to GROUP BY bucket', $p
        ) as $r) {
            $rows[$r['bucket']] ??= ['period_start' => $r['bucket'], 'sales_count' => 0, 'gross_sales' => 0, 'returns_value' => 0];
            $rows[$r['bucket']]['returns_value'] = self::money($r['v']);
        }
        ksort($rows);

        return array_values(array_map(static fn (array $r): array => [
            'period_start'  => $r['period_start'],
            'sales_count'   => $r['sales_count'],
            'gross_sales'   => Decimal::format($r['gross_sales'], 2),
            'returns_value' => Decimal::format($r['returns_value'], 2),
            'revenue'       => Decimal::format($r['gross_sales'] - $r['returns_value'], 2),
        ], $rows));
    }

    public static function stock(int $storeId, int $limit, int $offset): array
    {
        $limit  = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $items = Database::fetchAll(
            "SELECT p.id, p.name, p.barcode, p.unit, p.quantity_on_hand, p.cost_price,
                    ROUND(GREATEST(p.quantity_on_hand, 0) * p.cost_price, 2) AS stock_value,
                    p.reorder_point, p.is_active,
                    (p.reorder_point > 0 AND p.quantity_on_hand <= p.reorder_point) AS reorder_needed
               FROM products p WHERE p.store_id = :s AND (p.is_active = 1 OR p.quantity_on_hand > 0)
              ORDER BY p.name LIMIT {$limit} OFFSET {$offset}",
            ['s' => $storeId]
        );
        return ['summary' => self::stockSummary($storeId), 'items' => $items];
    }

    public static function stockSummary(int $storeId): array
    {
        $row = Database::fetchOne(
            'SELECT COALESCE(SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END), 0) AS active_products,
                    COALESCE(SUM(CASE WHEN is_active = 1 AND reorder_point > 0 AND quantity_on_hand <= reorder_point THEN 1 ELSE 0 END), 0) AS reorder_needed,
                    COALESCE(SUM(CASE WHEN is_active = 1 AND quantity_on_hand <= 0 THEN 1 ELSE 0 END), 0) AS out_of_stock
               FROM products WHERE store_id = :s',
            ['s' => $storeId]
        );
        $value = Database::fetchValue('SELECT stock_value FROM v_stock_value WHERE store_id = :s', ['s' => $storeId]);
        // A projection only (not realized profit): what current stock would earn if all sold at today's prices.
        $projected = Database::fetchValue(
            'SELECT ROUND(COALESCE(SUM(GREATEST(quantity_on_hand, 0) * (selling_price - cost_price)), 0), 2)
               FROM products WHERE store_id = :s AND is_active = 1',
            ['s' => $storeId]
        );
        return [
            'stock_value'     => Decimal::format(self::money($value), 2),
            'projected_gross_profit' => Decimal::format(self::money($projected), 2),
            'active_products' => (int) $row['active_products'],
            'reorder_needed'  => (int) $row['reorder_needed'],
            'out_of_stock'    => (int) $row['out_of_stock'],
        ];
    }

    public static function credits(int $storeId): array
    {
        $r = Database::fetchOne(
            "SELECT COUNT(*) AS open_count, COALESCE(SUM(balance), 0) AS total,
                    COALESCE(SUM(CASE WHEN due_date IS NOT NULL AND due_date < CURDATE() THEN balance ELSE 0 END), 0) AS overdue_amount,
                    COALESCE(SUM(CASE WHEN due_date IS NOT NULL AND due_date < CURDATE() THEN 1 ELSE 0 END), 0) AS overdue_count
               FROM v_credit_balances WHERE store_id = :s AND status = 'open'",
            ['s' => $storeId]
        );
        return [
            'open_count'     => (int) $r['open_count'],
            'total_balance'  => Decimal::format(self::money($r['total']), 2),
            'overdue_count'  => (int) $r['overdue_count'],
            'overdue_amount' => Decimal::format(self::money($r['overdue_amount']), 2),
        ];
    }

    /** Everything the dashboard cards need, all derived. */
    public static function dashboard(int $storeId): array
    {
        $today = date('Y-m-d');
        $week  = date('Y-m-d', strtotime('monday this week'));
        $month = date('Y-m-01');

        $store = Database::fetchOne('SELECT name, owner_name FROM stores WHERE id = :s', ['s' => $storeId]);
        $purchases = self::money(Database::fetchValue(
            "SELECT COALESCE(SUM(total_cost), 0) FROM purchases WHERE store_id = :s AND status = 'completed' AND purchase_date BETWEEN :f AND :t",
            ['s' => $storeId, 'f' => $month, 't' => $today]
        ));

        return [
            'store'            => $store,
            'cash'             => CashService::summary($storeId),
            'today'            => self::periodSummary($storeId, $today, $today),
            'snapshot'         => [
                'week_to_date'  => self::periodSummary($storeId, $week, $today),
                'month_to_date' => self::periodSummary($storeId, $month, $today),
            ],
            'stock'            => self::stockSummary($storeId),
            'credits'          => self::credits($storeId),
            'purchases_month_to_date' => Decimal::format($purchases, 2),
            'recent_activity'  => ActivityService::feed($storeId, null, null, null, 10, 0),
        ];
    }

    private static function money(mixed $v): int
    {
        return Decimal::units((string) ($v ?? '0'), Decimal::SCALE_MONEY);
    }
}
