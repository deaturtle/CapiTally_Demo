<?php
declare(strict_types=1);

namespace CapiTally\Services;

use CapiTally\Database;
use CapiTally\Support\AppException;
use CapiTally\Support\Audit;
use CapiTally\Support\Validator;

/** Staff accounts, managed by the owner. The owner account itself is edited under Profile. */
final class UserService
{
    private const ROLES = ['manager', 'staff', 'inventory'];
    private const COLUMNS = 'id, username, full_name, email, role, is_active, last_login_at';

    public static function list(int $storeId): array
    {
        return Database::fetchAll(
            'SELECT ' . self::COLUMNS . " FROM users WHERE store_id = :s ORDER BY (role = 'owner') DESC, is_active DESC, full_name",
            ['s' => $storeId]
        );
    }

    public static function create(int $storeId, int $actorId, array $data): array
    {
        $username = Validator::string($data, 'username', 'Username', true, 60);
        $fullName = Validator::string($data, 'full_name', 'Full name', true, 120);
        $email    = Validator::string($data, 'email', 'Email', false, 160);
        $role     = Validator::enum($data, 'role', 'Role', self::ROLES);
        $password = $data['password'] ?? null;

        if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username)) {
            throw Validator::fail('Username must be 3 to 60 letters, numbers, dots, dashes or underscores.');
        }
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw Validator::fail('Email is not valid.');
        }
        AuthService::assertPassword($password);

        return Database::transaction(function () use ($storeId, $actorId, $username, $fullName, $email, $role, $password) {
            if (Database::fetchValue('SELECT 1 FROM users WHERE username = :u', ['u' => $username]) !== null) {
                throw new AppException('That username is already taken.', 'DUPLICATE', 409);
            }
            $id = Database::insert(
                'INSERT INTO users (store_id, username, email, password_hash, full_name, role) VALUES (:s, :u, :e, :h, :f, :r)',
                ['s' => $storeId, 'u' => $username, 'e' => $email, 'h' => password_hash($password, PASSWORD_DEFAULT), 'f' => $fullName, 'r' => $role]
            );
            Audit::log($storeId, $actorId, 'user.create', 'user', $id, null, ['username' => $username, 'role' => $role]);
            return self::find($storeId, $id);
        });
    }

    public static function update(int $storeId, int $actorId, int $id, array $data): array
    {
        $current = self::staffMember($storeId, $id);
        $set = [];
        $params = ['id' => $id];
        if (array_key_exists('full_name', $data)) {
            $set[] = 'full_name = :f';
            $params['f'] = Validator::string($data, 'full_name', 'Full name', true, 120);
        }
        if (array_key_exists('email', $data)) {
            $email = Validator::string($data, 'email', 'Email', false, 160);
            if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw Validator::fail('Email is not valid.');
            }
            $set[] = 'email = :e';
            $params['e'] = $email;
        }
        if (array_key_exists('role', $data)) {
            $set[] = 'role = :r';
            $params['r'] = Validator::enum($data, 'role', 'Role', self::ROLES);
        }
        if ($set === []) {
            throw Validator::fail('Nothing to update.');
        }
        return Database::transaction(function () use ($storeId, $actorId, $id, $set, $params, $current) {
            Database::execute('UPDATE users SET ' . implode(', ', $set) . ' WHERE id = :id', $params);
            $new = self::find($storeId, $id);
            Audit::log($storeId, $actorId, 'user.update', 'user', $id,
                ['full_name' => $current['full_name'], 'email' => $current['email'], 'role' => $current['role']],
                ['full_name' => $new['full_name'], 'email' => $new['email'], 'role' => $new['role']]);
            return $new;
        });
    }

    public static function resetPassword(int $storeId, int $actorId, int $id, array $data): array
    {
        self::staffMember($storeId, $id);
        $password = $data['new_password'] ?? null;
        AuthService::assertPassword($password);
        Database::transaction(function () use ($storeId, $actorId, $id, $password) {
            Database::execute('UPDATE users SET password_hash = :h WHERE id = :id', ['h' => password_hash($password, PASSWORD_DEFAULT), 'id' => $id]);
            Audit::log($storeId, $actorId, 'user.reset_password', 'user', $id);   // never log the password itself
        });
        return ['reset' => true];
    }

    /** Deactivated users cannot log in, and any open session of theirs stops working at its next request. */
    public static function setActive(int $storeId, int $actorId, int $id, bool $active): array
    {
        self::staffMember($storeId, $id);
        return Database::transaction(function () use ($storeId, $actorId, $id, $active) {
            Database::execute('UPDATE users SET is_active = :a WHERE id = :id', ['a' => $active ? 1 : 0, 'id' => $id]);
            Audit::log($storeId, $actorId, $active ? 'user.activate' : 'user.deactivate', 'user', $id);
            return self::find($storeId, $id);
        });
    }

    private static function find(int $storeId, int $id): array
    {
        $row = Database::fetchOne('SELECT ' . self::COLUMNS . ' FROM users WHERE id = :id AND store_id = :s', ['id' => $id, 's' => $storeId]);
        if ($row === null) {
            throw new AppException('User not found.', 'NOT_FOUND', 404);
        }
        return $row;
    }

    /** A non-owner user of this store. The owner cannot be changed or deactivated from here. */
    private static function staffMember(int $storeId, int $id): array
    {
        $row = self::find($storeId, $id);
        if ($row['role'] === 'owner') {
            throw new AppException('The owner account cannot be changed here.', 'FORBIDDEN', 403);
        }
        return $row;
    }
}
