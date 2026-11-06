<?php

declare(strict_types=1);

namespace App\Enums;

enum AttentionLevel: string
{
    case Urgent = 'urgent';
    case Soon = 'soon';

    public function rank(): int
    {
        return match ($this) {
            self::Urgent => 0,
            self::Soon => 1,
        };
    }
}
