<?php

declare(strict_types=1);

namespace App\Actions\ApiTokens;

use App\Models\User;
use Illuminate\Support\Facades\Date;

final class CreateApiToken
{
    /**
     * Until ID-89 is fixed, ID only sends access.revoked to an app where the user still
     * holds a live ID token, which someone who minted a token and went away usually does
     * not. So the app cannot count on hearing that its grant was withdrawn, and expiry is
     * what bounds how long a token can outlive it.
     */
    public const int LIFETIME_DAYS = 90;

    /**
     * Returns the plaintext token. It is the only time it exists in readable
     * form: only a hash is stored, so a caller that does not show it to the
     * user here has lost it.
     */
    public function handle(User $user, string $name): string
    {
        return $user->createToken($name, expiresAt: Date::now()->addDays(self::LIFETIME_DAYS))->plainTextToken;
    }
}
