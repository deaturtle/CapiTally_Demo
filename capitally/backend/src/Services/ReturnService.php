<?php
declare(strict_types=1);

namespace CapiTally\Services;

use CapiTally\Database;
use CapiTally\Support\AppException;
use CapiTally\Support\Audit;
use CapiTally\Support\Decimal;
use CapiTally\Support\Validator;

/**
 * Returns and cancellations never delete or edit the original sale lines.
 * They create a linked sale_returns record, reverse stock (when goods go
 * back on the shelf), reduce utang or refund money, and flag the sale status.
 */
final class ReturnService
{
    public static function create(int $storeId, int $userId, int $saleId, array $data): array
    {
        $type    = Validator::enum($data, 'return_type', 'Return type', ['return', 'cancellation']);
        $reason  = Validator::string($data, 'reason', 'Reason', true, 500);
        $restock = array_key_exists('goods_restocked', $data) ? Validator::bool($data, 'goods_restocked') : true;
        $refundMethod = Validator::enum($data, 'refund_method', 'Refund method', ['cash', 'gcash', 'maya'], false);

        $requested = [];
        if ($type === 'return') {
            foreach (Validator::items($data, 'items', 'Items') as $i => $item) {
                $n  = $i + 1;
                $id = Validator::id($item, 'sale_item_id', "Item {$n}");
                $requested[$id] = ($requested[$id] ?? 0)
                    + Validator::decimal($item, 'quantity', "Item {$n} quantity", Decimal::SCALE_QTY, Validator::MAX_QTY_UNITS);
            }
        }

        return Database::transaction(function () use ($storeId, $userId, $saleId, $type, $reason, $restock, $refundMethod, $requested) {
            $sale = Database::fetchOne('SELECT * FROM sales WHERE id = :id AND store_id = :s FOR UPDATE', ['id' => $saleId, 's' => $storeId]);
            if ($sale === null) {
                throw new AppException('Sale not found.', 'NOT_FOUND', 404);
            }
            if (!in_array($sale['status'], ['completed', 'partially_returned'], true)) {
                throw new AppException('This sale was already cancelled or fully returned.', 'INVALID_STATE', 409);
            }

            $items = Database::fetchAll('SELECT * FROM sale_items WHERE sale_id = :id ORDER BY id', ['id' => $saleId]);
            $previous = [];
            foreach (Database::fetchAll(
                'SELECT sri.sale_item_id, SUM(sri.line_value) AS value FROM sale_return_items sri
                   JOIN sale_returns sr ON sr.id = sri.sale_return_id WHERE sr.sale_id = :id GROUP BY sri.sale_item_id',
                ['id' => $saleId]
            ) as $r) {
                $previous[(int) $r['sale_item_id']] = Decimal::units($r['value'], 2);
            }

            // Work out what is coming back.
            $byId = [];
            foreach ($items as $it) {
                $byId[(int) $it['id']] = $it;
            }
            $wanted = [];
            if ($type === 'cancellation') {
                foreach ($items as $it) {
                    $remaining = Decimal::units($it['quantity'], 3) - Decimal::units($it['returned_quantity'], 3);
                    if ($remaining > 0) {
                        $wanted[(int) $it['id']] = $remaining;
                    }
                }
            } else {
                foreach ($requested as $itemId => $qty) {
                    if (!isset($byId[$itemId])) {
                        throw Validator::fail('One of the items does not belong to this sale.');
                    }
                    $wanted[$itemId] = $qty;
                }
            }
            if ($wanted === []) {
                throw new AppException('There is nothing left to return on this sale.', 'INVALID_STATE', 409);
            }
            ksort($wanted);

            $lines = [];
            $total = 0;
            foreach ($wanted as $itemId => $qty) {
                $it        = $byId[$itemId];
                $remaining = Decimal::units($it['quantity'], 3) - Decimal::units($it['returned_quantity'], 3);
                if ($qty > $remaining) {
                    throw Validator::fail("You can return at most " . Decimal::format($remaining, 3) . " of '{$it['product_name']}'.");
                }
                $price = Decimal::units($it['unit_price'], 2);
                $value = self::lineValue($qty, $price, Decimal::units($it['line_total'], 2), $previous[$itemId] ?? 0, $remaining);
                $lines[] = ['item' => $it, 'qty' => $qty, 'price' => $price, 'value' => $value];
                $total  += $value;
            }

            // Utang first reduces what the customer still owes; any excess is refunded.
            $credit = null;
            $balance = 0;
            if ($sale['payment_method'] === 'credit') {
                $credit = Database::fetchOne('SELECT id FROM credits WHERE sale_id = :id FOR UPDATE', ['id' => $saleId]);
                $balance = CreditService::balance((int) $credit['id']);
            }
            [$offset, $refund] = self::splitValue($total, $balance);

            $method = null;
            if ($refund > 0) {
                $method = $refundMethod ?? ($sale['payment_method'] !== 'credit' ? $sale['payment_method'] : null);
                if ($method === null) {
                    throw Validator::fail('Refund method is required because money must be paid back to the customer.');
                }
            }

            $returnId = Database::insert(
                'INSERT INTO sale_returns (store_id, sale_id, return_type, reason, total_value, credit_offset_amount, refund_amount, refund_method, goods_restocked, user_id)
                 VALUES (:s, :sale, :t, :r, :v, :o, :f, :m, :g, :u)',
                ['s' => $storeId, 'sale' => $saleId, 't' => $type, 'r' => $reason, 'v' => Decimal::format($total, 2),
                 'o' => Decimal::format($offset, 2), 'f' => Decimal::format($refund, 2), 'm' => $method, 'g' => $restock ? 1 : 0, 'u' => $userId]
            );

            foreach ($lines as $l) {
                $it = $l['item'];
                Database::insert(
                    'INSERT INTO sale_return_items (sale_return_id, sale_item_id, product_id, quantity, unit_price, unit_cost, line_value)
                     VALUES (:r, :i, :p, :q, :up, :uc, :v)',
                    ['r' => $returnId, 'i' => $it['id'], 'p' => $it['product_id'], 'q' => Decimal::format($l['qty'], 3),
                     'up' => $it['unit_price'], 'uc' => $it['unit_cost'], 'v' => Decimal::format($l['value'], 2)]
                );
                Database::execute(
                    'UPDATE sale_items SET returned_quantity = returned_quantity + :q WHERE id = :id',
                    ['q' => Decimal::format($l['qty'], 3), 'id' => $it['id']]
                );
            }

            // Goods back on the shelf restore stock; damaged goods do not.
            if ($restock) {
                usort($lines, static fn ($a, $b) => (int) $a['item']['product_id'] <=> (int) $b['item']['product_id']);
                foreach ($lines as $l) {
                    $pid = (int) $l['item']['product_id'];
                    Database::lockProduct($pid, $storeId);
                    InventoryService::postMovement([
                        'store_id' => $storeId, 'product_id' => $pid,
                        'type' => $type === 'cancellation' ? 'sale_cancel' : 'sale_return',
                        'quantity_change' => $l['qty'], 'unit_cost' => Decimal::units($l['item']['unit_cost'], 4),
                        'origin' => 'sale_return_id', 'origin_id' => $returnId, 'user_id' => $userId,
                    ]);
                }
            }

            if ($refund > 0) {
                CashLedgerService::post($storeId, $userId, 'refund', $method, -$refund, $returnId);
            }

            // Status of the credit and of the sale.
            if ($credit !== null) {
                if ($type === 'cancellation') {
                    Database::execute("UPDATE credits SET status = 'cancelled' WHERE id = :id", ['id' => $credit['id']]);
                } else {
                    CreditService::refreshStatus((int) $credit['id']);
                }
            }

            $open = (int) Database::fetchValue('SELECT COUNT(*) FROM sale_items WHERE sale_id = :id AND returned_quantity < quantity', ['id' => $saleId]);
            if ($type === 'cancellation') {
                $newStatus = 'cancelled';
                Database::execute('UPDATE sales SET status = :st, cancelled_at = :t, cancelled_by = :u WHERE id = :id',
                    ['st' => $newStatus, 't' => date('Y-m-d H:i:s'), 'u' => $userId, 'id' => $saleId]);
            } else {
                $newStatus = $open === 0 ? 'returned' : 'partially_returned';
                Database::execute('UPDATE sales SET status = :st WHERE id = :id', ['st' => $newStatus, 'id' => $saleId]);
            }

            Audit::log($storeId, $userId, $type === 'cancellation' ? 'sale.cancel' : 'sale.return', 'sale', $saleId,
                ['status' => $sale['status']],
                ['status' => $newStatus, 'return_id' => $returnId, 'total_value' => Decimal::format($total, 2),
                 'credit_offset' => Decimal::format($offset, 2), 'refund' => Decimal::format($refund, 2), 'restocked' => $restock]);

            return ['return_id' => $returnId, 'total_value' => Decimal::format($total, 2),
                    'credit_offset_amount' => Decimal::format($offset, 2), 'refund_amount' => Decimal::format($refund, 2),
                    'refund_method' => $method, 'sale_status' => $newStatus];
        });
    }

    /**
     * Value of returned units at the ORIGINAL sale price. When the last of a
     * line comes back, use whatever is left of the line total so rounding
     * never leaves a centavo behind across several returns.
     */
    public static function lineValue(int $qty3, int $price2, int $lineTotal2, int $alreadyReturnedValue2, int $remainingQty3): int
    {
        if ($qty3 === $remainingQty3) {
            return max(0, $lineTotal2 - $alreadyReturnedValue2);
        }
        return Decimal::lineAmount($qty3, $price2);
    }

    /** @return array{0:int,1:int} [credit offset, cash refund] in centavos */
    public static function splitValue(int $totalValue2, int $creditBalance2): array
    {
        $offset = min($totalValue2, max(0, $creditBalance2));
        return [$offset, $totalValue2 - $offset];
    }
}
