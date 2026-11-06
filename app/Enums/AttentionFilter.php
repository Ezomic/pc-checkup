<?php

declare(strict_types=1);

namespace App\Enums;

enum AttentionFilter: string
{
    case UnsupportedOs = 'os';
    case Drive = 'drive';
    case Age = 'age';
    case Backup = 'backup';
    case Stale = 'stale';

    public function label(): string
    {
        return match ($this) {
            self::UnsupportedOs => 'No security updates',
            self::Drive => 'Drive problems',
            self::Age => '7+ years old',
            self::Backup => 'No recent backup',
            self::Stale => 'Not scanned in 6 months',
        };
    }
}
