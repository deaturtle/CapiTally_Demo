<?php
declare(strict_types=1);

namespace CapiTally\Services;

use CapiTally\Database;

/**
 * Stock alerts, derived live from products so they can never go stale.
 *  - out of stock: active product with quantity on hand <= 0
 *  - low stock:    active product with a reorder point set, stock above 0 but at or below that point
 */
final class NotificationService
{
    public static function stockAlerts(int $storeId): array
    {
        $cols = 'id, name, barcode, unit, quantity_on_hand, reorder_point';

        $out = Database::fetchAll(
            "SELECT {$cols} FROM products WHERE store_id = :s AND is_active = 1 AND quantity_on_hand <= 0 ORDER BY name",
            ['s' => $storeId]
        );
        $low = Database::fetchAll(
            "SELECT {$cols} FROM products
              WHERE store_id = :s AND is_active = 1 AND reorder_point > 0
                AND quantity_on_hand > 0 AND quantity_on_hand <= reorder_point ORDER BY name",
            ['s' => $storeId]
        );

        return [
            'counts'       => ['out_of_stock' => count($out), 'low_stock' => count($low), 'total' => count($out) + count($low)],
            'out_of_stock' => $out,
            'low_stock'    => $low,
        ];
    }
}
