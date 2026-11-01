<?php

declare(strict_types=1);

namespace App\Enums;

enum MachineStatus: string
{
    case Ok = 'ok';
    case NeedsWork = 'needs_work';
    case Reject = 'reject';
}
