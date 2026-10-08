<?php
declare(strict_types=1);

namespace CapiTally;

use CapiTally\Support\AppException;
use CapiTally\Support\Config;
use CapiTally\Support\Logger;
use PDO;
use PDOException;
use Throwable;

/**
 * Single PDO connection plus transaction and error-translation helpers.
 *
 * Rules:
 *  - Prepared statements only. Never concatenate user input into SQL.
 *  - Multi-step financial/inventory work goes through transaction().
 *  - Raw database errors are logged, then replaced with an AppException
 *    carrying a safe message.
 *
 * Money note: PDO returns DECIMAL columns as strings. Services must keep
 * them as strings and use bcmath (or integer centavos) for arithmetic, never
 * floats, so weighted-average cost and profit stay exact.
 */
final class Database
{
    private static ?PDO $pdo = null;
    private static int $depth = 0;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host    = (string) Config::get('db.host');
        $port    = (int) Config::get('db.port');
        $name    = (string) Config::get('db.name');
        $charset = (string) Config::get('db.charset', 'utf8mb4');
        $dsn     = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

        try {
            self::$pdo = new PDO($dsn, (string) Config::get('db.user'), (string) Config::get('db.password'), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,   // real server-side prepares
                PDO::ATTR_STRINGIFY_FETCHES  => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset}, time_zone = '+08:00'",
            ]);
        } catch (PDOException $e) {
            Logger::error('Database connection failed', ['code' => $e->getCode(), 'detail' => $e->getMessage()]);
            throw new AppException(
                'We could not reach the database. Please try again in a moment.',
                'DB_UNAVAILABLE',
                503,
                $e
            );
        }

        return self::$pdo;
    }

    /** Run a SELECT and return all rows. */
    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** Run a SELECT and return the first row, or null. */
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** Run a SELECT and return the first column of the first row, or null. */
    public static function fetchValue(string $sql, array $params = []): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /** Run INSERT/UPDATE and return the affected row count. */
    public static function execute(string $sql, array $params = []): int
    {
        return self::run($sql, $params)->rowCount();
    }

    /** Run an INSERT and return the new auto-increment id. */
    public static function insert(string $sql, array $params = []): int
    {
        self::run($sql, $params);
        return (int) self::connection()->lastInsertId();
    }

    /**
     * Run $work atomically. Commits on success, rolls back on any throwable.
     * Nested calls join the outer transaction (the outermost call owns commit).
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function transaction(callable $work): mixed
    {
        $pdo = self::connection();
        $isOutermost = self::$depth === 0;

        try {
            if ($isOutermost) {
                $pdo->beginTransaction();
            }
            self::$depth++;
            $result = $work();
            self::$depth--;
            if ($isOutermost) {
                $pdo->commit();
            }
            return $result;
        } catch (Throwable $e) {
            self::$depth = max(0, self::$depth - 1);
            if ($isOutermost && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof AppException) {
                throw $e;
            }
            if ($e instanceof PDOException) {
                throw self::translate($e);
            }
            throw $e;
        }
    }

    /** Lock-aware helper: call inside transaction() to lock a product row. */
    public static function lockProduct(int $productId, int $storeId): ?array
    {
        return self::fetchOne(
            'SELECT * FROM products WHERE id = :id AND store_id = :store FOR UPDATE',
            ['id' => $productId, 'store' => $storeId]
        );
    }

    private static function run(string $sql, array $params): \PDOStatement
    {
        try {
            $stmt = self::connection()->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            throw self::translate($e);
        }
    }

    /**
     * Convert a PDOException into a safe AppException.
     * Business-rule violations raised by schema triggers (SIGNAL 45000,
     * driver error 1644) carry a "CODE: message" prefix that we map here.
     */
    public static function translate(PDOException $e): AppException
    {
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        $detail     = (string) ($e->errorInfo[2] ?? $e->getMessage());

        if ($driverCode === 1644) {
            $key = strtok($detail, ':');
            $known = [
                'INSUFFICIENT_STOCK'    => ['Not enough stock for this item.', 409],
                'STOCK_LEDGER_REQUIRED' => ['Stock can only change through a recorded sale, purchase, return or adjustment.', 409],
                'PRODUCT_NOT_FOUND'     => ['That product could not be found.', 404],
                'STORE_MISMATCH'        => ['That product does not belong to this store.', 403],
                'SOFT_DELETE_ONLY'      => ['This record cannot be deleted. Deactivate or cancel it instead.', 409],
                'APPEND_ONLY'           => ['This record is part of your permanent history and cannot be changed.', 409],
            ];
            if (isset($known[$key])) {
                return new AppException($known[$key][0], (string) $key, $known[$key][1], $e);
            }
        }

        Logger::error('Database error', [
            'sqlstate' => $e->errorInfo[0] ?? null,
            'driver'   => $driverCode,
            'detail'   => $detail,
        ]);

        return match ($driverCode) {
            1062 => new AppException('That record already exists.', 'DUPLICATE', 409, $e),
            1451, 1452 => new AppException('That record is linked to other records and cannot be changed this way.', 'LINKED_RECORD', 409, $e),
            3819, 4025 => new AppException('Some values are not valid. Please check and try again.', 'INVALID_VALUE', 422, $e),
            1213, 1205 => new AppException('The system was busy. Please try again.', 'BUSY', 503, $e),
            default => new AppException('Something went wrong while saving. Please try again.', 'DB_ERROR', 500, $e),
        };
    }
}
