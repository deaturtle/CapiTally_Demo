<?php
declare(strict_types=1);

/**
 * Backend bootstrap. Every endpoint includes this first.
 * Registers the PSR-4 style autoloader for the CapiTally namespace (no
 * Composer needed), loads config, and applies safe PHP settings.
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'CapiTally\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = __DIR__ . '/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use CapiTally\Support\Config;

Config::load(dirname(__DIR__) . '/config/config.php');

date_default_timezone_set((string) Config::get('app.timezone', 'Asia/Manila'));

// Never leak technical errors to users; they are logged instead.
ini_set('display_errors', Config::isDevelopment() ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
