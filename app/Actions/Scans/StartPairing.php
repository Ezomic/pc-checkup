<?php

declare(strict_types=1);

namespace App\Actions\Scans;

use App\Models\ScanPairing;
use App\Services\Scans\PairingQr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StartPairing
{
    public const LIFETIME_MINUTES = 10;

    // No 0/O, 1/I/L, 2/Z, 5/S or 8/B, so the code reads the same off any screen or photo.
    private const CHECK_ALPHABET = 'ACDEFHJKMNPRTUVWXY34679';

    private const CHECK_LENGTH = 4;

    public function __construct(private readonly PairingQr $qr) {}

    /**
     * The id goes in the link the owner opens; the secret stays with the script and is what it
     * polls with. Neither is stored, only their hashes, and nothing about the computer is.
     *
     * @return array{id: string, secret: string, check_code: string, url: string, qr: list<string>, expires_in: int}
     */
    public function handle(bool $ascii): array
    {
        $id = Str::random(ScanPairing::ID_LENGTH);
        $secret = Str::random(ScanPairing::SECRET_LENGTH);
        $check = $this->checkCode();

        DB::transaction(function () use ($id, $secret, $check): void {
            ScanPairing::query()->dead()->delete();
            ScanPairing::query()->create([
                'pairing_hash' => ScanPairing::hash($id),
                'secret_hash' => ScanPairing::hash($secret),
                'check_code' => $check,
                'expires_at' => now()->addMinutes(self::LIFETIME_MINUTES),
            ]);
        });

        // Built from APP_URL, not the request, so a forged Host header cannot aim the link elsewhere.
        $url = rtrim(config()->string('app.url'), '/').route('pairings.show', ['pairing' => $id], absolute: false);

        return [
            'id' => $id,
            'secret' => $secret,
            'check_code' => $check,
            'url' => $url,
            'qr' => $this->qr->lines($url, $ascii),
            'expires_in' => self::LIFETIME_MINUTES * 60,
        ];
    }

    private function checkCode(): string
    {
        $code = '';

        for ($i = 0; $i < self::CHECK_LENGTH; $i++) {
            $code .= self::CHECK_ALPHABET[random_int(0, strlen(self::CHECK_ALPHABET) - 1)];
        }

        return $code;
    }
}
