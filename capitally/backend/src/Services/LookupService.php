<?php
declare(strict_types=1);

namespace CapiTally\Services;

use CapiTally\Database;

/** Get-or-create for simple lookups typed by name (categories, suppliers). */
final class LookupService
{
    public static function categoryId(int $storeId, ?string $name): ?int
    {
        return self::idByName('categories', $storeId, $name);
    }

    public static function supplierId(int $storeId, ?string $name): ?int
    {
        return self::idByName('suppliers', $storeId, $name);
    }

    private static function idByName(string $table, int $storeId, ?string $name): ?int
    {
        if ($name === null) {
            return null;
        }
        // $table is one of two internal constants, never user input.
        $id = Database::fetchValue("SELECT id FROM {$table} WHERE store_id = :s AND name = :n", ['s' => $storeId, 'n' => $name]);
        if ($id !== null) {
            Database::execute("UPDATE {$table} SET is_active = 1 WHERE id = :id", ['id' => $id]);
            return (int) $id;
        }
        return Database::insert("INSERT INTO {$table} (store_id, name) VALUES (:s, :n)", ['s' => $storeId, 'n' => $name]);
    }
}
