<?php

declare(strict_types=1);

namespace App\Enums;

enum ChecklistOutcome: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case NotApplicable = 'na';
}
