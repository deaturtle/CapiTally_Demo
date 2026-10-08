<?php
declare(strict_types=1);

namespace CapiTally\Services;

use CapiTally\Database;
use CapiTally\Support\AppException;
use CapiTally\Support\Audit;
use CapiTally\Support\Validator;

/** Store information, the user's own account, and application settings. */
final class ProfileService
{
    public static function get(array $user): array
    {
        $store = Database::fetchOne(
            'SELECT id, name, owner_name, address, phone, reserve_cash, allow_negative_override, created_at FROM stores WHERE id = :s',
            ['s' => $user['store_id']]
        );
        $store['allow_negative_override'] = (bool) $store['allow_negative_override'];

        return [
            'store' => $store,
            'user'  => AuthService::publicUser($user) + ['email' => $user['email'], 'last_login_at' => $user['last_login_at']],
        ];
    }

    public static function updateStore(int $storeId, int $userId, array $data): array
    {
        $old = Database::fetchOne('SELECT name, owner_name, address, phone FROM stores WHERE id = :s', ['s' => $storeId]);
        $new = [
            'name'       => Validator::string($data, 'name', 'Store name', true, 120),
            'owner_name' => Validator::string($data, 'owner_name', 'Owner name', true, 120),
            'address'    => Validator::string($data, 'address', 'Address', false, 255),
            'phone'      => Validator::string($data, 'phone', 'Phone', false, 30),
        ];
        Database::transaction(function () use ($storeId, $userId, $old, $new) {
            Database::execute('UPDATE stores SET name = :name, owner_name = :owner_name, address = :address, phone = :phone WHERE id = :s',
                $new + ['s' => $storeId]);
            Audit::log($storeId, $userId, 'store.update', 'store', $storeId, $old, $new);
        });
        return self::get(Database::fetchOne('SELECT * FROM users WHERE id = :id', ['id' => $userId]));
    }

    public static function updateAccount(array $user, array $data): array
    {
        $fullName = Validator::string($data, 'full_name', 'Full name', true, 120);
        $email    = Validator::string($data, 'email', 'Email', false, 160);
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw Validator::fail('Email is not valid.');
        }
        $userId = (int) $user['id'];
        Database::transaction(function () use ($user, $userId, $fullName, $email) {
            Database::execute('UPDATE users SET full_name = :n, email = :e WHERE id = :id', ['n' => $fullName, 'e' => $email, 'id' => $userId]);
            Audit::log((int) $user['store_id'], $userId, 'user.update', 'user', $userId,
                ['full_name' => $user['full_name'], 'email' => $user['email']], ['full_name' => $fullName, 'email' => $email]);
        });
        return self::get(Database::fetchOne('SELECT * FROM users WHERE id = :id', ['id' => $userId]));
    }

    public static function changePassword(array $user, array $data): array
    {
        $current = $data['current_password'] ?? null;
        $new     = $data['new_password'] ?? null;
        if (!is_string($current) || !password_verify($current, $user['password_hash'])) {
            throw new AppException('Your current password is not correct.', 'LOGIN_FAILED', 403);
        }
        AuthService::assertPassword($new);

        $userId = (int) $user['id'];
        Database::transaction(function () use ($user, $userId, $new) {
            Database::execute('UPDATE users SET password_hash = :h WHERE id = :id', ['h' => password_hash($new, PASSWORD_DEFAULT), 'id' => $userId]);
            Audit::log((int) $user['store_id'], $userId, 'user.change_password', 'user', $userId);
        });
        session_regenerate_id(true);
        return ['changed' => true];
    }

    /** Application settings. Currently: the owner switch for the logged negative-stock override. */
    public static function updateSettings(int $storeId, int $userId, array $data): array
    {
        if (!array_key_exists('allow_negative_override', $data) || !is_bool($data['allow_negative_override'])) {
            throw Validator::fail('allow_negative_override must be true or false.');
        }
        $new = $data['allow_negative_override'] ? 1 : 0;
        Database::transaction(function () use ($storeId, $userId, $new) {
            $old = Database::fetchValue('SELECT allow_negative_override FROM stores WHERE id = :s FOR UPDATE', ['s' => $storeId]);
            Database::execute('UPDATE stores SET allow_negative_override = :v WHERE id = :s', ['v' => $new, 's' => $storeId]);
            Audit::log($storeId, $userId, 'settings.update', 'store', $storeId, ['allow_negative_override' => (int) $old], ['allow_negative_override' => $new]);
        });
        return ['allow_negative_override' => (bool) $new];
    }
}
