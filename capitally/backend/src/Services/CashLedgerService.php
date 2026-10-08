<?php
declare(strict_types=1);

namespace CapiTally\Services;

use CapiTally\Database;
use CapiTally\Support\AppException;
use CapiTally\Support\Decimal;

/** Posts rows to the append-only cash_ledger. Credit sales never post here until paid. */
final class CashLedgerService
{
    private const ORIGIN_COLUMNS = [
        'sale'             => 'sale_id',
        'credit_payment'   => 'credit_payment_id',
        'refund'           => 'sale_return_id',
        'expense'          => 'expense_id',
        'withdrawal'       => 'withdrawal_id',
        'supplier_payment' => 'supplier_payment_id',
        'count_adjustment' => 'cash_count_id',
    ];

    /**
     * @param int $amountUnits signed amount in centavos (positive in, negative out)
     */
    public static function post(int $storeId, int $userId, string $entryType, string $method, int $amountUnits, int $originId, ?string $at = null): int
    {
        $column = self::ORIGIN_COLUMNS[$entryType] ?? null;
        if ($column === null || $amountUnits === 0) {
            throw new AppException('Invalid cash entry.', 'INVALID_CASH_ENTRY', 500);
        }

        return Database::insert(
            "INSERT INTO cash_ledger (store_id, entry_type, payment_method, amount, entry_date, {$column}, user_id)
             VALUES (:store, :type, :method, :amount, :date, :origin, :user)",
            [
                'store'  => $storeId,
                'type'   => $entryType,
                'method' => $method,
                'amount' => Decimal::format($amountUnits, Decimal::SCALE_MONEY),
                'date'   => $at ?? date('Y-m-d H:i:s'),
                'origin' => $originId,
                'user'   => $userId,
            ]
        );
    }

    /**
     * Voiding a record never edits history: it posts the opposite of whatever
     * the record posted, per payment method, as new ledger rows.
     */
    public static function reverseOrigin(int $storeId, int $userId, string $entryType, int $originId): void
    {
        $column = self::ORIGIN_COLUMNS[$entryType] ?? null;
        if ($column === null) {
            throw new AppException('Invalid cash entry.', 'INVALID_CASH_ENTRY', 500);
        }
        $rows = Database::fetchAll(
            "SELECT payment_method, SUM(amount) AS amount FROM cash_ledger
              WHERE store_id = :s AND {$column} = :o GROUP BY payment_method",
            ['s' => $storeId, 'o' => $originId]
        );
        foreach ($rows as $r) {
            $units = Decimal::units($r['amount'], Decimal::SCALE_MONEY);
            if ($units !== 0) {
                self::post($storeId, $userId, $entryType, $r['payment_method'], -$units, $originId);
            }
        }
    }
}
