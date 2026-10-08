<?php
declare(strict_types=1);

namespace CapiTally\Services;

use CapiTally\Database;
use CapiTally\Support\AppException;
use CapiTally\Support\Audit;
use CapiTally\Support\Decimal;
use CapiTally\Support\Validator;

/**
 * Utang. A credit is created by a credit sale (see SaleService) and linked to
 * that sale's items. Cash increases only when the customer actually pays.
 */
final class CreditService
{
    private const SELECT = 'SELECT v.credit_id AS id, v.sale_id, s.sale_no, s.sale_date, v.customer_name, v.customer_phone,
            v.original_amount, v.amount_paid, v.returned_offset, v.balance, v.due_date, v.status,
            (v.status = \'open\' AND v.due_date IS NOT NULL AND v.due_date < CURDATE()) AS is_overdue
        FROM v_credit_balances v JOIN sales s ON s.id = v.sale_id';

    public static function list(int $storeId, string $status, ?string $search, int $limit, int $offset): array
    {
        $where  = ['v.store_id = :s'];
        $params = ['s' => $storeId];
        if (in_array($status, ['open', 'paid', 'cancelled'], true)) {
            $where[] = 'v.status = :st';
            $params['st'] = $status;
        }
        if ($search !== null && trim($search) !== '') {
            $where[] = '(v.customer_name LIKE :q ESCAPE \'!\' OR v.customer_phone LIKE :q2 ESCAPE \'!\')';
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($search)) . '%';
            $params['q'] = $like;
            $params['q2'] = $like;
        }
        $limit  = max(1, min(100, $limit));
        $offset = max(0, $offset);
        return Database::fetchAll(self::SELECT . ' WHERE ' . implode(' AND ', $where) . " ORDER BY v.credit_id DESC LIMIT {$limit} OFFSET {$offset}", $params);
    }

    public static function find(int $storeId, int $id): array
    {
        $credit = Database::fetchOne(self::SELECT . ' WHERE v.store_id = :s AND v.credit_id = :id', ['s' => $storeId, 'id' => $id]);
        if ($credit === null) {
            throw new AppException('Credit record not found.', 'NOT_FOUND', 404);
        }
        $credit['items'] = Database::fetchAll(
            'SELECT product_name, quantity, unit_price, line_total, returned_quantity FROM sale_items WHERE sale_id = :sale ORDER BY id',
            ['sale' => $credit['sale_id']]
        );
        $credit['payments'] = Database::fetchAll(
            'SELECT id, amount, payment_method, paid_at, notes, is_voided FROM credit_payments WHERE credit_id = :id ORDER BY id',
            ['id' => $id]
        );
        return $credit;
    }

    /** Full or partial payment. Posts to the cash ledger. */
    public static function pay(int $storeId, int $userId, int $creditId, array $data): array
    {
        $amount = Validator::decimal($data, 'amount', 'Amount', Decimal::SCALE_MONEY, Validator::MAX_PRICE_UNITS * 100);
        $method = Validator::enum($data, 'payment_method', 'Payment method', ['cash', 'gcash', 'maya']);
        $notes  = Validator::string($data, 'notes', 'Notes', false, 255);

        return Database::transaction(function () use ($storeId, $userId, $creditId, $amount, $method, $notes) {
            $credit = Database::fetchOne('SELECT id, status FROM credits WHERE id = :id AND store_id = :s FOR UPDATE', ['id' => $creditId, 's' => $storeId]);
            if ($credit === null) {
                throw new AppException('Credit record not found.', 'NOT_FOUND', 404);
            }
            if ($credit['status'] !== 'open') {
                throw new AppException('This credit is already settled or cancelled.', 'INVALID_STATE', 409);
            }

            $balance = self::balance($creditId);
            if ($amount > $balance) {
                throw Validator::fail('Payment is more than the remaining balance of ' . Decimal::format($balance, 2) . '.');
            }

            $paymentId = Database::insert(
                'INSERT INTO credit_payments (store_id, credit_id, amount, payment_method, paid_at, notes, user_id)
                 VALUES (:s, :c, :a, :m, :t, :n, :u)',
                ['s' => $storeId, 'c' => $creditId, 'a' => Decimal::format($amount, 2), 'm' => $method,
                 't' => date('Y-m-d H:i:s'), 'n' => $notes, 'u' => $userId]
            );
            CashLedgerService::post($storeId, $userId, 'credit_payment', $method, $amount, $paymentId);
            self::refreshStatus($creditId);
            Audit::log($storeId, $userId, 'credit.payment', 'credit', $creditId, null,
                ['payment_id' => $paymentId, 'amount' => Decimal::format($amount, 2), 'method' => $method]);

            return self::find($storeId, $creditId);
        });
    }

    /** Voids a payment (soft delete) and reverses its cash entry. */
    public static function voidPayment(int $storeId, int $userId, int $paymentId): array
    {
        return Database::transaction(function () use ($storeId, $userId, $paymentId) {
            $p = Database::fetchOne('SELECT id, credit_id, is_voided FROM credit_payments WHERE id = :id AND store_id = :s FOR UPDATE', ['id' => $paymentId, 's' => $storeId]);
            if ($p === null) {
                throw new AppException('Payment not found.', 'NOT_FOUND', 404);
            }
            if ((int) $p['is_voided'] === 1) {
                throw new AppException('This payment is already voided.', 'INVALID_STATE', 409);
            }
            $credit = Database::fetchOne('SELECT status FROM credits WHERE id = :id FOR UPDATE', ['id' => $p['credit_id']]);
            if ($credit['status'] === 'cancelled') {
                throw new AppException('This credit was cancelled, so its payments cannot be voided.', 'INVALID_STATE', 409);
            }

            Database::execute('UPDATE credit_payments SET is_voided = 1, voided_at = :t, voided_by = :u WHERE id = :id',
                ['t' => date('Y-m-d H:i:s'), 'u' => $userId, 'id' => $paymentId]);
            CashLedgerService::reverseOrigin($storeId, $userId, 'credit_payment', $paymentId);
            self::refreshStatus((int) $p['credit_id']);
            Audit::log($storeId, $userId, 'credit.void_payment', 'credit_payment', $paymentId);

            return self::find($storeId, (int) $p['credit_id']);
        });
    }

    /** Remaining balance in centavos (original - active payments - return offsets). */
    public static function balance(int $creditId): int
    {
        return Decimal::units((string) Database::fetchValue('SELECT balance FROM v_credit_balances WHERE credit_id = :id', ['id' => $creditId]), Decimal::SCALE_MONEY);
    }

    /** open <-> paid based on the derived balance. Cancelled credits are left alone. */
    public static function refreshStatus(int $creditId): void
    {
        $status = Database::fetchValue('SELECT status FROM credits WHERE id = :id', ['id' => $creditId]);
        if ($status === 'cancelled') {
            return;
        }
        Database::execute('UPDATE credits SET status = :st WHERE id = :id',
            ['st' => self::balance($creditId) <= 0 ? 'paid' : 'open', 'id' => $creditId]);
    }
}
