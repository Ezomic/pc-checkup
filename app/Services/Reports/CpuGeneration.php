<?php

declare(strict_types=1);

namespace App\Services\Reports;

final class CpuGeneration
{
    public const OK = 'ok';

    public const OLD = 'old';

    public const UNKNOWN = 'unknown';

    public const INTEL_MIN_GENERATION = 8;

    public const AMD_MIN_RYZEN_SERIES = 2;

    private const INTEL_MAX_SANE_GENERATION = 30;

    public static function judge(?string $name, ?string $maker = null): string
    {
        $name = trim((string) $name);
        $intel = preg_match('/\bIntel\b/i', $name) === 1;
        $amd = preg_match('/\bAMD\b|\bRyzen\b/i', $name) === 1;

        return match (true) {
            self::contradicts($maker, $intel, $amd) => self::UNKNOWN,
            $intel && ! $amd => self::intel($name),
            $amd && ! $intel => self::amd($name),
            default => self::UNKNOWN,
        };
    }

    private static function contradicts(?string $maker, bool $intel, bool $amd): bool
    {
        $maker = (string) $maker;

        return ($intel && preg_match('/AuthenticAMD|Advanced Micro/i', $maker) === 1)
            || ($amd && preg_match('/GenuineIntel/i', $maker) === 1);
    }

    private static function intel(string $name): string
    {
        return match (true) {
            preg_match('/Core\(TM\)\s*2|Core\s*2\s+(?:Duo|Quad|Extreme)/i', $name) === 1 => self::OLD,
            preg_match('/Core(?:\(TM\))?\s+Ultra\b|Core(?:\(TM\))?\s+[3579]\s+\d{3}|Core(?:\(TM\))?\s+i[3579]-N\d+|Intel\(R\)\s+N\d{3}\b/i', $name) === 1 => self::OK,
            preg_match('/Core(?:\(TM\))?\s*i[3579][-\s](\d{3,5})/i', $name, $model) === 1 => self::fromIntelModel($model[1]),
            preg_match('/Core(?:\(TM\))?\s*i[3579]\s+(?:CPU\s+)?[A-Z]?\s*\d{3}\b/i', $name) === 1 => self::OLD,
            default => self::UNKNOWN,
        };
    }

    private static function fromIntelModel(string $digits): string
    {
        $generation = match (strlen($digits)) {
            3 => 1,
            4 => $digits[0] === '1' ? (int) substr($digits, 0, 2) : (int) $digits[0],
            default => (int) substr($digits, 0, 2),
        };

        return match (true) {
            $generation > self::INTEL_MAX_SANE_GENERATION => self::UNKNOWN,
            $generation >= self::INTEL_MIN_GENERATION => self::OK,
            default => self::OLD,
        };
    }

    private static function amd(string $name): string
    {
        return match (true) {
            preg_match('/Ryzen\s+AI\b/i', $name) === 1 => self::OK,
            preg_match('/Ryzen\s+(?:[3579]\s+)?(?:PRO\s+)?(?:Threadripper\s+)?(?:PRO\s+)?(\d)\d{3}/i', $name, $model) === 1 => (int) $model[1] >= self::AMD_MIN_RYZEN_SERIES ? self::OK : self::OLD,
            preg_match('/\bFX(?:\(tm\))?-\d|Phenom|Sempron|Athlon(?:\(tm\))?\s+(?:64|II|X[2346])|\bA(?:4|6|8|10|12)-\d{4}/i', $name) === 1 => self::OLD,
            default => self::UNKNOWN,
        };
    }
}
