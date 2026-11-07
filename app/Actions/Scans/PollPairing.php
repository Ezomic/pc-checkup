<?php

declare(strict_types=1);

namespace App\Actions\Scans;

use App\Enums\PairingStatus;
use App\Models\ScanPairing;
use Illuminate\Support\Str;

final class PollPairing
{
    /**
     * Null for an id that is not one, or a secret that is not its own, without saying which.
     * Before a decision the answer is the status alone. Once approved, every poll mints a fresh
     * upload token and the one before stops working, so a lost answer can simply be asked again.
     *
     * @return array{status: string, upload_token?: string, expires_in?: int}|null
     */
    public function handle(string $id, string $secret): ?array
    {
        if (! ScanPairing::wellFormed($id, ScanPairing::ID_LENGTH) || ! ScanPairing::wellFormed($secret, ScanPairing::SECRET_LENGTH)) {
            return null;
        }

        $pairing = ScanPairing::query()->forPairing($id)->first();

        if ($pairing === null || ! hash_equals($pairing->secret_hash, ScanPairing::hash($secret))) {
            return null;
        }

        $status = $pairing->status();

        if ($status !== PairingStatus::Approved) {
            return ['status' => $status->value];
        }

        $token = Str::random(ScanPairing::TOKEN_LENGTH);
        $pairing->forceFill(['token_hash' => ScanPairing::hash($token)])->save();

        return [
            'status' => $status->value,
            'upload_token' => $token,
            'expires_in' => (int) now()->diffInSeconds($pairing->token_expires_at, true),
        ];
    }
}
