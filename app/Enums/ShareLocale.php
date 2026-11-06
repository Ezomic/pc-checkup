<?php

declare(strict_types=1);

namespace App\Enums;

enum ShareLocale: string
{
    case Dutch = 'nl';
    case English = 'en';

    public static function default(): self
    {
        return self::Dutch;
    }
}
