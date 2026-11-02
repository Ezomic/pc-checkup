<?php

declare(strict_types=1);

namespace App\Enums;

enum ReportKind: string
{
    case Audit = 'audit';
    case AfterFix = 'after-fix';
}
