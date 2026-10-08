<?php
declare(strict_types=1);

namespace CapiTally\Services;

use CapiTally\Database;
use CapiTally\Support\AppException;
use CapiTally\Support\Audit;
use CapiTally\Support\Decimal;
use CapiTally\Support\Validator;

final class ProductService
{
    private const SELECT = 'SELECT p.id, p.barcode, p.name, p.category_id, c.name AS category_name,
            p.supplier_id, s.name AS supplier_name, p.cost_price, p.selling_price, p.quantity_on_hand,
            p.unit, p.reorder_point, p.image_url, p.is_active, p.created_at, p.updated_at
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        LEFT JOIN suppliers s ON s.id = p.supplier_id';

    private const SORTS = [
        'name' => 'p.name', 'stock_low' => 'p.quantity_on_hand ASC, p.name', 'stock_high' => 'p.quantity_on_hand DESC, p.name',
        'price_low' => 'p.selling_price ASC, p.name', 'price_high' => 'p.selling_price DESC, p.name', 'newest' => 'p.id DESC',
    ];

    /** @param string|null $stock 'low' (at or below the reorder point) or 'out' (none left) */
    public static function list(int $storeId, ?string $search, string $active, int $limit, int $offset, ?string $sort = null, ?string $stock = null): array
    {
        $where  = ['p.store_id = :store'];
        $params = ['store' => $storeId];
        if ($active === '1' || $active === '0') {
            $where[] = 'p.is_active = :active';
            $params['active'] = (int) $active;
        }
        if ($search !== null && trim($search) !== '') {
            $where[] = '(p.name LIKE :q ESCAPE \'!\' OR p.barcode = :exact)';
            $params['q']     = '%' . self::escapeLike(trim($search)) . '%';
            $params['exact'] = trim($search);
        }
        if ($stock === 'low') {
            $where[] = 'p.reorder_point > 0 AND p.quantity_on_hand > 0 AND p.quantity_on_hand <= p.reorder_point';
        } elseif ($stock === 'out') {
            $where[] = 'p.quantity_on_hand <= 0';
        } elseif ($stock !== null && $stock !== '') {
            throw Validator::fail('Stock filter must be low or out.');
        }
        $order  = self::SORTS[$sort ?? 'name'] ?? throw Validator::fail('Sort option is not valid.');
        $limit  = max(1, min(100, $limit));
        $offset = max(0, $offset);

        return Database::fetchAll(self::SELECT . ' WHERE ' . implode(' AND ', $where) . " ORDER BY {$order} LIMIT {$limit} OFFSET {$offset}", $params);
    }

    public static function find(int $storeId, int $id): array
    {
        $row = Database::fetchOne(self::SELECT . ' WHERE p.store_id = :store AND p.id = :id', ['store' => $storeId, 'id' => $id]);
        if ($row === null) {
            throw new AppException('Product not found.', 'PRODUCT_NOT_FOUND', 404);
        }
        return $row;
    }

    /** Search-before-create: products sharing the barcode or a similar name. */
    public static function findSimilar(int $storeId, ?string $name, ?string $barcode, ?int $excludeId = null): array
    {
        $found = [];
        if ($barcode !== null) {
            foreach (Database::fetchAll(
                'SELECT id, name, barcode, is_active FROM products WHERE store_id = :s AND barcode = :b' . ($excludeId ? ' AND id <> :x' : '') . ' LIMIT 5',
                array_filter(['s' => $storeId, 'b' => $barcode, 'x' => $excludeId], static fn ($v) => $v !== null)
            ) as $r) {
                $found[$r['id']] = $r + ['match' => 'barcode'];
            }
        }
        if ($name !== null) {
            foreach (Database::fetchAll(
                'SELECT id, name, barcode, is_active FROM products WHERE store_id = :s AND name LIKE :q ESCAPE \'!\'' . ($excludeId ? ' AND id <> :x' : '') . ' LIMIT 5',
                array_filter(['s' => $storeId, 'q' => '%' . self::escapeLike($name) . '%', 'x' => $excludeId], static fn ($v) => $v !== null)
            ) as $r) {
                $found[$r['id']] ??= $r + ['match' => 'name'];
            }
        }
        return array_values($found);
    }

    public static function create(int $storeId, int $userId, string $role, array $data): array
    {
        $name     = Validator::string($data, 'name', 'Product name', true, 160);
        $barcode  = Validator::barcode($data, 'barcode');
        $category = Validator::string($data, 'category_name', 'Category', false, 80);
        $supplier = Validator::string($data, 'supplier_name', 'Supplier', false, 120);
        $cost     = Validator::decimal($data, 'cost_price', 'Cost price', Decimal::SCALE_COST, Validator::MAX_COST_UNITS, true, true);
        $price    = Validator::decimal($data, 'selling_price', 'Selling price', Decimal::SCALE_MONEY, Validator::MAX_PRICE_UNITS, true, true);
        $unit     = Validator::string($data, 'unit', 'Unit', false, 20) ?? 'pcs';
        $reorder  = Validator::decimal($data, 'reorder_point', 'Reorder point', Decimal::SCALE_QTY, Validator::MAX_QTY_UNITS, false, true) ?? 0;
        $opening  = Validator::decimal($data, 'opening_quantity', 'Opening quantity', Decimal::SCALE_QTY, Validator::MAX_QTY_UNITS, false, true) ?? 0;

        self::assertDuplicatesConfirmed($storeId, $name, $barcode, null, Validator::bool($data, 'confirm_duplicate'));

        return Database::transaction(function () use ($storeId, $userId, $role, $name, $barcode, $category, $supplier, $cost, $price, $unit, $reorder, $opening) {
            $id = Database::insert(
                'INSERT INTO products (store_id, barcode, name, category_id, supplier_id, cost_price, selling_price, unit, reorder_point, created_by)
                 VALUES (:s, :b, :n, :c, :sup, :cost, :price, :unit, :ro, :u)',
                ['s' => $storeId, 'b' => $barcode, 'n' => $name,
                 'c' => LookupService::categoryId($storeId, $category), 'sup' => LookupService::supplierId($storeId, $supplier),
                 'cost' => Decimal::format($cost, 4), 'price' => Decimal::format($price, 2),
                 'unit' => $unit, 'ro' => Decimal::format($reorder, 3), 'u' => $userId]
            );

            if ($opening > 0) {
                InventoryService::adjustWithinTransaction($storeId, $userId, $role, 'opening_stock', 'Opening stock', [
                    ['product_id' => $id, 'counted_qty' => Decimal::format($opening, 3), 'reason_code' => 'opening_stock'],
                ]);
            }

            $product = self::find($storeId, $id);
            Audit::log($storeId, $userId, 'product.create', 'product', $id, null, $product);
            return $product;
        });
    }

    public static function update(int $storeId, int $userId, int $id, array $data): array
    {
        $current = self::find($storeId, $id);
        $set = [];
        $params = ['id' => $id, 'store' => $storeId];

        if (array_key_exists('name', $data)) {
            $set[] = 'name = :name';
            $params['name'] = Validator::string($data, 'name', 'Product name', true, 160);
        }
        if (array_key_exists('barcode', $data)) {
            $set[] = 'barcode = :barcode';
            $params['barcode'] = Validator::barcode($data, 'barcode');
        }
        $hasCategory  = array_key_exists('category_name', $data);
        $hasSupplier  = array_key_exists('supplier_name', $data);
        $categoryName = $hasCategory ? Validator::string($data, 'category_name', 'Category', false, 80) : null;
        $supplierName = $hasSupplier ? Validator::string($data, 'supplier_name', 'Supplier', false, 120) : null;
        if (array_key_exists('cost_price', $data)) {
            $set[] = 'cost_price = :cost';
            $params['cost'] = Decimal::format(Validator::decimal($data, 'cost_price', 'Cost price', 4, Validator::MAX_COST_UNITS, true, true), 4);
        }
        if (array_key_exists('selling_price', $data)) {
            $set[] = 'selling_price = :price';
            $params['price'] = Decimal::format(Validator::decimal($data, 'selling_price', 'Selling price', 2, Validator::MAX_PRICE_UNITS, true, true), 2);
        }
        if (array_key_exists('unit', $data)) {
            $set[] = 'unit = :unit';
            $params['unit'] = Validator::string($data, 'unit', 'Unit', true, 20);
        }
        if (array_key_exists('reorder_point', $data)) {
            $set[] = 'reorder_point = :ro';
            $params['ro'] = Decimal::format(Validator::decimal($data, 'reorder_point', 'Reorder point', 3, Validator::MAX_QTY_UNITS, true, true), 3);
        }

        $newName    = $params['name'] ?? null;
        $newBarcode = array_key_exists('barcode', $params) ? $params['barcode'] : null;
        if ($newName !== null || $newBarcode !== null) {
            self::assertDuplicatesConfirmed($storeId, $newName, $newBarcode, $id, Validator::bool($data, 'confirm_duplicate'));
        }

        return Database::transaction(function () use ($storeId, $userId, $id, $current, $set, $params, $hasCategory, $hasSupplier, $categoryName, $supplierName) {
            if ($hasCategory) {
                $set[] = 'category_id = :cat';
                $params['cat'] = LookupService::categoryId($storeId, $categoryName);
            }
            if ($hasSupplier) {
                $set[] = 'supplier_id = :sup';
                $params['sup'] = LookupService::supplierId($storeId, $supplierName);
            }
            if ($set === []) {
                throw Validator::fail('Nothing to update.');
            }
            Database::execute('UPDATE products SET ' . implode(', ', $set) . ' WHERE id = :id AND store_id = :store', $params);

            $updated = self::find($storeId, $id);
            Audit::log($storeId, $userId, 'product.update', 'product', $id, $current, $updated);
            return $updated;
        });
    }

    public static function setActive(int $storeId, int $userId, int $id, bool $active): array
    {
        $current = self::find($storeId, $id);
        return Database::transaction(function () use ($storeId, $userId, $id, $active, $current) {
            Database::execute('UPDATE products SET is_active = :a WHERE id = :id AND store_id = :s',
                ['a' => $active ? 1 : 0, 'id' => $id, 's' => $storeId]);
            $updated = self::find($storeId, $id);
            Audit::log($storeId, $userId, $active ? 'product.activate' : 'product.deactivate', 'product', $id,
                ['is_active' => $current['is_active']], ['is_active' => $updated['is_active']]);
            return $updated;
        });
    }

    private static function assertDuplicatesConfirmed(int $storeId, ?string $name, ?string $barcode, ?int $excludeId, bool $confirmed): void
    {
        if ($confirmed) {
            return;
        }
        $similar = self::findSimilar($storeId, $name, $barcode, $excludeId);
        if ($similar !== []) {
            throw new AppException(
                'A similar product already exists. Review it, or confirm to save anyway.',
                'DUPLICATE_WARNING', 409, null, ['similar' => $similar]
            );
        }
    }

    private static function escapeLike(string $s): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $s);
    }
}
