<?php
declare(strict_types=1);

namespace CapiTally\Services;

use CapiTally\Database;
use CapiTally\Support\AppException;
use CapiTally\Support\Audit;
use CapiTally\Support\Decimal;
use CapiTally\Support\Validator;

/** Expenses are separate from inventory and COGS. They reduce cash and Net Profit only. */
final class ExpenseService
{
    public static function create(int $storeId, int $userId, array $data): array
    {
        $category = Validator::string($data, 'category_name', 'Category', true, 80);
        $date     = Validator::date($data, 'expense_date', 'Expense date');
        $amount   = Validator::decimal($data, 'amount', 'Amount', Decimal::SCALE_MONEY, Validator::MAX_PRICE_UNITS * 100);
        $desc     = Validator::string($data, 'description', 'Description', false, 255);
        $method   = Validator::enum($data, 'payment_method', 'Paid with', ['cash', 'gcash', 'maya'], true, 'cash');

        return Database::transaction(function () use ($storeId, $userId, $category, $date, $amount, $desc, $method) {
            $categoryId = self::categoryId($storeId, $category);
            $id = Database::insert(
                'INSERT INTO expenses (store_id, category_id, expense_date, amount, description, payment_method, user_id)
                 VALUES (:s, :c, :d, :a, :desc, :m, :u)',
                ['s' => $storeId, 'c' => $categoryId, 'd' => $date, 'a' => Decimal::format($amount, 2),
                 'desc' => $desc, 'm' => $method, 'u' => $userId]
            );
            CashLedgerService::post($storeId, $userId, 'expense', $method, -$amount, $id);
            Audit::log($storeId, $userId, 'expense.create', 'expense', $id, null,
                ['category' => $category, 'amount' => Decimal::format($amount, 2), 'date' => $date]);
            return self::find($storeId, $id);
        });
    }

    /** Voids an expense (soft delete) and reverses its cash entry. */
    public static function void(int $storeId, int $userId, int $id): array
    {
        return Database::transaction(function () use ($storeId, $userId, $id) {
            $e = Database::fetchOne('SELECT id, is_voided FROM expenses WHERE id = :id AND store_id = :s FOR UPDATE', ['id' => $id, 's' => $storeId]);
            if ($e === null) {
                throw new AppException('Expense not found.', 'NOT_FOUND', 404);
            }
            if ((int) $e['is_voided'] === 1) {
                throw new AppException('This expense is already voided.', 'INVALID_STATE', 409);
            }
            Database::execute('UPDATE expenses SET is_voided = 1, voided_at = :t, voided_by = :u WHERE id = :id',
                ['t' => date('Y-m-d H:i:s'), 'u' => $userId, 'id' => $id]);
            CashLedgerService::reverseOrigin($storeId, $userId, 'expense', $id);
            Audit::log($storeId, $userId, 'expense.void', 'expense', $id);
            return self::find($storeId, $id);
        });
    }

    public static function find(int $storeId, int $id): array
    {
        $row = Database::fetchOne(
            'SELECT e.id, e.expense_date, c.name AS category_name, e.amount, e.description, e.payment_method,
                    e.is_voided, u.full_name AS user_name, e.created_at
               FROM expenses e JOIN expense_categories c ON c.id = e.category_id JOIN users u ON u.id = e.user_id
              WHERE e.id = :id AND e.store_id = :s',
            ['id' => $id, 's' => $storeId]
        );
        if ($row === null) {
            throw new AppException('Expense not found.', 'NOT_FOUND', 404);
        }
        return $row;
    }

    public static function list(int $storeId, ?string $from, ?string $to, bool $includeVoided, int $limit, int $offset): array
    {
        $where  = ['e.store_id = :s'];
        $params = ['s' => $storeId];
        if (!$includeVoided) {
            $where[] = 'e.is_voided = 0';
        }
        if ($from !== null) {
            $where[] = 'e.expense_date >= :from';
            $params['from'] = $from;
        }
        if ($to !== null) {
            $where[] = 'e.expense_date <= :to';
            $params['to'] = $to;
        }
        $limit  = max(1, min(100, $limit));
        $offset = max(0, $offset);
        return Database::fetchAll(
            'SELECT e.id, e.expense_date, c.name AS category_name, e.amount, e.description, e.payment_method, e.is_voided
               FROM expenses e JOIN expense_categories c ON c.id = e.category_id
              WHERE ' . implode(' AND ', $where) . " ORDER BY e.expense_date DESC, e.id DESC LIMIT {$limit} OFFSET {$offset}",
            $params
        );
    }

    public static function categories(int $storeId): array
    {
        return Database::fetchAll('SELECT id, name FROM expense_categories WHERE store_id = :s AND is_active = 1 ORDER BY name', ['s' => $storeId]);
    }

    private static function categoryId(int $storeId, string $name): int
    {
        $id = Database::fetchValue('SELECT id FROM expense_categories WHERE store_id = :s AND name = :n', ['s' => $storeId, 'n' => $name]);
        if ($id !== null) {
            Database::execute('UPDATE expense_categories SET is_active = 1 WHERE id = :id', ['id' => $id]);
            return (int) $id;
        }
        return Database::insert('INSERT INTO expense_categories (store_id, name) VALUES (:s, :n)', ['s' => $storeId, 'n' => $name]);
    }
}
