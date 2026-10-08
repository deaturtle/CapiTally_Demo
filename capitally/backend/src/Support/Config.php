<?php
declare(strict_types=1);

namespace CapiTally\Support;

/** Read-only access to config/config.php using dotted keys, e.g. "db.host". */
final class Config
{
    private static ?array $values = null;

    public static function load(string $file): void
    {
        $loaded = require $file;
        self::$values = is_array($loaded) ? $loaded : [];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $node = self::$values ?? [];
        foreach (explode('.', $key) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return $default;
            }
            $node = $node[$segment];
        }
        return $node;
    }

    public static function isDevelopment(): bool
    {
        return self::get('app.env') === 'development';
    }
}
