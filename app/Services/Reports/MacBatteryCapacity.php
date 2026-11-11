<?php

declare(strict_types=1);

namespace App\Services\Reports;

/**
 * Maximum capacity from the ioreg battery keys, for Macs whose system_profiler prints none, as on
 * macOS 10.14. AppleRawMaxCapacity and DesignCapacity are mAh, but MaxCapacity is a percentage on
 * Apple silicon, so it only counts when it is clearly mAh.
 */
final readonly class MacBatteryCapacity
{
    private const PERCENT_SCALE_MAX = 100;

    private const MIN_PERCENT = 1;

    private const MAX_PERCENT = 110;

    public function percent(?string $ioreg): ?int
    {
        $design = $this->value($ioreg, 'DesignCapacity');
        $maximum = $this->value($ioreg, 'MaxCapacity');

        return $this->ratio($this->value($ioreg, 'AppleRawMaxCapacity'), $design)
            ?? $this->ratio($maximum !== null && $maximum > self::PERCENT_SCALE_MAX ? $maximum : null, $design);
    }

    private function ratio(?int $charge, ?int $design): ?int
    {
        if ($charge === null || $design === null || $charge <= 0 || $design <= 0) {
            return null;
        }

        $percent = (int) round($charge * 100 / $design);

        return $percent >= self::MIN_PERCENT && $percent <= self::MAX_PERCENT ? $percent : null;
    }

    private function value(?string $ioreg, string $key): ?int
    {
        return $ioreg !== null && preg_match('/^[ \t|]*"'.$key.'"[ \t]*=[ \t]*(\d{1,9})[ \t\r]*$/m', $ioreg, $match) === 1 ? (int) $match[1] : null;
    }
}
