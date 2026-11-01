<?php

declare(strict_types=1);

namespace App\Enums;

enum DeviceType: string
{
    case Laptop = 'laptop';
    case Desktop = 'desktop';
}
