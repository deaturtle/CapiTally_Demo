<?php
declare(strict_types=1);

namespace CapiTally\Services;

use CapiTally\Database;
use CapiTally\Support\AppException;
use CapiTally\Support\Audit;
use CapiTally\Support\Decimal;
use CapiTally\Support\Validator;

/** Receiving stock: purchase -> items -> positive movements -> weighted-average cost -> supplier payment. */
final class PurchaseService
{
    public static function create(int $storeId, int $userId, array $data): array
    {
        $date      = Validator::date($data, 'purchase_date', 'Purchase date');
        $supplier  = Validator::string($data, 'supplier_name', 'Supplier', false, 120);
        $reference = Validator::string($data, 'reference_no', 'Reference number', false, 60);
        $notes     = Validator::string($data, 'notes', 'Notes', false, 500);
        $paid      = Validator::decimal($data, 'amount_paid', 'Amount paid', Decimal::SCALE_MONEY, Validator::MAX_PRICE_UNITS * 100, true, true);
        $method    = $paid > 0 ? Validator::enum($data, 'payment_method', 'Payment method', ['cash', 'gcash', 'maya']) : null;

        $lines = [];
        foreach (Validator::items($data, 'items', 'Items') as $i => $item) {
            $n = $i + 1;
            $lines[] = [
                'product_id' => Validator::id($item, 'product_id', "Item {$n} product"),
                'qty'        => Validator::decimal($item, 'quantity', "Item {$n} quantity", Decimal::SCALE_QTY, Validator::MAX_QTY_UNITS),
                'cost'       => Validator::decimal($item, 'unit_cost', "Item {$n} cost per unit", Decimal::SCALE_COST, Validator::MAX_COST_UNITS, true, true),
            ];
        }

        return Database::transaction(function () use ($storeId, $userId, $date, $supplier, $reference, $notes, $paid, $method, $lines) {
            // Lock every product once, in id order, to avoid deadlocks.
            $products = [];
            $ids = array_unique(array_column($lines, 'product_id'));
            sort($ids);
            foreach ($ids as $pid) {
                $row = Database::lockProduct($pid, $storeId);
                if ($row === null) {
                    throw new AppException('One of the products could not be found.', 'PRODUCT_NOT_FOUND', 404);
                }
                if ((int) $row['is_active'] !== 1) {
                    throw new AppException("'{$row['name']}' is deactivated and cannot be received.", 'PRODUCT_INACTIVE', 409);
                }
                $products[$pid] = [
                    'name' => $row['name'],
                    'qty'  => Decimal::units($row['quantity_on_hand'], Decimal::SCALE_QTY),
                    'cost' => Decimal::units($row['cost_price'], Decimal::SCALE_COST),
                ];
            }

            $total = 0;
            foreach ($lines as &$l) {
                $l['total'] = Decimal::lineCost($l['qty'], $l['cost']);
                $total += $l['total'];
            }
            unset($l);

            if ($paid > $total) {
                throw Validator::fail('Amount paid cannot be more than the purchase total.');
            }

            $purchaseId = Database::insert(
                'INSERT INTO purchases (store_id, supplier_id, purchase_date, reference_no, total_cost, notes, user_id)
                 VALUES (:s, :sup, :d, :ref, :t, :n, :u)',
                ['s' => $storeId, 'sup' => LookupService::supplierId($storeId, $supplier), 'd' => $date, 'ref' => $reference,
                 't' => Decimal::format($total, 2), 'n' => $notes, 'u' => $userId]
            );

            foreach ($lines as $l) {
                $pid = $l['product_id'];
                Database::insert(
                    'INSERT INTO purchase_items (purchase_id, product_id, quantity, unit_cost, line_total)
                     VALUES (:p, :pid, :q, :c, :t)',
                    ['p' => $purchaseId, 'pid' => $pid, 'q' => Decimal::format($l['qty'], 3),
                     'c' => Decimal::format($l['cost'], 4), 't' => Decimal::format($l['total'], 2)]
                );

                InventoryService::postMovement([
                    'store_id' => $storeId, 'product_id' => $pid, 'type' => 'purchase',
                    'quantity_change' => $l['qty'], 'unit_cost' => $l['cost'],
                    'origin' => 'purchase_id', 'origin_id' => $purchaseId, 'user_id' => $userId,
                ]);

                // Weighted average uses the running quantity and cost for repeated lines of the same product.
                $newCost = Decimal::weightedAverageCost($products[$pid]['qty'], $products[$pid]['cost'], $l['qty'], $l['cost']);
                Database::execute('UPDATE products SET cost_price = :c WHERE id = :id AND store_id = :s',
                    ['c' => Decimal::format($newCost, 4), 'id' => $pid, 's' => $storeId]);
                $products[$pid]['qty']  += $l['qty'];
                $products[$pid]['cost']  = $newCost;
            }

            if ($paid > 0) {
                self::recordPayment($storeId, $userId, $purchaseId, $paid, $method, null);
            }

            Audit::log($storeId, $userId, 'purchase.create', 'purchase', $purchaseId, null,
                ['total_cost' => Decimal::format($total, 2), 'amount_paid' => Decimal::format($paid, 2), 'items' => count($lines)]);

            return self::find($storeId, $purchaseId);
        });
    }

    /** Pay down a purchase's outstanding balance. */
    public static function addPayment(int $storeId, int $userId, int $purchaseId, array $data): array
    {
        $amount = Validator::decimal($data, 'amount', 'Amount', Decimal::SCALE_MONEY, Validator::MAX_PRICE_UNITS * 100);
        $method = Validator::enum($data, 'payment_method', 'Payment method', ['cash', 'gcash', 'maya']);
        $notes  = Validator::string($data, 'notes', 'Notes', false, 255);

        return Database::transaction(function () use ($storeId, $userId, $purchaseId, $amount, $method, $notes) {
            $purchase = Database::fetchOne(
                'SELECT id, total_cost, status FROM purchases WHERE id = :id AND store_id = :s FOR UPDATE',
                ['id' => $purchaseId, 's' => $storeId]
            );
            if ($purchase === null) {
                throw new AppException('Purchase not found.', 'NOT_FOUND', 404);
            }
            if ($purchase['status'] !== 'completed') {
                throw new AppException('This purchase was cancelled.', 'INVALID_STATE', 409);
            }

            $paid = Decimal::units((string) Database::fetchValue(
                'SELECT COALESCE(SUM(amount), 0) FROM supplier_payments WHERE purchase_id = :id AND is_voided = 0', ['id' => $purchaseId]
            ), 2);
            $outstanding = Decimal::units($purchase['total_cost'], 2) - $paid;
            if ($amount > $outstanding) {
                throw Validator::fail('Payment is more than the outstanding balance.');
            }

            self::recordPayment($storeId, $userId, $purchaseId, $amount, $method, $notes);
            Audit::log($storeId, $userId, 'purchase.payment', 'purchase', $purchaseId, null,
                ['amount' => Decimal::format($amount, 2), 'method' => $method]);
            return self::find($storeId, $purchaseId);
        });
    }

    public static function find(int $storeId, int $id): array
    {
        $purchase = Database::fetchOne(
            'SELECT pu.id, pu.purchase_date, pu.reference_no, pu.total_cost, pu.status, pu.notes, pu.created_at,
                    su.name AS supplier_name, u.full_name AS user_name,
                    COALESCE(pp.amount_paid, 0) AS amount_paid, COALESCE(pp.outstanding, pu.total_cost) AS outstanding
               FROM purchases pu
               LEFT JOIN suppliers su ON su.id = pu.supplier_id
               JOIN users u ON u.id = pu.user_id
               LEFT JOIN v_purchase_payables pp ON pp.purchase_id = pu.id
              WHERE pu.id = :id AND pu.store_id = :s',
            ['id' => $id, 's' => $storeId]
        );
        if ($purchase === null) {
            throw new AppException('Purchase not found.', 'NOT_FOUND', 404);
        }
        $purchase['items'] = Database::fetchAll(
            'SELECT pi.product_id, p.name AS product_name, pi.quantity, pi.unit_cost, pi.line_total
               FROM purchase_items pi JOIN products p ON p.id = pi.product_id WHERE pi.purchase_id = :id ORDER BY pi.id',
            ['id' => $id]
        );
        return $purchase;
    }

    public static function list(int $storeId, ?string $from, ?string $to, int $limit, int $offset): array
    {
        $where  = ['pu.store_id = :s'];
        $params = ['s' => $storeId];
        if ($from !== null) {
            $where[] = 'pu.purchase_date >= :from';
            $params['from'] = $from;
        }
        if ($to !== null) {
            $where[] = 'pu.purchase_date <= :to';
            $params['to'] = $to;
        }
        $limit  = max(1, min(100, $limit));
        $offset = max(0, $offset);

        return Database::fetchAll(
            'SELECT pu.id, pu.purchase_date, pu.reference_no, pu.total_cost, pu.status, su.name AS supplier_name,
                    COALESCE(pp.outstanding, pu.total_cost) AS outstanding
               FROM purchases pu
               LEFT JOIN suppliers su ON su.id = pu.supplier_id
               LEFT JOIN v_purchase_payables pp ON pp.purchase_id = pu.id
              WHERE ' . implode(' AND ', $where) . " ORDER BY pu.purchase_date DESC, pu.id DESC LIMIT {$limit} OFFSET {$offset}",
            $params
        );
    }

    private static function recordPayment(int $storeId, int $userId, int $purchaseId, int $amount, string $method, ?string $notes): void
    {
        $paymentId = Database::insert(
            'INSERT INTO supplier_payments (store_id, purchase_id, amount, payment_method, paid_at, notes, user_id)
             VALUES (:s, :p, :a, :m, :t, :n, :u)',
            ['s' => $storeId, 'p' => $purchaseId, 'a' => Decimal::format($amount, 2), 'm' => $method,
             't' => date('Y-m-d H:i:s'), 'n' => $notes, 'u' => $userId]
        );
        CashLedgerService::post($storeId, $userId, 'supplier_payment', $method, -$amount, $paymentId);
    }

    /**
     * Cancels a completed purchase: stock is taken back out through the ledger and any supplier
     * payments are voided with their cash returned. Refused if the goods were already sold.
     * The average cost is not rewound (it cannot be recovered exactly once items were sold).
     */
    public static function cancel(int $storeId, int $userId, int $purchaseId, array $data): array
    {
        $reason = Validator::string($data, 'reason', 'Reason', true, 500);

        return Database::transaction(function () use ($storeId, $userId, $purchaseId, $reason) {
            $purchase = Database::fetchOne('SELECT id, status FROM purchases WHERE id = :id AND store_id = :s FOR UPDATE', ['id' => $purchaseId, 's' => $storeId]);
            if ($purchase === null) {
                throw new AppException('Purchase not found.', 'NOT_FOUND', 404);
            }
            if ($purchase['status'] !== 'completed') {
                throw new AppException('This purchase was already cancelled.', 'INVALID_STATE', 409);
            }

            $items = Database::fetchAll(
                'SELECT product_id, SUM(quantity) AS qty FROM purchase_items WHERE purchase_id = :id GROUP BY product_id ORDER BY product_id',
                ['id' => $purchaseId]
            );
            $reversals = [];
            foreach ($items as $it) {
                $product = Database::lockProduct((int) $it['product_id'], $storeId);
                $qty     = Decimal::units($it['qty'], Decimal::SCALE_QTY);
                if ($product === null || Decimal::units($product['quantity_on_hand'], Decimal::SCALE_QTY) < $qty) {
                    $name = $product['name'] ?? 'an item';
                    throw new AppException("Some of '{$name}' from this purchase was already sold, so it cannot be cancelled. Use a stock adjustment instead.", 'INSUFFICIENT_STOCK', 409);
                }
                $reversals[] = [$product, $qty];
            }

            foreach ($reversals as [$product, $qty]) {
                InventoryService::postMovement([
                    'store_id' => $storeId, 'product_id' => (int) $product['id'], 'type' => 'purchase_cancel',
                    'quantity_change' => -$qty, 'unit_cost' => Decimal::units($product['cost_price'], Decimal::SCALE_COST),
                    'origin' => 'purchase_id', 'origin_id' => $purchaseId, 'user_id' => $userId,
                ]);
            }

            foreach (Database::fetchAll('SELECT id FROM supplier_payments WHERE purchase_id = :id AND is_voided = 0', ['id' => $purchaseId]) as $pay) {
                Database::execute('UPDATE supplier_payments SET is_voided = 1, voided_at = :t, voided_by = :u WHERE id = :id',
                    ['t' => date('Y-m-d H:i:s'), 'u' => $userId, 'id' => $pay['id']]);
                CashLedgerService::reverseOrigin($storeId, $userId, 'supplier_payment', (int) $pay['id']);
            }

            Database::execute('UPDATE purchases SET status = \'cancelled\', cancelled_at = :t, cancelled_by = :u WHERE id = :id',
                ['t' => date('Y-m-d H:i:s'), 'u' => $userId, 'id' => $purchaseId]);
            Audit::log($storeId, $userId, 'purchase.cancel', 'purchase', $purchaseId, ['status' => 'completed'], ['status' => 'cancelled', 'reason' => $reason]);

            return self::find($storeId, $purchaseId);
        });
    }
}
