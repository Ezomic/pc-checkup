<?php

declare(strict_types=1);

namespace App\Enums;

enum LoginLinkOutcome: string
{
    case SignedIn = 'signed-in';
    case TwoFactorChallenge = 'two-factor-challenge';
    case Invalid = 'invalid';
}
