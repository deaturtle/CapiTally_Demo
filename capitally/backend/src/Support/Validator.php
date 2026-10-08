<?php
declare(strict_types=1);

namespace CapiTally\Support;

use DateTimeImmutable;

/** Server-side input validation. Frontend validation is for UX only. */
final class Validator
{
    public const MAX_QTY_UNITS   = 100000 * 1000;      // 100,000 (scale 3)
    public const MAX_PRICE_UNITS = 1000000 * 100;      // 1,000,000 pesos (scale 2)
    public const MAX_COST_UNITS  = 1000000 * 10000;    // 1,000,000 pesos (scale 4)

    public static function string(array $d, string $key, string $label, bool $required = true, int $max = 255): ?string
    {
        $v = $d[$key] ?? null;
        if ($v === null || (is_string($v) && trim($v) === '')) {
            if ($required) {
                throw self::fail("{$label} is required.");
            }
            return null;
        }
        if (!is_string($v)) {
            throw self::fail("{$label} is not valid.");
        }
        $v = trim($v);
        if (mb_strlen($v) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $v)) {
            throw self::fail("{$label} is not valid (max {$max} characters).");
        }
        return $v;
    }

    public static function id(array $d, string $key, string $label, bool $required = true): ?int
    {
        $v = $d[$key] ?? null;
        if ($v === null || $v === '') {
            if ($required) {
                throw self::fail("{$label} is required.");
            }
            return null;
        }
        if ((!is_int($v) && !(is_string($v) && ctype_digit($v))) || (int) $v < 1) {
            throw self::fail("{$label} is not valid.");
        }
        return (int) $v;
    }

    /**
     * @param int $maxUnits upper bound in scaled units
     * @return int|null scaled units
     */
    public static function decimal(
        array $d, string $key, string $label, int $scale, int $maxUnits,
        bool $required = true, bool $allowZero = false
    ): ?int {
        $v = $d[$key] ?? null;
        if ($v === null || $v === '') {
            if ($required) {
                throw self::fail("{$label} is required.");
            }
            return null;
        }
        if (!is_int($v) && !is_float($v) && !is_string($v)) {
            throw self::fail("{$label} is not valid.");
        }
        $units = Decimal::units($v, $scale, $label);
        if ($units < 0 || ($units === 0 && !$allowZero)) {
            throw self::fail($allowZero ? "{$label} cannot be negative." : "{$label} must be greater than zero.");
        }
        if ($units > $maxUnits) {
            throw self::fail("{$label} is too large.");
        }
        return $units;
    }

    public static function enum(array $d, string $key, string $label, array $allowed, bool $required = true, ?string $default = null): ?string
    {
        $v = $d[$key] ?? null;
        if ($v === null || $v === '') {
            if ($default !== null) {
                return $default;
            }
            if ($required) {
                throw self::fail("{$label} is required.");
            }
            return null;
        }
        if (!is_string($v) || !in_array($v, $allowed, true)) {
            throw self::fail("{$label} is not valid.");
        }
        return $v;
    }

    public static function date(array $d, string $key, string $label, bool $required = true): ?string
    {
        $v = self::string($d, $key, $label, $required, 10);
        if ($v === null) {
            return null;
        }
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$dt || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $dt->format('Y-m-d') !== $v) {
            throw self::fail("{$label} must be a valid date (YYYY-MM-DD).");
        }
        return $v;
    }

    public static function bool(array $d, string $key): bool
    {
        return ($d[$key] ?? false) === true;
    }

    /** @return array<int,array> non-empty list of item arrays, bounded in size */
    public static function items(array $d, string $key, string $label, int $max = 200): array
    {
        $v = $d[$key] ?? null;
        if (!is_array($v) || $v === [] || !array_is_list($v) || count($v) > $max) {
            throw self::fail("{$label} must be a list of 1 to {$max} entries.");
        }
        foreach ($v as $item) {
            if (!is_array($item)) {
                throw self::fail("{$label} contains an invalid entry.");
            }
        }
        return $v;
    }

    public static function barcode(array $d, string $key, bool $required = false): ?string
    {
        $v = self::string($d, $key, 'Barcode', $required, 64);
        if ($v !== null && !preg_match('/^[A-Za-z0-9\-]{4,64}$/', $v)) {
            throw self::fail('Barcode must be 4 to 64 letters, numbers or dashes.');
        }
        return $v;
    }

    public static function fail(string $message): AppException
    {
        return new AppException($message, 'VALIDATION', 422);
    }
}
