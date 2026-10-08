<?php
declare(strict_types=1);

namespace CapiTally\Services;

use CapiTally\Database;
use CapiTally\Support\AppException;
use CapiTally\Support\Audit;
use CapiTally\Support\Decimal;
use CapiTally\Support\Permissions;
use CapiTally\Support\Validator;
use DateTimeImmutable;
use DateTimeZone;

/**
 * A sale is one atomic transaction:
 * sale -> line items (price and cost snapshots) -> negative movements -> stock update
 * -> cash ledger entry (cash/GCash/Maya) or credit record (credit, no cash yet).
 *
 * Offline support: the client sends a client_uuid (a retry returns the sale already saved) and
 * optionally sold_at plus each item's expected_price. If the price changed since the sale was
 * entered the sync is refused with PRICE_CHANGED so the owner can review it; prices are never
 * accepted from the client.
 */
final class SaleService
{
    private const MAX_OFFLINE_DAYS = 14;

    public static function create(int $storeId, array $user, array $data): array
    {
        $userId  = (int) $user['id'];
        $method  = Validator::enum($data, 'payment_method', 'Payment method', ['cash', 'gcash', 'maya', 'credit']);
        $name    = Validator::string($data, 'customer_name', 'Customer name', $method === 'credit', 120);
        $phone   = Validator::string($data, 'customer_phone', 'Customer phone', false, 30);
        $due     = $method === 'credit' ? Validator::date($data, 'due_date', 'Due date', false) : null;
        $notes   = Validator::string($data, 'notes', 'Notes', false, 500);
        $wantsOverride = Validator::bool($data, 'allow_negative_stock');
        $clientUuid = Validator::string($data, 'client_uuid', 'Reference', false, 36);
        if ($clientUuid !== null && !preg_match('/^[0-9a-fA-F-]{36}$/', $clientUuid)) {
            throw Validator::fail('Reference is not valid.');
        }
        $saleDate = self::saleTime(Validator::string($data, 'sold_at', 'Sale time', false, 40));

        // Repeated scans of the same product increment one line instead of creating duplicates.
        $wanted = [];
        $expected = [];
        foreach (Validator::items($data, 'items', 'Items') as $i => $item) {
            $n = $i + 1;
            $pid = Validator::id($item, 'product_id', "Item {$n} product");
            $qty = Validator::decimal($item, 'quantity', "Item {$n} quantity", Decimal::SCALE_QTY, Validator::MAX_QTY_UNITS);
            $wanted[$pid] = ($wanted[$pid] ?? 0) + $qty;
            if ($wanted[$pid] > Validator::MAX_QTY_UNITS) {
                throw Validator::fail('Quantity is too large.');
            }
            $exp = Validator::decimal($item, 'expected_price', "Item {$n} price", Decimal::SCALE_MONEY, Validator::MAX_PRICE_UNITS, false, true);
            if ($exp !== null) {
                $expected[$pid] ??= $exp;
            }
        }
        ksort($wanted);

        return Database::transaction(function () use ($storeId, $user, $userId, $method, $name, $phone, $due, $notes, $wantsOverride, $clientUuid, $saleDate, $wanted, $expected) {
            $store = Database::fetchOne(
                'SELECT next_sale_no, allow_negative_override FROM stores WHERE id = :id FOR UPDATE', ['id' => $storeId]
            );

            // The store row lock serializes sales, so this check makes retries safe.
            if ($clientUuid !== null) {
                $existing = Database::fetchValue('SELECT id FROM sales WHERE store_id = :s AND client_uuid = :u', ['s' => $storeId, 'u' => $clientUuid]);
                if ($existing !== null) {
                    return self::find($storeId, (int) $existing);
                }
            }

            $overrideAllowed = $wantsOverride
                && (int) $store['allow_negative_override'] === 1
                && Permissions::can($user['role'], 'sales.override_stock');

            $lines = [];
            $revenue = 0;
            $cogs = 0;
            $overrideUsed = false;
            foreach ($wanted as $pid => $qty) {
                $p = Database::lockProduct($pid, $storeId);
                if ($p === null) {
                    throw new AppException('One of the products could not be found.', 'PRODUCT_NOT_FOUND', 404);
                }
                if ((int) $p['is_active'] !== 1) {
                    throw new AppException("'{$p['name']}' is deactivated and cannot be sold.", 'PRODUCT_INACTIVE', 409);
                }

                $price = Decimal::units($p['selling_price'], Decimal::SCALE_MONEY);
                if (isset($expected[$pid]) && $expected[$pid] !== $price) {
                    throw new AppException(
                        "The price of '{$p['name']}' changed from " . Decimal::format($expected[$pid], 2) . ' to ' . Decimal::format($price, 2) . ' since this sale was entered.',
                        'PRICE_CHANGED', 409, null,
                        ['product_id' => $pid, 'expected_price' => Decimal::format($expected[$pid], 2), 'current_price' => Decimal::format($price, 2)]
                    );
                }

                $onHand = Decimal::units($p['quantity_on_hand'], Decimal::SCALE_QTY);
                $short  = $qty > $onHand;
                if ($short && !$overrideAllowed) {
                    throw new AppException(
                        "Not enough stock for '{$p['name']}'. Available: " . rtrim(rtrim(Decimal::format(max(0, $onHand), 3), '0'), '.') . " {$p['unit']}.",
                        'INSUFFICIENT_STOCK', 409, null,
                        ['product_id' => $pid, 'available' => Decimal::format(max(0, $onHand), 3)]
                    );
                }
                $overrideUsed = $overrideUsed || $short;

                $cost  = Decimal::units($p['cost_price'], Decimal::SCALE_COST);
                $line  = ['pid' => $pid, 'name' => $p['name'], 'barcode' => $p['barcode'], 'qty' => $qty, 'price' => $price,
                          'cost' => $cost, 'total' => Decimal::lineAmount($qty, $price), 'line_cost' => Decimal::lineCost($qty, $cost),
                          'short' => $short];
                $revenue += $line['total'];
                $cogs    += $line['line_cost'];
                $lines[]  = $line;
            }

            if ($method === 'credit' && $revenue <= 0) {
                throw Validator::fail('A credit sale must have a total greater than zero.');
            }

            $saleNo = (int) $store['next_sale_no'];
            Database::execute('UPDATE stores SET next_sale_no = next_sale_no + 1 WHERE id = :id', ['id' => $storeId]);

            $saleId = Database::insert(
                'INSERT INTO sales (store_id, sale_no, sale_date, total_amount, total_cost, payment_method, customer_name, customer_phone, notes, client_uuid, user_id)
                 VALUES (:s, :no, :d, :t, :c, :m, :cn, :cp, :n, :cu, :u)',
                ['s' => $storeId, 'no' => $saleNo, 'd' => $saleDate, 't' => Decimal::format($revenue, 2),
                 'c' => Decimal::format($cogs, 2), 'm' => $method, 'cn' => $name, 'cp' => $phone, 'n' => $notes, 'cu' => $clientUuid, 'u' => $userId]
            );

            foreach ($lines as $l) {
                Database::insert(
                    'INSERT INTO sale_items (sale_id, product_id, product_name, barcode, quantity, unit_price, unit_cost, line_total, line_cost)
                     VALUES (:sale, :pid, :nm, :bc, :q, :up, :uc, :lt, :lc)',
                    ['sale' => $saleId, 'pid' => $l['pid'], 'nm' => $l['name'], 'bc' => $l['barcode'],
                     'q' => Decimal::format($l['qty'], 3), 'up' => Decimal::format($l['price'], 2), 'uc' => Decimal::format($l['cost'], 4),
                     'lt' => Decimal::format($l['total'], 2), 'lc' => Decimal::format($l['line_cost'], 2)]
                );
                InventoryService::postMovement([
                    'store_id' => $storeId, 'product_id' => $l['pid'], 'type' => 'sale',
                    'quantity_change' => -$l['qty'], 'unit_cost' => $l['cost'],
                    'origin' => 'sale_id', 'origin_id' => $saleId, 'user_id' => $userId,
                    'override_by' => $l['short'] ? $userId : null,
                ]);
            }

            if ($method === 'credit') {
                Database::insert(
                    'INSERT INTO credits (store_id, sale_id, customer_name, customer_phone, original_amount, due_date)
                     VALUES (:s, :sale, :n, :p, :a, :d)',
                    ['s' => $storeId, 'sale' => $saleId, 'n' => $name, 'p' => $phone, 'a' => Decimal::format($revenue, 2), 'd' => $due]
                );
            } elseif ($revenue > 0) {
                CashLedgerService::post($storeId, $userId, 'sale', $method, $revenue, $saleId, $saleDate);
            }

            Audit::log($storeId, $userId, 'sale.create', 'sale', $saleId, null,
                ['sale_no' => $saleNo, 'total' => Decimal::format($revenue, 2), 'method' => $method, 'items' => count($lines)]
                + ($clientUuid !== null ? ['client_uuid' => $clientUuid] : []));
            if ($overrideUsed) {
                Audit::log($storeId, $userId, 'sale.negative_stock_override', 'sale', $saleId);
            }

            return self::find($storeId, $saleId);
        });
    }

    /** @param int|null $onlyUserId restrict to one cashier's own sales (staff accounts) */
    public static function find(int $storeId, int $id, ?int $onlyUserId = null): array
    {
        $sale = Database::fetchOne(
            'SELECT s.id, s.sale_no, s.sale_date, s.total_amount, s.total_cost, s.payment_method, s.customer_name,
                    s.customer_phone, s.status, s.notes, u.full_name AS cashier
               FROM sales s JOIN users u ON u.id = s.user_id WHERE s.id = :id AND s.store_id = :st'
            . ($onlyUserId !== null ? ' AND s.user_id = :uid' : ''),
            ['id' => $id, 'st' => $storeId] + ($onlyUserId !== null ? ['uid' => $onlyUserId] : [])
        );
        if ($sale === null) {
            throw new AppException('Sale not found.', 'NOT_FOUND', 404);
        }
        $sale['items'] = Database::fetchAll(
            'SELECT id, product_id, product_name, barcode, quantity, unit_price, line_total, returned_quantity
               FROM sale_items WHERE sale_id = :id ORDER BY id',
            ['id' => $id]
        );
        return $sale;
    }

    /**
     * Time the sale really happened. Accepts the client's time only for offline sales:
     * never in the future (small clock drift tolerated) and not older than MAX_OFFLINE_DAYS.
     */
    private static function saleTime(?string $soldAt): string
    {
        $tz  = new DateTimeZone((string) date_default_timezone_get());
        $now = new DateTimeImmutable('now', $tz);
        if ($soldAt === null) {
            return $now->format('Y-m-d H:i:s');
        }
        try {
            $t = (new DateTimeImmutable($soldAt))->setTimezone($tz);
        } catch (\Exception) {
            throw Validator::fail('Sale time is not valid.');
        }
        if ($t > $now->modify('+5 minutes')) {
            $t = $now;
        }
        if ($t < $now->modify('-' . self::MAX_OFFLINE_DAYS . ' days')) {
            throw Validator::fail('This sale is more than ' . self::MAX_OFFLINE_DAYS . ' days old and can no longer be synced automatically.');
        }
        return $t->format('Y-m-d H:i:s');
    }
}
