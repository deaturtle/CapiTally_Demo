<?php
declare(strict_types=1);

namespace CapiTally\Support;

/**
 * Minimal file logger. Technical detail (SQL errors, stack traces) goes
 * here, never into a response shown to the user.
 */
final class Logger
{
    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        $path = (string) Config::get('app.log_path', '');
        $line = sprintf(
            "[%s] %s %s %s\n",
            date('Y-m-d H:i:s'),
            $level,
            $message,
            $context === [] ? '' : json_encode($context, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)
        );
        if ($path === '' || @file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === false) {
            error_log(trim($line)); // last resort: PHP's own log
        }
    }
}
