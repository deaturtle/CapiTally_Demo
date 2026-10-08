<?php
declare(strict_types=1);

namespace CapiTally\Services;

use CapiTally\Database;
use CapiTally\Support\AppException;
use CapiTally\Support\Audit;
use CapiTally\Support\Decimal;
use CapiTally\Support\Validator;

/**
 * Cash and capital, always derived from records:
 *   Capital         = stock value (cost basis) + owner-designated reserve cash
 *   Safe-to-Withdraw = total cash - capital - outstanding supplier payables
 * Total cash = collected cash + GCash + Maya (credit is excluded until paid).
 */
final class CashService
{
    private const METHODS = ['cash', 'gcash', 'maya'];

    public static function summary(int $storeId): array
    {
        $by = array_fill_keys(self::METHODS, 0);
        foreach (Database::fetchAll('SELECT payment_method, balance FROM v_cash_balances WHERE store_id = :s', ['s' => $storeId]) as $r) {
            $by[$r['payment_method']] = Decimal::units($r['balance'], Decimal::SCALE_MONEY);
        }
        $total    = array_sum($by);
        $stock    = self::money(Database::fetchValue('SELECT stock_value FROM v_stock_value WHERE store_id = :s', ['s' => $storeId]));
        $reserve  = self::money(Database::fetchValue('SELECT reserve_cash FROM stores WHERE id = :s', ['s' => $storeId]));
        $payables = self::money(Database::fetchValue('SELECT COALESCE(SUM(outstanding), 0) FROM v_purchase_payables WHERE store_id = :s', ['s' => $storeId]));
        $credit   = self::money(Database::fetchValue("SELECT COALESCE(SUM(balance), 0) FROM v_credit_balances WHERE store_id = :s AND status = 'open'", ['s' => $storeId]));

        $capital = $stock + $reserve;
        $safe    = $total - $capital - $payables;

        $f = static fn (int $u): string => Decimal::format($u, Decimal::SCALE_MONEY);
        return [
            'total_cash'           => $f($total),
            'cash_by_method'       => array_map($f, $by),
            'stock_value'          => $f($stock),
            'reserve_cash'         => $f($reserve),
            'protected_capital'    => $f($capital),
            'outstanding_payables' => $f($payables),
            'safe_to_withdraw'     => $f($safe),
            'below_capital'        => $safe < 0,      // true when cash does not fully cover capital and payables
            'credit_outstanding'   => $f($credit),    // utang owed to the store, not part of cash
        ];
    }

    public static function ledger(int $storeId, ?string $from, ?string $to, int $limit, int $offset): array
    {
        $where  = ['store_id = :s'];
        $params = ['s' => $storeId];
        if ($from !== null) {
            $where[] = 'entry_date >= :from';
            $params['from'] = $from . ' 00:00:00';
        }
        if ($to !== null) {
            $where[] = 'entry_date <= :to';
            $params['to'] = $to . ' 23:59:59';
        }
        $limit  = max(1, min(100, $limit));
        $offset = max(0, $offset);
        return Database::fetchAll(
            'SELECT id, entry_type, payment_method, amount, entry_date, sale_id, credit_payment_id, sale_return_id,
                    expense_id, withdrawal_id, supplier_payment_id, cash_count_id
               FROM cash_ledger WHERE ' . implode(' AND ', $where) . " ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}",
            $params
        );
    }

    public static function setReserve(int $storeId, int $userId, array $data): array
    {
        $amount = Validator::decimal($data, 'reserve_cash', 'Reserve cash', Decimal::SCALE_MONEY, Validator::MAX_PRICE_UNITS * 100, true, true);
        return Database::transaction(function () use ($storeId, $userId, $amount) {
            $old = Database::fetchValue('SELECT reserve_cash FROM stores WHERE id = :s FOR UPDATE', ['s' => $storeId]);
            Database::execute('UPDATE stores SET reserve_cash = :r WHERE id = :s', ['r' => Decimal::format($amount, 2), 's' => $storeId]);
            Audit::log($storeId, $userId, 'cash.set_reserve', 'store', $storeId, ['reserve_cash' => $old], ['reserve_cash' => Decimal::format($amount, 2)]);
            return self::summary($storeId);
        });
    }

    /**
     * A withdrawal that eats into capital is flagged, never blocked.
     * The safe amount at the time is stored so the flag stays explainable.
     */
    public static function withdraw(int $storeId, int $userId, array $data): array
    {
        $amount = Validator::decimal($data, 'amount', 'Amount', Decimal::SCALE_MONEY, Validator::MAX_PRICE_UNITS * 100);
        $method = Validator::enum($data, 'payment_method', 'Withdraw from', self::METHODS, true, 'cash');
        $note   = Validator::string($data, 'note', 'Note', false, 255);

        return Database::transaction(function () use ($storeId, $userId, $amount, $method, $note) {
            Database::fetchValue('SELECT id FROM stores WHERE id = :s FOR UPDATE', ['s' => $storeId]); // serialize cash math
            $before = self::summary($storeId);
            $safe   = Decimal::units($before['safe_to_withdraw'], Decimal::SCALE_MONEY);
            $exceeded = $amount > max(0, $safe);

            $id = Database::insert(
                'INSERT INTO withdrawals (store_id, amount, withdrawn_at, note, safe_amount_at_time, exceeded_safe_limit, user_id)
                 VALUES (:s, :a, :t, :n, :safe, :ex, :u)',
                ['s' => $storeId, 'a' => Decimal::format($amount, 2), 't' => date('Y-m-d H:i:s'), 'n' => $note,
                 'safe' => Decimal::format($safe, 2), 'ex' => $exceeded ? 1 : 0, 'u' => $userId]
            );
            CashLedgerService::post($storeId, $userId, 'withdrawal', $method, -$amount, $id);
            Audit::log($storeId, $userId, 'cash.withdraw', 'withdrawal', $id, null,
                ['amount' => Decimal::format($amount, 2), 'method' => $method, 'exceeded_safe_limit' => $exceeded]);

            return ['withdrawal_id' => $id, 'exceeded_safe_limit' => $exceeded, 'summary' => self::summary($storeId)];
        });
    }

    public static function voidWithdrawal(int $storeId, int $userId, int $id): array
    {
        return Database::transaction(function () use ($storeId, $userId, $id) {
            $w = Database::fetchOne('SELECT id, is_voided FROM withdrawals WHERE id = :id AND store_id = :s FOR UPDATE', ['id' => $id, 's' => $storeId]);
            if ($w === null) {
                throw new AppException('Withdrawal not found.', 'NOT_FOUND', 404);
            }
            if ((int) $w['is_voided'] === 1) {
                throw new AppException('This withdrawal is already voided.', 'INVALID_STATE', 409);
            }
            Database::execute('UPDATE withdrawals SET is_voided = 1, voided_at = :t, voided_by = :u WHERE id = :id',
                ['t' => date('Y-m-d H:i:s'), 'u' => $userId, 'id' => $id]);
            CashLedgerService::reverseOrigin($storeId, $userId, 'withdrawal', $id);
            Audit::log($storeId, $userId, 'cash.void_withdrawal', 'withdrawal', $id);
            return self::summary($storeId);
        });
    }

    /**
     * Physical count of one payment method. The variance against the ledger is
     * posted as a count adjustment, so total cash stays derived from the ledger.
     */
    public static function recordCount(int $storeId, int $userId, array $data): array
    {
        $counted = Validator::decimal($data, 'counted_amount', 'Counted amount', Decimal::SCALE_MONEY, Validator::MAX_PRICE_UNITS * 100, true, true);
        $method  = Validator::enum($data, 'payment_method', 'Counted from', self::METHODS, true, 'cash');
        $note    = Validator::string($data, 'note', 'Note', false, 255);

        return Database::transaction(function () use ($storeId, $userId, $counted, $method, $note) {
            Database::fetchValue('SELECT id FROM stores WHERE id = :s FOR UPDATE', ['s' => $storeId]);
            $expected = Decimal::units(
                (string) Database::fetchValue('SELECT COALESCE(SUM(amount), 0) FROM cash_ledger WHERE store_id = :s AND payment_method = :m', ['s' => $storeId, 'm' => $method]),
                Decimal::SCALE_MONEY
            );
            $variance = $counted - $expected;

            $id = Database::insert(
                'INSERT INTO cash_counts (store_id, counted_amount, expected_amount, variance, note, user_id, counted_at)
                 VALUES (:s, :c, :e, :v, :n, :u, :t)',
                ['s' => $storeId, 'c' => Decimal::format($counted, 2), 'e' => Decimal::format($expected, 2),
                 'v' => Decimal::format($variance, 2), 'n' => $note, 'u' => $userId, 't' => date('Y-m-d H:i:s')]
            );
            if ($variance !== 0) {
                CashLedgerService::post($storeId, $userId, 'count_adjustment', $method, $variance, $id);
            }
            Audit::log($storeId, $userId, 'cash.count', 'cash_count', $id, null,
                ['method' => $method, 'counted' => Decimal::format($counted, 2), 'expected' => Decimal::format($expected, 2), 'variance' => Decimal::format($variance, 2)]);

            return ['cash_count_id' => $id, 'expected_amount' => Decimal::format($expected, 2),
                    'variance' => Decimal::format($variance, 2), 'summary' => self::summary($storeId)];
        });
    }

    private static function money(mixed $dbValue): int
    {
        return Decimal::units((string) ($dbValue ?? '0'), Decimal::SCALE_MONEY);
    }
}
