<?php

declare(strict_types=1);

namespace App\Enums;

enum FlagSeverity: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function rank(): int
    {
        return match ($this) {
            self::High => 0,
            self::Medium => 1,
            self::Low => 2,
        };
    }
}
