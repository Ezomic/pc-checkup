<?php

declare(strict_types=1);

namespace App\Services\Scans;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Polls for an id that does not exist, or with the wrong secret, are counted per address. Unlike
 * scan codes there is no count for everyone: a pairing id cannot be guessed, so a global lock
 * would only let one person stop every pairing.
 */
final class PairingThrottle
{
    // A waiting script only ever gets one 404, after its pairing expired and was pruned, so this
    // leaves room for a shop full of PCs behind one address.
    public const ADDRESS_FAILURES = 30;

    public const ADDRESS_WINDOW_SECONDS = 600;

    /**
     * Seconds until polling is open again for this address, or 0 when it is open now.
     */
    public function retryAfter(string $ip): int
    {
        $key = $this->key($ip);

        return RateLimiter::tooManyAttempts($key, self::ADDRESS_FAILURES) ? RateLimiter::availableIn($key) : 0;
    }

    public function recordFailure(string $ip): void
    {
        RateLimiter::hit($this->key($ip), self::ADDRESS_WINDOW_SECONDS);
    }

    private function key(string $ip): string
    {
        return 'pairing:failures:'.$ip;
    }
}
