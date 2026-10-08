<?php
declare(strict_types=1);

namespace CapiTally\Services;

use CapiTally\Database;
use CapiTally\Support\AppException;
use CapiTally\Support\Audit;
use CapiTally\Support\Decimal;
use CapiTally\Support\Validator;

/**
 * The only code path that changes stock. Every change is an
 * inventory_movements row; schema triggers update quantity_on_hand and
 * reject negative stock unless an override is recorded.
 */
final class InventoryService
{
    private const ORIGINS = ['sale_id', 'purchase_id', 'adjustment_id', 'sale_return_id'];

    /**
     * Append one movement. Must run inside Database::transaction() after the
     * product row has been locked.
     *
     * @param array{store_id:int,product_id:int,type:string,reason_code?:?string,quantity_change:int,
     *              unit_cost?:?int,origin:string,origin_id:int,user_id:int,override_by?:?int} $m
     */
    public static function postMovement(array $m): int
    {
        if (!in_array($m['origin'], self::ORIGINS, true) || $m['quantity_change'] === 0) {
            throw new AppException('Invalid inventory movement.', 'INVALID_MOVEMENT', 500);
        }

        $override = ($m['override_by'] ?? null) !== null;
        $cols = [
            'store_id' => $m['store_id'], 'product_id' => $m['product_id'], 'movement_type' => $m['type'],
            'reason_code' => $m['reason_code'] ?? null,
            'quantity_change' => Decimal::format($m['quantity_change'], Decimal::SCALE_QTY),
            'unit_cost' => isset($m['unit_cost']) ? Decimal::format($m['unit_cost'], Decimal::SCALE_COST) : null,
            $m['origin'] => $m['origin_id'],
            'is_negative_override' => $override ? 1 : 0,
            'override_by' => $m['override_by'] ?? null,
            'user_id' => $m['user_id'],
        ];

        $names = implode(', ', array_keys($cols));
        $marks = implode(', ', array_map(static fn ($k) => ':' . $k, array_keys($cols)));
        return Database::insert("INSERT INTO inventory_movements ({$names}) VALUES ({$marks})", $cols);
    }

    /**
     * Count / adjustment / opening stock. Each item states the physically
     * counted quantity; the variance against the ledger quantity is posted.
     *
     * @param array<int,array> $items [{product_id, counted_qty, reason_code}]
     */
    public static function adjust(int $storeId, int $userId, string $role, array $data): array
    {
        $type  = Validator::enum($data, 'adjustment_type', 'Adjustment type', ['manual', 'periodic_count']);
        $note  = Validator::string($data, 'note', 'Note', false, 500);
        $items = Validator::items($data, 'items', 'Items');

        return Database::transaction(function () use ($storeId, $userId, $role, $type, $note, $items) {
            return self::adjustWithinTransaction($storeId, $userId, $role, $type, $note, $items);
        });
    }

    /** Shared by adjust() and ProductService (opening stock). Caller owns the transaction. */
    public static function adjustWithinTransaction(int $storeId, int $userId, string $role, string $type, ?string $note, array $items): array
    {
        $parsed = [];
        foreach ($items as $i => $item) {
            $n = $i + 1;
            $productId = Validator::id($item, 'product_id', "Item {$n} product");
            $counted   = Validator::decimal($item, 'counted_qty', "Item {$n} counted quantity", Decimal::SCALE_QTY, Validator::MAX_QTY_UNITS, true, true);
            $reasons   = $type === 'opening_stock' ? ['opening_stock'] : ['damage', 'theft', 'miscount', 'expired', 'other'];
            $reason    = Validator::enum($item, 'reason_code', "Item {$n} reason", $reasons);
            if (isset($parsed[$productId])) {
                throw Validator::fail('Each product can appear only once per adjustment.');
            }
            $parsed[$productId] = ['counted' => $counted, 'reason' => $reason];
        }

        $adjustmentId = Database::insert(
            'INSERT INTO stock_adjustments (store_id, adjustment_type, note, user_id, approved_by)
             VALUES (:s, :t, :n, :u, :a)',
            ['s' => $storeId, 't' => $type, 'n' => $note, 'u' => $userId, 'a' => $role === 'owner' ? $userId : null]
        );

        ksort($parsed); // consistent lock order prevents deadlocks
        $posted = [];
        foreach ($parsed as $productId => $p) {
            $product = Database::lockProduct($productId, $storeId);
            if ($product === null) {
                throw new AppException('One of the products could not be found.', 'PRODUCT_NOT_FOUND', 404);
            }
            if ((int) $product['is_active'] !== 1) {
                throw new AppException("'{$product['name']}' is deactivated.", 'PRODUCT_INACTIVE', 409);
            }

            $system   = Decimal::units($product['quantity_on_hand'], Decimal::SCALE_QTY);
            $variance = $p['counted'] - $system;

            Database::insert(
                'INSERT INTO stock_adjustment_items (adjustment_id, product_id, system_qty, counted_qty, variance, reason_code)
                 VALUES (:a, :p, :sys, :cnt, :var, :r)',
                ['a' => $adjustmentId, 'p' => $productId,
                 'sys' => Decimal::format($system, 3), 'cnt' => Decimal::format($p['counted'], 3),
                 'var' => Decimal::format($variance, 3), 'r' => $p['reason']]
            );

            if ($variance !== 0) {
                self::postMovement([
                    'store_id' => $storeId, 'product_id' => $productId,
                    'type' => self::movementType($type, $p['reason'], $variance),
                    'reason_code' => $p['reason'], 'quantity_change' => $variance,
                    'unit_cost' => Decimal::units($product['cost_price'], Decimal::SCALE_COST),
                    'origin' => 'adjustment_id', 'origin_id' => $adjustmentId, 'user_id' => $userId,
                ]);
            }
            $posted[] = ['product_id' => $productId, 'system_qty' => Decimal::format($system, 3),
                         'counted_qty' => Decimal::format($p['counted'], 3), 'variance' => Decimal::format($variance, 3)];
        }

        Audit::log($storeId, $userId, 'inventory.adjust', 'stock_adjustment', $adjustmentId, null,
            ['type' => $type, 'note' => $note, 'items' => $posted]);

        return ['adjustment_id' => $adjustmentId, 'items' => $posted];
    }

    /** Ledger history, newest first. */
    public static function movements(int $storeId, ?int $productId, ?string $from, ?string $to, int $limit, int $offset): array
    {
        $where  = ['m.store_id = :store'];
        $params = ['store' => $storeId];
        if ($productId !== null) {
            $where[] = 'm.product_id = :pid';
            $params['pid'] = $productId;
        }
        if ($from !== null) {
            $where[] = 'm.created_at >= :from';
            $params['from'] = $from . ' 00:00:00';
        }
        if ($to !== null) {
            $where[] = 'm.created_at <= :to';
            $params['to'] = $to . ' 23:59:59';
        }
        $limit  = max(1, min(100, $limit));
        $offset = max(0, $offset);

        return Database::fetchAll(
            'SELECT m.id, m.product_id, p.name AS product_name, m.movement_type, m.reason_code,
                    m.quantity_change, m.quantity_after, m.unit_cost, m.sale_id, m.purchase_id,
                    m.adjustment_id, m.sale_return_id, m.is_negative_override, u.full_name AS user_name, m.created_at
               FROM inventory_movements m
               JOIN products p ON p.id = m.product_id
               JOIN users u ON u.id = m.user_id
              WHERE ' . implode(' AND ', $where) . "
              ORDER BY m.id DESC LIMIT {$limit} OFFSET {$offset}",
            $params
        );
    }

    private static function movementType(string $adjustmentType, string $reason, int $variance): string
    {
        if ($adjustmentType === 'opening_stock') {
            return 'opening_stock';
        }
        return match (true) {
            $variance < 0 && $reason === 'theft'                         => 'loss',
            $variance < 0 && in_array($reason, ['damage', 'expired'], true) => 'spoilage',
            default                                                        => 'adjustment',
        };
    }
}
