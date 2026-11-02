<?php

declare(strict_types=1);

namespace App\Actions\Bench;

use Symfony\Component\HttpFoundation\StreamedResponse;

final class StreamRandomBytes
{
    private const CHUNK_BYTES = 65536;

    public function handle(int $bytes): StreamedResponse
    {
        return response()->stream(function () use ($bytes): void {
            for ($remaining = $bytes; $remaining > 0; $remaining -= self::CHUNK_BYTES) {
                echo random_bytes(min($remaining, self::CHUNK_BYTES));
                flush();
            }
        }, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) $bytes,
            'Content-Encoding' => 'identity',
            'Cache-Control' => 'no-store',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
