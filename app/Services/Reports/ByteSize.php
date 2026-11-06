<?php

declare(strict_types=1);

namespace App\Services\Reports;

final class ByteSize
{
    private const MAX_BYTES = 1024 ** 5;

    private const UNITS = ['' => 1024, 'B' => 1, 'KB' => 1024, 'MB' => 1024 ** 2, 'GB' => 1024 ** 3, 'TB' => 1024 ** 4];

    private const LABELS = ['TB' => 1024 ** 4, 'GB' => 1024 ** 3, 'MB' => 1024 ** 2, 'KB' => 1024, 'B' => 1];

    public static function parse(string $value): ?float
    {
        if (preg_match('/^('.LocaleNumber::PATTERN.')\s*(B|KB|MB|GB|TB)?$/i', trim($value), $match) !== 1) {
            return null;
        }

        $bytes = LocaleNumber::captured($match[1]) * self::UNITS[strtoupper($match[2] ?? '')];

        return $bytes <= self::MAX_BYTES ? $bytes : null;
    }

    public static function format(float $bytes): string
    {
        foreach (self::LABELS as $label => $size) {
            if ($bytes >= $size) {
                $value = $bytes / $size;

                return ($value >= 10 ? (string) round($value) : (string) round($value, 1))." {$label}";
            }
        }

        return '0 B';
    }
}
