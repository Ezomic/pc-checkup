<?php

declare(strict_types=1);

namespace App\Enums;

enum MachineStatus: string
{
    case Checking = 'checking';
    case Ready = 'ready';
    case NeedsWork = 'needs_work';
}
