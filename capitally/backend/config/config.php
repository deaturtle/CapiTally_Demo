<?php
declare(strict_types=1);

/**
 * CapiTally configuration.
 *
 * Values come from config/config.local.php (git-ignored, machine specific)
 * and fall back to the safe defaults below. Never commit real credentials.
 */

$defaults = [
    'app' => [
        'env'      => 'production',          // 'development' only on a developer machine
        'timezone' => 'Asia/Manila',
        'log_path' => dirname(__DIR__) . '/storage/logs/app.log',
    ],
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'capitally',
        'user'     => 'root',                // XAMPP default; use a dedicated user in production
        'password' => '',
        'charset'  => 'utf8mb4',
    ],
    'session' => [
        'name'             => 'capitally_session',
        'idle_timeout_sec' => 1800,          // 30 minutes
    ],
    'backup' => [
        'mysqldump_path' => 'mysqldump',     // XAMPP on Windows: C:\\xampp\\mysql\\bin\\mysqldump.exe
        'directory'      => dirname(__DIR__) . '/storage/backups',
        'keep'           => 14,              // number of most recent backups to retain
    ],
    'barcode_api' => [
        'enabled'  => false,                 // external lookup is optional, never the only path
        'base_url' => 'https://api.barcodelookup.com/v3/products',
        'api_key'  => '',
        'timeout'  => 5,
    ],
];

$local = [];
$localFile = __DIR__ . '/config.local.php';
if (is_file($localFile)) {
    $loaded = require $localFile;
    if (is_array($loaded)) {
        $local = $loaded;
    }
}

return array_replace_recursive($defaults, $local);
