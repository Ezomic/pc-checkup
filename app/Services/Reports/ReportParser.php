<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\ReportKind;
use App\Services\RustDesk\RustDeskId;

final class ReportParser
{
    public const string FLAGS_TITLE = 'Things to look at';

    private const MAX_VALUE_LENGTH = 255;

    private const MAX_FLAGS = 100;

    private const MAX_FIXES = 100;

    private const MAX_COMMAND_LENGTH = 2000;

    private const BENCHMARK_KEYS = [
        'cpu_single_ms',
        'cpu_multi_ms',
        'memory_mb_s',
        'disk_write_mb_s',
        'disk_read_mb_s',
        'net_down_mbps',
        'net_up_mbps',
    ];

    private const UPDATE_CHECKS = ['current', 'outdated, continued', 'not checked'];

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
            kind: ReportKind::tryFrom(mb_strtolower($header['kind'] ?? '')) ?? ReportKind::Audit,
            beforeChecksum: $this->checksum($header['before checksum'] ?? null),
            fixes: $this->fixes($lines),
            benchmark: $this->benchmark($lines),
            rustdeskId: RustDeskId::orNull($header['rustdesk id'] ?? null),
            ranAsAdmin: $this->rights($header['run as administrator'] ?? null),
            scriptVersion: $this->scriptVersion($header['script version'] ?? null),
            updateCheck: $this->updateCheck($header['update check'] ?? null),
        );
    }

    private function scriptVersion(?string $value): ?string
    {
        return $value !== null && preg_match('/\A[A-Za-z0-9.-]{1,32}\z/', $value) === 1 ? $value : null;
    }

    private function updateCheck(?string $value): ?string
    {
        $value = $value === null ? null : mb_strtolower($value);

        return in_array($value, self::UPDATE_CHECKS, true) ? $value : null;
    }

    private function rights(?string $value): ?bool
    {
        if ($value === null || preg_match('/\A(yes|no)(?:,|\z)/i', $value, $match) !== 1) {
            return null;
        }

        return mb_strtolower($match[1]) === 'yes';
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
                $inSection = trim(substr($line, 3)) === self::FLAGS_TITLE;

                continue;
            }

            if (! $inSection || ! str_starts_with($line, '- ')) {
                continue;
            }

            $flag = $this->clean(substr($line, 2));

            if ($flag !== null && $flag !== self::NOTHING_FLAGGED) {
                $flags[] = $flag;
            }

            if (count($flags) === self::MAX_FLAGS) {
                break;
            }
        }

        return $flags;
    }

    /**
     * @param  list<string>  $lines
     * @return list<array{id: string, status: string, command: string, undo: string|null}>|null
     */
    private function fixes(array $lines): ?array
    {
        $section = $this->section($lines, 'Fix log');

        if ($section === null) {
            return null;
        }

        $fixes = [];

        foreach ($section as $line) {
            if (preg_match('/^- (\S+) \| (applied|skipped|failed) \| (.+) \| undo: (.*)$/u', $line, $match) !== 1) {
                continue;
            }

            $undo = $this->clean($match[4]);

            $fixes[] = [
                'id' => mb_substr($match[1], 0, self::MAX_VALUE_LENGTH),
                'status' => $match[2],
                'command' => mb_substr(trim($match[3]), 0, self::MAX_COMMAND_LENGTH),
                'undo' => $undo === null || mb_strtolower($undo) === 'none' ? null : $undo,
            ];

            if (count($fixes) === self::MAX_FIXES) {
                break;
            }
        }

        return $fixes;
    }

    /**
     * @param  list<string>  $lines
     * @return array<string, int|float>|null
     */
    private function benchmark(array $lines): ?array
    {
        $section = $this->section($lines, 'Benchmark');

        if ($section === null) {
            return null;
        }

        $benchmark = [];

        foreach ($section as $line) {
            if (preg_match('/^- ([a-z_]+):\s*(\S+)\s*$/', $line, $match) !== 1) {
                continue;
            }

            $number = is_numeric($match[2]) ? $match[2] + 0 : LocaleNumber::float($match[2]);

            if (in_array($match[1], self::BENCHMARK_KEYS, true) && $number !== null && is_finite($number)) {
                $benchmark[$match[1]] = $number;
            }
        }

        return $benchmark;
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>|null
     */
    private function section(array $lines, string $title): ?array
    {
        $found = false;
        $inSection = false;
        $body = [];

        foreach ($lines as $line) {
            if (str_starts_with($line, '## ')) {
                $inSection = trim(substr($line, 3)) === $title;
                $found = $found || $inSection;

                continue;
            }

            if ($inSection) {
                $body[] = $line;
            }
        }

        return $found ? $body : null;
    }

    private function checksum(?string $value): ?string
    {
        return $value !== null && preg_match('/\A[0-9a-f]{64}\z/i', $value) === 1 ? mb_strtolower($value) : null;
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
