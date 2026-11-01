<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use LogicException;
use Thijssensoftware\IdClient\Events\AccessRevoked;

/**
 * Deletes every API token of a user whose grant to this app ID has revoked.
 *
 * A plain ID logout is deliberately not enough: ID sends one per session, and signing out
 * on one machine must not kill a script's token on another. Until ID sends this event for
 * every revoked grant (ID-89), the 90-day expiry is what bounds a token that outlives one.
 */
final class RevokeApiTokensOnLostAccess
{
    public function handle(AccessRevoked $event): void
    {
        // Thrown rather than skipped: the failed delivery is reported and ID retries it,
        // where a silent return would leave the tokens working with nothing to show for it.
        if (! $event->user instanceof User) {
            throw new LogicException('id-client.user_model is not '.User::class.'.');
        }

        $event->user->tokens()->delete();
    }
}
