<?php

declare(strict_types=1);

namespace App\Actions\Bench;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class CountUploadedBytes
{
    public const MAX_BYTES = 50 * 1024 * 1024;

    private const CHUNK_BYTES = 65536;

    public function handle(Request $request): int
    {
        $declared = $request->header('Content-Length');

        if (is_numeric($declared) && (int) $declared > self::MAX_BYTES) {
            throw new HttpException(413, 'The upload is larger than 50 MB.');
        }

        $body = $request->getContent(true);
        $total = 0;

        while (! feof($body)) {
            $total += strlen((string) fread($body, self::CHUNK_BYTES));

            if ($total > self::MAX_BYTES) {
                throw new HttpException(413, 'The upload is larger than 50 MB.');
            }
        }

        return $total;
    }
}
