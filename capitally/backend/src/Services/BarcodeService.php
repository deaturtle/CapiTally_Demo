<?php
declare(strict_types=1);

namespace CapiTally\Services;

use CapiTally\Database;
use CapiTally\Support\AppException;
use CapiTally\Support\Config;
use CapiTally\Support\Logger;

/**
 * Lookup order: local products, then cached/external API, then manual entry.
 * The external API is never the only way to identify a product.
 */
final class BarcodeService
{
    private const NOT_FOUND_RETRY_HOURS = 24;

    /**
     * @param string $mode 'sale' (scan decreases stock after the sale completes)
     *                     or 'receiving' (scan increases stock after the purchase completes)
     */
    public static function lookup(int $storeId, string $barcode, string $mode): array
    {
        if (!in_array($mode, ['sale', 'receiving'], true)) {
            throw new AppException('Scan mode must be sale or receiving.', 'VALIDATION', 422);
        }
        if (!preg_match('/^[A-Za-z0-9\-]{4,64}$/', $barcode)) {
            throw new AppException('That barcode is not valid.', 'VALIDATION', 422);
        }

        $local = Database::fetchAll(
            'SELECT id, barcode, name, selling_price, cost_price, quantity_on_hand, unit, is_active
               FROM products WHERE store_id = :s AND barcode = :b ORDER BY is_active DESC, id',
            ['s' => $storeId, 'b' => $barcode]
        );

        $result = ['barcode' => $barcode, 'mode' => $mode, 'source' => 'none', 'products' => [],
                   'suggestion' => null, 'warnings' => [], 'requires_manual_entry' => false, 'api_status' => 'not_used'];

        if ($local !== []) {
            $active = array_values(array_filter($local, static fn ($p) => (int) $p['is_active'] === 1));
            $result['source']   = 'local';
            $result['products'] = $active;
            if (count($active) > 1) {
                $result['warnings'][] = 'DUPLICATE_BARCODE';   // two active products share this code; user must confirm which
            }
            if ($active === []) {
                $result['warnings'][] = 'PRODUCT_INACTIVE';
            }
            return $result;
        }

        // Not local: try cache, then the API. Failures fall through to manual entry.
        $suggestion = self::cached($barcode);
        if ($suggestion !== null) {
            $result['source'] = 'cache';
            $result['api_status'] = 'cache';
        } else {
            [$suggestion, $status] = self::fetchExternal($barcode);
            $result['api_status'] = $status;
            if ($suggestion !== null) {
                $result['source'] = 'api';
            }
        }

        $result['suggestion'] = $suggestion;
        $result['requires_manual_entry'] = true;   // cost and selling price must always be set by the owner
        return $result;
    }

    private static function cached(string $barcode): ?array
    {
        $row = Database::fetchOne('SELECT * FROM barcode_lookup_cache WHERE barcode = :b', ['b' => $barcode]);
        if ($row === null) {
            return null;
        }
        if ((int) $row['was_found'] === 1) {
            return ['name' => $row['name'], 'category' => $row['category'], 'image_url' => $row['image_url']];
        }
        return null;
    }

    /** @return array{0:?array,1:string} suggestion and status (ok|not_found|rate_limited|unavailable|disabled) */
    private static function fetchExternal(string $barcode): array
    {
        if (!Config::get('barcode_api.enabled') || (string) Config::get('barcode_api.api_key') === '') {
            return [null, 'disabled'];
        }

        // Skip the call if this barcode was recently reported as not found.
        $miss = Database::fetchValue(
            'SELECT 1 FROM barcode_lookup_cache WHERE barcode = :b AND was_found = 0 AND fetched_at > (NOW() - INTERVAL ' . self::NOT_FOUND_RETRY_HOURS . ' HOUR)',
            ['b' => $barcode]
        );
        if ($miss !== null) {
            return [null, 'not_found'];
        }

        $url = Config::get('barcode_api.base_url') . '?' . http_build_query(
            ['barcode' => $barcode, 'formatted' => 'y', 'key' => Config::get('barcode_api.api_key')]
        );
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => (int) Config::get('barcode_api.timeout', 5),
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            Logger::warning('Barcode API unreachable', ['error' => $err]);
            return [null, 'unavailable'];
        }
        if ($http === 429) {
            Logger::warning('Barcode API rate limit reached');
            return [null, 'rate_limited'];
        }
        if ($http === 404) {
            self::remember($barcode, null);
            return [null, 'not_found'];
        }
        $json = json_decode($body, true);
        $item = is_array($json) ? ($json['products'][0] ?? null) : null;
        if ($http !== 200 || !is_array($item) || empty($item['title'])) {
            if ($http !== 200) {
                Logger::warning('Barcode API unexpected response', ['http' => $http]);
                return [null, 'unavailable'];
            }
            self::remember($barcode, null);
            return [null, 'not_found'];
        }

        $suggestion = [
            'name'      => mb_substr((string) $item['title'], 0, 200),
            'category'  => isset($item['category']) ? mb_substr((string) $item['category'], 0, 120) : null,
            'image_url' => isset($item['images'][0]) && str_starts_with((string) $item['images'][0], 'https://')
                ? mb_substr((string) $item['images'][0], 0, 500) : null,
        ];
        self::remember($barcode, $suggestion);
        return [$suggestion, 'ok'];
    }

    private static function remember(string $barcode, ?array $s): void
    {
        Database::execute(
            'INSERT INTO barcode_lookup_cache (barcode, was_found, name, category, image_url, fetched_at)
             VALUES (:b, :f, :n, :c, :i, NOW())
             ON DUPLICATE KEY UPDATE was_found = VALUES(was_found), name = VALUES(name),
                category = VALUES(category), image_url = VALUES(image_url), fetched_at = NOW()',
            ['b' => $barcode, 'f' => $s === null ? 0 : 1, 'n' => $s['name'] ?? null, 'c' => $s['category'] ?? null, 'i' => $s['image_url'] ?? null]
        );
    }
}
