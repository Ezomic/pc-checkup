<?php

declare(strict_types=1);

namespace App\Services\Reports;

final class ReportAppName
{
    private const LIMIT = 60;

    public static function clean(string $name): ?string
    {
        $spaced = (string) preg_replace('/[\t\n\r\f\x0B\p{Zs}\p{Zl}\p{Zp}]+/u', ' ', mb_scrub($name));
        $stripped = (string) preg_replace('~[\p{Cc}/\\\\|]|(?![\x{200C}\x{200D}])\p{Cf}~u', '', $spaced);
        $clean = trim(mb_substr(trim((string) preg_replace('/ {2,}/', ' ', $stripped)), 0, self::LIMIT));

        return in_array($clean, ['', '.', '..'], true) ? null : $clean;
    }
}
