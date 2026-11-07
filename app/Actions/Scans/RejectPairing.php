<?php

declare(strict_types=1);

namespace App\Actions\Scans;

use App\Models\ScanPairing;
use App\Models\User;

final class RejectPairing
{
    /**
     * False when the pairing is no longer waiting for a decision.
     */
    public function handle(ScanPairing $pairing, User $user): bool
    {
        return ScanPairing::query()->whereKey($pairing->id)->pending()->update([
            'user_id' => $user->id,
            'rejected_at' => now(),
        ]) === 1;
    }
}
