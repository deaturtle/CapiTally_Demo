<?php
declare(strict_types=1);

namespace CapiTally\Services;

use CapiTally\Database;
use CapiTally\Support\AppException;
use CapiTally\Support\Audit;
use CapiTally\Support\Config;
use CapiTally\Support\Validator;

final class AuthService
{
    private const MAX_FAILS_PER_USER = 5;
    private const MAX_FAILS_PER_IP   = 20;
    private const LOCK_MINUTES       = 15;

    public static function needsSetup(): bool
    {
        return (int) Database::fetchValue('SELECT COUNT(*) FROM users') === 0;
    }

    /** First-run owner and store creation. Allowed only while no user exists. */
    public static function setupOwner(array $data): array
    {
        if (!self::needsSetup()) {
            throw new AppException('Setup has already been completed.', 'SETUP_DONE', 409);
        }

        $storeName = Validator::string($data, 'store_name', 'Store name', true, 120);
        $ownerName = Validator::string($data, 'owner_name', 'Owner name', true, 120);
        $address   = Validator::string($data, 'address', 'Address', false, 255);
        $phone     = Validator::string($data, 'phone', 'Phone', false, 30);
        $username  = Validator::string($data, 'username', 'Username', true, 60);
        $email     = Validator::string($data, 'email', 'Email', false, 160);
        $password  = $data['password'] ?? null;

        if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username)) {
            throw Validator::fail('Username must be 3 to 60 letters, numbers, dots, dashes or underscores.');
        }
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw Validator::fail('Email is not valid.');
        }
        self::assertPassword($password);

        // Serialize setup so two simultaneous requests cannot both create an owner.
        if ((int) Database::fetchValue("SELECT GET_LOCK('capitally_setup', 5)") !== 1) {
            throw new AppException('The system was busy. Please try again.', 'BUSY', 503);
        }
        try {
            if (!self::needsSetup()) {
                throw new AppException('Setup has already been completed.', 'SETUP_DONE', 409);
            }
            return self::createOwner($storeName, $ownerName, $address, $phone, $username, $email, $password);
        } finally {
            Database::fetchValue("SELECT RELEASE_LOCK('capitally_setup')");
        }
    }

    private static function createOwner(string $storeName, string $ownerName, ?string $address, ?string $phone, string $username, ?string $email, string $password): array
    {
        return Database::transaction(function () use ($storeName, $ownerName, $address, $phone, $username, $email, $password) {
            $storeId = Database::insert(
                'INSERT INTO stores (name, owner_name, address, phone) VALUES (:n, :o, :a, :p)',
                ['n' => $storeName, 'o' => $ownerName, 'a' => $address, 'p' => $phone]
            );
            $userId = Database::insert(
                'INSERT INTO users (store_id, username, email, password_hash, full_name, role)
                 VALUES (:s, :u, :e, :h, :f, \'owner\')',
                ['s' => $storeId, 'u' => $username, 'e' => $email,
                 'h' => password_hash($password, PASSWORD_DEFAULT), 'f' => $ownerName]
            );
            Audit::log($storeId, $userId, 'store.setup', 'store', $storeId, null, ['store_name' => $storeName]);
            return ['store_id' => $storeId, 'user_id' => $userId];
        });
    }

    public static function login(string $username, string $password, string $ip): array
    {
        $username = trim($username);
        self::assertNotThrottled($username, $ip);

        $user = Database::fetchOne('SELECT * FROM users WHERE username = :u', ['u' => $username]);
        if ($user === null) {
            password_hash($password, PASSWORD_DEFAULT); // spend similar time so timing does not reveal valid usernames
            $valid = false;
        } else {
            $valid = password_verify($password, $user['password_hash']) && (int) $user['is_active'] === 1;
        }

        if (!$valid) {
            Database::insert(
                'INSERT INTO login_attempts (username, ip_address, was_successful) VALUES (:u, :ip, 0)',
                ['u' => mb_substr($username, 0, 60), 'ip' => $ip]
            );
            throw new AppException('Incorrect username or password.', 'LOGIN_FAILED', 401);
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            Database::execute('UPDATE users SET password_hash = :h WHERE id = :id',
                ['h' => password_hash($password, PASSWORD_DEFAULT), 'id' => $user['id']]);
        }

        Database::execute('DELETE FROM login_attempts WHERE username = :u AND was_successful = 0', ['u' => $username]);
        Database::execute('UPDATE users SET last_login_at = :t WHERE id = :id',
            ['t' => date('Y-m-d H:i:s'), 'id' => $user['id']]);

        session_regenerate_id(true);
        $_SESSION['user_id']       = (int) $user['id'];
        $_SESSION['csrf']          = bin2hex(random_bytes(32));
        $_SESSION['last_activity'] = time();

        Audit::log((int) $user['store_id'], (int) $user['id'], 'auth.login', 'user', (int) $user['id']);

        return ['user' => self::publicUser($user), 'csrf_token' => $_SESSION['csrf']];
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    /** Returns the logged-in user row or throws 401. Enforces the idle timeout. */
    public static function requireUser(): array
    {
        $uid = $_SESSION['user_id'] ?? null;
        if (!$uid) {
            throw new AppException('Please log in to continue.', 'UNAUTHENTICATED', 401);
        }
        $timeout = (int) Config::get('session.idle_timeout_sec', 1800);
        if (time() - (int) ($_SESSION['last_activity'] ?? 0) > $timeout) {
            self::logout();
            throw new AppException('Your session expired. Please log in again.', 'SESSION_EXPIRED', 401);
        }

        $user = Database::fetchOne('SELECT * FROM users WHERE id = :id AND is_active = 1', ['id' => $uid]);
        if ($user === null) {
            self::logout();
            throw new AppException('Please log in to continue.', 'UNAUTHENTICATED', 401);
        }
        $_SESSION['last_activity'] = time();
        return $user;
    }

    public static function requireCsrf(?string $token): void
    {
        $expected = $_SESSION['csrf'] ?? '';
        if ($token === null || $expected === '' || !hash_equals($expected, $token)) {
            throw new AppException('Your request could not be verified. Please refresh and try again.', 'CSRF_INVALID', 403);
        }
    }

    public static function csrfToken(): string
    {
        return (string) ($_SESSION['csrf'] ?? '');
    }

    public static function publicUser(array $user): array
    {
        return [
            'id'        => (int) $user['id'],
            'store_id'  => (int) $user['store_id'],
            'username'  => $user['username'],
            'full_name' => $user['full_name'],
            'role'      => $user['role'],
        ];
    }

    public static function assertPassword(mixed $password): void
    {
        // bcrypt silently ignores bytes beyond 72, so longer passwords are refused rather than truncated.
        if (!is_string($password) || strlen($password) < 8 || strlen($password) > 72) {
            throw Validator::fail('Password must be 8 to 72 characters.');
        }
    }

    private static function assertNotThrottled(string $username, string $ip): void
    {
        $window = 'attempted_at > (NOW() - INTERVAL ' . self::LOCK_MINUTES . ' MINUTE) AND was_successful = 0';
        $byUser = (int) Database::fetchValue("SELECT COUNT(*) FROM login_attempts WHERE username = :u AND {$window}", ['u' => mb_substr($username, 0, 60)]);
        $byIp   = (int) Database::fetchValue("SELECT COUNT(*) FROM login_attempts WHERE ip_address = :ip AND {$window}", ['ip' => $ip]);
        if ($byUser >= self::MAX_FAILS_PER_USER || $byIp >= self::MAX_FAILS_PER_IP) {
            throw new AppException('Too many failed attempts. Please wait a few minutes and try again.', 'TOO_MANY_ATTEMPTS', 429);
        }
    }
}
