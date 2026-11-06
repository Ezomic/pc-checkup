<?php

declare(strict_types=1);

namespace App\Services\Quote;

final readonly class EuroAmount
{
    public const VAT_PERCENT = 21;

    public static function toCents(string $input): ?int
    {
        if (preg_match('/\A(\d{1,6})(?:[.,](\d{1,2}))?\z/', trim($input), $match) !== 1) {
            return null;
        }

        return (int) $match[1] * 100 + (int) str_pad($match[2] ?? '', 2, '0');
    }
}
