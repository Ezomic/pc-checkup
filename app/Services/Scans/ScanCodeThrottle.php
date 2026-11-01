<?php

declare(strict_types=1);

namespace App\Services\Scans;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Six digits are guessable, so wrong guesses are counted per address and for everyone. Only
 * failures count: a good redemption never moves either counter.
 */
final class ScanCodeThrottle
{
    public const ADDRESS_FAILURES = 5;

    public const ADDRESS_WINDOW_SECONDS = 600;

    public const GLOBAL_FAILURES = 30;

    public const GLOBAL_WINDOW_SECONDS = 1800;

    private const GLOBAL_KEY = 'scan-code:failures';

    /**
     * Seconds until redemption is open again, or 0 when it is open now.
     */
    public function retryAfter(string $ip): int
    {
        return max(
            $this->remaining($this->addressKey($ip), self::ADDRESS_FAILURES),
            $this->remaining(self::GLOBAL_KEY, self::GLOBAL_FAILURES),
        );
    }

    public function recordFailure(string $ip): void
    {
        RateLimiter::hit($this->addressKey($ip), self::ADDRESS_WINDOW_SECONDS);
        RateLimiter::hit(self::GLOBAL_KEY, self::GLOBAL_WINDOW_SECONDS);
    }

    private function remaining(string $key, int $max): int
    {
        return RateLimiter::tooManyAttempts($key, $max) ? RateLimiter::availableIn($key) : 0;
    }

    private function addressKey(string $ip): string
    {
        return 'scan-code:failures:'.$ip;
    }
}
