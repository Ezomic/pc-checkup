<?php

declare(strict_types=1);

namespace App\Services\Reports;

use Carbon\CarbonImmutable;

final class ReportDates
{
    private const FIRST_YEAR = 2000;

    public static function parse(?string $value): ?CarbonImmutable
    {
        $value = trim((string) $value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== null && $date->format('Y-m-d') === $value && $date->year >= self::FIRST_YEAR ? $date : null;
    }

    /**
     * Whole days from $then to $scanned. A date a day ahead of the scan (clock and time zone differences)
     * counts as today; anything further ahead cannot be right and is not a number of days.
     */
    public static function daysBetween(CarbonImmutable $then, CarbonImmutable $scanned): ?int
    {
        $days = (int) $then->diffInDays($scanned, false);

        return match (true) {
            $days >= 0 => $days,
            $days === -1 => 0,
            default => null,
        };
    }

    /**
     * @param  list<string>  $lines
     */
    public static function keyValue(array $lines, string $key): ?string
    {
        foreach ($lines as $line) {
            if (preg_match('/^'.preg_quote($key, '/').':\s*(.*)$/i', trim($line), $match) === 1) {
                return trim($match[1]);
            }
        }

        return null;
    }
}
