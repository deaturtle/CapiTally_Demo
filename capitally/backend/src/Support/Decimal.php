<?php
declare(strict_types=1);

namespace CapiTally\Support;

/**
 * Exact fixed-point arithmetic on scaled integers (no floats for money).
 *
 * Scales used across CapiTally:
 *   SCALE_MONEY = 2  (pesos, 12.50 -> 1250)
 *   SCALE_QTY   = 3  (quantities, 1.250 kg -> 1250)
 *   SCALE_COST  = 4  (unit cost, 12.3456 -> 123456)
 */
final class Decimal
{
    public const SCALE_MONEY = 2;
    public const SCALE_QTY   = 3;
    public const SCALE_COST  = 4;

    /** Parse "12.5", 12, or a JSON float into scaled units. Rejects anything else. */
    public static function units(string|int|float $value, int $scale, string $field = 'Value'): int
    {
        if (is_float($value)) {
            $s = sprintf('%.' . ($scale + 4) . 'F', $value);
            if (!preg_match('/^(-?\d+)\.(\d+)$/', $s, $m) || substr($m[2], $scale) !== '0000') {
                throw self::invalid($field);
            }
            $value = $m[1] . '.' . substr($m[2], 0, $scale);
        }

        $s = trim((string) $value);
        if (!preg_match('/^(-?)(\d{1,12})(?:\.(\d{1,' . $scale . '}))?$/', $s, $m)) {
            throw self::invalid($field);
        }

        $units = ((int) $m[2]) * (10 ** $scale) + (int) str_pad($m[3] ?? '', $scale, '0');
        return $m[1] === '-' ? -$units : $units;
    }

    /** Format scaled units back to a fixed-point string, e.g. 1250 @2 -> "12.50". */
    public static function format(int $units, int $scale): string
    {
        $neg = $units < 0;
        $abs = abs($units);
        $p   = 10 ** $scale;
        $out = intdiv($abs, $p) . '.' . str_pad((string) ($abs % $p), $scale, '0', STR_PAD_LEFT);
        return ($neg ? '-' : '') . $out;
    }

    /** round-half-away-from-zero of (a * b) / divisor. Throws on integer overflow. */
    public static function mulDiv(int $a, int $b, int $divisor): int
    {
        $p = $a * $b;
        if (!is_int($p)) {
            throw new AppException('That amount is too large.', 'NUMBER_TOO_LARGE', 422);
        }
        $neg = $p < 0;
        $p   = abs($p);
        $q   = intdiv($p, $divisor) + ((($p % $divisor) * 2 >= $divisor) ? 1 : 0);
        return $neg ? -$q : $q;
    }

    /** round-half-up of numerator / denominator for non-negative inputs. */
    public static function div(int $numerator, int $denominator): int
    {
        return intdiv($numerator, $denominator) + ((($numerator % $denominator) * 2 >= $denominator) ? 1 : 0);
    }

    /** qty (scale 3) x unit price (scale 2) -> line amount (scale 2). */
    public static function lineAmount(int $qty3, int $price2): int
    {
        return self::mulDiv($qty3, $price2, 10 ** self::SCALE_QTY);
    }

    /** qty (scale 3) x unit cost (scale 4) -> line cost (scale 2). */
    public static function lineCost(int $qty3, int $cost4): int
    {
        return self::mulDiv($qty3, $cost4, 10 ** (self::SCALE_QTY + self::SCALE_COST - self::SCALE_MONEY));
    }

    /**
     * Weighted-average cost (scale 4) after receiving $inQty3 @ $inCost4.
     * If on-hand is zero or negative the incoming cost becomes the cost.
     */
    public static function weightedAverageCost(int $oldQty3, int $oldCost4, int $inQty3, int $inCost4): int
    {
        if ($oldQty3 <= 0) {
            return $inCost4;
        }
        $numerator = self::mulDiv($oldQty3, $oldCost4, 1) + self::mulDiv($inQty3, $inCost4, 1);
        return self::div($numerator, $oldQty3 + $inQty3);
    }

    private static function invalid(string $field): AppException
    {
        return new AppException("{$field} is not a valid number.", 'VALIDATION', 422);
    }
}
