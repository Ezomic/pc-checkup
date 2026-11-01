<?php

declare(strict_types=1);

namespace App\Actions\ApiTokens;

use App\Models\User;

final class RevokeApiToken
{
    /**
     * Scoped to the user's own tokens rather than looked up by id alone, so a
     * guessed or borrowed id cannot revoke somebody else's token.
     */
    public function handle(User $user, int $tokenId): bool
    {
        return $user->tokens()->whereKey($tokenId)->delete() > 0;
    }
}
