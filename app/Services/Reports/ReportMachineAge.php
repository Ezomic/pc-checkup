<?php

declare(strict_types=1);

namespace App\Services\Reports;

use Carbon\CarbonImmutable;

final class ReportMachineAge
{
    private const int SANE_MAX_YEARS = 40;

    private const string BIOS_SECTION = 'BIOS and firmware age';

    /**
     * @return array{years: int, source: 'model'|'firmware'}|null
     */
    public static function of(ReportSections $sections): ?array
    {
        $model = self::modelYears($sections->header('model'));

        if ($model !== null) {
            return ['years' => $model, 'source' => 'model'];
        }

        $firmware = self::firmwareYears($sections->text(self::BIOS_SECTION));

        return $firmware === null ? null : ['years' => $firmware, 'source' => 'firmware'];
    }

    public static function modelYears(?string $model): ?int
    {
        $identifier = $model !== null && preg_match('/\(([A-Za-z]+\d+,\d+)\)$/', $model, $match) === 1 ? $match[1] : null;
        $year = $identifier === null ? null : MacModelYears::year($identifier);

        return $year === null ? null : self::sane(CarbonImmutable::now()->year - $year);
    }

    /**
     * A firmware update moves the release date forward, so this is how old the machine is at least.
     */
    public static function firmwareYears(?string $section): ?int
    {
        $date = preg_match('/^BIOS release date:\s*(\S+)/mi', (string) $section, $match) === 1 ? ReportDates::parse($match[1]) : null;

        return $date === null ? null : self::sane((int) floor($date->diffInYears(CarbonImmutable::now(), false)));
    }

    private static function sane(int $years): ?int
    {
        return $years < 0 || $years > self::SANE_MAX_YEARS ? null : $years;
    }
}
