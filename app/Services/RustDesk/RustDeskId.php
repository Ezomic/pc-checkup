<?php

declare(strict_types=1);

namespace App\Services\RustDesk;

final class RustDeskId
{
    // Self-hosted servers may hand out custom ids, so this is letters, digits, dash and underscore.
    public const PATTERN = '/\A[A-Za-z0-9_-]{4,64}\z/';

    public static function isValid(mixed $id): bool
    {
        return is_string($id) && preg_match(self::PATTERN, $id) === 1;
    }

    public static function orNull(?string $id): ?string
    {
        return self::isValid($id) ? $id : null;
    }
}
