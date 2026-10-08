<?php
declare(strict_types=1);

namespace CapiTally\Services;

use CapiTally\Database;
use CapiTally\Support\AppException;
use CapiTally\Support\Validator;

/** Notes and tasks (reminders). Nothing is hard-deleted: "delete" archives. */
final class NotesService
{
    private const SELECT = 'SELECT id, title, details, due_date, status, completed_at, created_at, updated_at FROM notes_tasks';

    public static function list(int $storeId, string $status, int $limit, int $offset): array
    {
        $where  = ['store_id = :s'];
        $params = ['s' => $storeId];
        if (in_array($status, ['open', 'done', 'archived'], true)) {
            $where[] = 'status = :st';
            $params['st'] = $status;
        } else {
            $where[] = "status <> 'archived'";   // default view hides archived items
        }
        $limit  = max(1, min(100, $limit));
        $offset = max(0, $offset);
        return Database::fetchAll(
            self::SELECT . ' WHERE ' . implode(' AND ', $where)
            . " ORDER BY (status = 'done'), due_date IS NULL, due_date, id DESC LIMIT {$limit} OFFSET {$offset}",
            $params
        );
    }

    public static function create(int $storeId, int $userId, array $data): array
    {
        $title   = Validator::string($data, 'title', 'Title', true, 160);
        $details = Validator::string($data, 'details', 'Details', false, 1000);
        $due     = Validator::date($data, 'due_date', 'Due date', false);

        $id = Database::insert(
            'INSERT INTO notes_tasks (store_id, title, details, due_date, user_id) VALUES (:s, :t, :d, :due, :u)',
            ['s' => $storeId, 't' => $title, 'd' => $details, 'due' => $due, 'u' => $userId]
        );
        return self::find($storeId, $id);
    }

    public static function update(int $storeId, int $id, array $data): array
    {
        self::find($storeId, $id);
        $set = [];
        $params = ['id' => $id, 's' => $storeId];
        if (array_key_exists('title', $data)) {
            $set[] = 'title = :title';
            $params['title'] = Validator::string($data, 'title', 'Title', true, 160);
        }
        if (array_key_exists('details', $data)) {
            $set[] = 'details = :details';
            $params['details'] = Validator::string($data, 'details', 'Details', false, 1000);
        }
        if (array_key_exists('due_date', $data)) {
            $set[] = 'due_date = :due';
            $params['due'] = Validator::date($data, 'due_date', 'Due date', false);   // null clears the date
        }
        if ($set === []) {
            throw Validator::fail('Nothing to update.');
        }
        Database::execute('UPDATE notes_tasks SET ' . implode(', ', $set) . ' WHERE id = :id AND store_id = :s', $params);
        return self::find($storeId, $id);
    }

    /** @param string $status open|done|archived */
    public static function setStatus(int $storeId, int $id, string $status): array
    {
        self::find($storeId, $id);
        Database::execute(
            'UPDATE notes_tasks SET status = :st, completed_at = :c WHERE id = :id AND store_id = :s',
            ['st' => $status, 'c' => $status === 'done' ? date('Y-m-d H:i:s') : null, 'id' => $id, 's' => $storeId]
        );
        return self::find($storeId, $id);
    }

    public static function find(int $storeId, int $id): array
    {
        $row = Database::fetchOne(self::SELECT . ' WHERE id = :id AND store_id = :s', ['id' => $id, 's' => $storeId]);
        if ($row === null) {
            throw new AppException('Note not found.', 'NOT_FOUND', 404);
        }
        return $row;
    }
}
