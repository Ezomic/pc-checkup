<?php

declare(strict_types=1);

namespace App\Actions\Scans;

use App\Models\Client;
use App\Models\Machine;
use App\Models\ScanPairing;
use App\Models\User;

final class ApprovePairing
{
    public const TOKEN_LIFETIME_MINUTES = 30;

    /**
     * Binds the pairing to the owner, the client and either one of that client's machines or,
     * with no machine, whatever machine the report turns out to be. False when the choice is not
     * the owner's to make, or the pairing is no longer waiting.
     */
    public function handle(ScanPairing $pairing, User $user, Client $client, ?Machine $machine): bool
    {
        if ($client->user_id !== $user->id || ($machine !== null && ($machine->user_id !== $user->id || $machine->client_id !== $client->id))) {
            return false;
        }

        // The conditional update is what decides a race: only one approval or rejection finds it waiting.
        return ScanPairing::query()->whereKey($pairing->id)->pending()->update([
            'user_id' => $user->id,
            'client_id' => $client->id,
            'machine_id' => $machine?->id,
            'approved_at' => now(),
            'token_expires_at' => now()->addMinutes(self::TOKEN_LIFETIME_MINUTES),
        ]) === 1;
    }
}
