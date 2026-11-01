<?php

declare(strict_types=1);

namespace App\Services\Reports;

final class ReportParser
{
    private const MAX_VALUE_LENGTH = 255;

    private const NOTHING_FLAGGED = 'Nothing flagged automatically.';

    public function parse(string $markdown): ParsedReport
    {
        $lines = explode("\n", $markdown);
        $header = $this->header($lines);

        return new ParsedReport(
            name: $this->name($lines),
            formatVersion: $this->formatVersion($header['format'] ?? null),
            os: $header['os'] ?? null,
            deviceType: $header['device type'] ?? null,
            serial: $header['serial'] ?? null,
            hardwareUuid: $header['hardware uuid'] ?? null,
            model: $header['model'] ?? null,
            flags: $this->flags($lines),
        );
    }

    /**
     * @param  list<string>  $lines
     */
    private function name(array $lines): ?string
    {
        foreach ($lines as $line) {
            if (str_starts_with($line, '## ')) {
                return null;
            }

            if (str_starts_with($line, '# ')) {
                $separator = strpos($line, ': ');

                return $separator === false ? null : $this->clean(substr($line, $separator + 2));
            }
        }

        return null;
    }

    /**
     * Only the "- Key: value" lines above the first section are the header; later lines
     * may quote the same words from the machine itself.
     *
     * @param  list<string>  $lines
     * @return array<string, string>
     */
    private function header(array $lines): array
    {
        $header = [];

        foreach ($lines as $line) {
            if (str_starts_with($line, '## ')) {
                break;
            }

            if (preg_match('/^- ([^:]+):(.*)$/u', $line, $match) !== 1) {
                continue;
            }

            $value = $this->clean($match[2]);

            if ($value !== null) {
                $header[mb_strtolower(trim($match[1]))] = $value;
            }
        }

        return $header;
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function flags(array $lines): array
    {
        $flags = [];
        $inSection = false;

        foreach ($lines as $line) {
            if (str_starts_with($line, '## ')) {
                $inSection = trim(substr($line, 3)) === 'Things to look at';

                continue;
            }

            if (! $inSection || ! str_starts_with($line, '- ')) {
                continue;
            }

            $flag = $this->clean(substr($line, 2));

            if ($flag !== null && $flag !== self::NOTHING_FLAGGED) {
                $flags[] = $flag;
            }
        }

        return $flags;
    }

    private function formatVersion(?string $value): ?int
    {
        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }

    private function clean(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, self::MAX_VALUE_LENGTH);
    }
}
