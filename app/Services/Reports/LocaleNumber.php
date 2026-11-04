<?php

declare(strict_types=1);

namespace App\Services\Reports;

/**
 * Reads numbers the way the scan tools print them, which follows the locale of the machine: a Dutch
 * Mac prints 1,41 where an English one prints 1.41. Nothing stored is rewritten, numbers are read
 * at the moment they are used.
 *
 * Rules, in order:
 * - float() is for measurements (load, CPU percent, sizes). One separator is always a decimal mark,
 *   so 1,414 and 1.414 are both 1.414. Scan tools do not group digits in a measurement.
 * - count() is for counts of things (files, items). One separator followed by exactly three digits,
 *   after one to three digits that do not start with 0, is a thousands mark: 1,234 and 1.234 are 1234.
 * - Several separators are only read when they are consistent: the same mark between groups of three
 *   (1,234,567), or a grouping mark followed by one different decimal mark (1.234,56 and 1,234.56).
 *   Anything else (1,2,3 / 1,,5 / 12,34,567) is not a number.
 * - A number needs a digit on both sides of a separator, so ,5 and 5, are refused. A leading minus
 *   is allowed, a plus, an exponent, a unit or spaces inside are not.
 */
final class LocaleNumber
{
    public const PATTERN = '\d+(?:[.,]\d+)?';

    public static function float(?string $value): ?float
    {
        return self::parse($value, false);
    }

    public static function captured(string $matched): float
    {
        return (float) self::float($matched);
    }

    public static function count(?string $value): ?int
    {
        $number = self::parse($value, true);

        return $number !== null && $number >= 0 && floor($number) === $number ? (int) $number : null;
    }

    private static function parse(?string $value, bool $grouped): ?float
    {
        $value = trim((string) $value);

        if (preg_match('/^(-?)(\d+(?:[.,]\d+)*)$/', $value, $match) !== 1) {
            return null;
        }

        $digits = preg_split('/[.,]/', $match[2]) ?: [];
        preg_match_all('/[.,]/', $match[2], $marks);
        $marks = $marks[0];
        $sign = $match[1] === '-' ? -1.0 : 1.0;

        $number = match (true) {
            $marks === [] => $match[2],
            count($marks) === 1 => self::single($digits, $grouped),
            default => self::several($digits, $marks),
        };

        return $number === null ? null : $sign * (float) $number;
    }

    /**
     * @param  list<string>  $digits
     */
    private static function single(array $digits, bool $grouped): string
    {
        [$whole, $fraction] = [$digits[0] ?? '', $digits[1] ?? ''];

        return $grouped && strlen($fraction) === 3 && self::lead($whole) ? $whole.$fraction : "{$whole}.{$fraction}";
    }

    /**
     * @param  list<string>  $digits
     * @param  list<string>  $marks
     */
    private static function several(array $digits, array $marks): ?string
    {
        $decimal = array_pop($marks);
        $groups = array_slice($digits, 0, -1);
        $fraction = $digits[count($digits) - 1];
        $onlyOneMark = count(array_unique([...$marks, $decimal])) === 1;

        if ($onlyOneMark) {
            return self::grouped($digits) ? implode('', $digits) : null;
        }

        if (count(array_unique($marks)) !== 1 || ! self::grouped($groups)) {
            return null;
        }

        return implode('', $groups).".{$fraction}";
    }

    /**
     * @param  list<string>  $groups
     */
    private static function grouped(array $groups): bool
    {
        if (! self::lead($groups[0] ?? '')) {
            return false;
        }

        foreach (array_slice($groups, 1) as $group) {
            if (strlen($group) !== 3) {
                return false;
            }
        }

        return true;
    }

    private static function lead(string $group): bool
    {
        return $group !== '' && strlen($group) <= 3 && $group[0] !== '0';
    }
}
