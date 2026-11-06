<?php

declare(strict_types=1);

namespace App\Services\Reports;

final class ReportRedactor
{
    public const REMOVED = '[removed]';

    private const SECRET_WORDS = ['token', 'mobileme', 'password', 'secret'];

    // A key at the start of a line, as nvram, defaults and ini style output print it. The key is
    // capped so a huge single line cannot make the pattern backtrack for long.
    private const KEY_PATTERN = '/\A[ \t]*(?:[-*][ \t]+)?["\']?([\w:.\-]{1,200})["\']?[ \t]*[\t=:]/';

    // Eight escaped bytes in a row is a binary blob, where a short flag word such as %7f%00 is not.
    private const BLOB_PATTERN = '/(?:%[0-9a-f]{2}){8}/i';

    public function redact(string $markdown): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown));

        $kept = implode("\n", array_map(fn (string $line): string => $this->isSecret($line) ? self::REMOVED : $line, $lines));

        return (new ReportUserMasker)->mask($kept);
    }

    private function isSecret(string $line): bool
    {
        if (stripos($line, 'bplist00') !== false || preg_match(self::BLOB_PATTERN, $line) === 1) {
            return true;
        }

        if (preg_match(self::KEY_PATTERN, $line, $match) !== 1) {
            return false;
        }

        foreach (self::SECRET_WORDS as $word) {
            if (stripos($match[1], $word) !== false) {
                return true;
            }
        }

        return false;
    }
}
