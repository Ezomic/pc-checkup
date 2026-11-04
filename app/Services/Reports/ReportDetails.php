<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Services\Flags\FlagAdvisor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * @phpstan-type Row array{label: string, value: string, tone: 'good'|'bad'|'neutral'}
 * @phpstan-type Card array{id: string, title: string, rows: list<Row>, list: array{title: string, items: list<Row>}|null}
 */
final readonly class ReportDetails
{
    private const FOLDER_LIMIT = 5;

    private const VALUE_LIMIT = 80;

    private const UNITS = ['B' => 1, 'K' => 1024, 'M' => 1024 ** 2, 'G' => 1024 ** 3, 'T' => 1024 ** 4, 'P' => 1024 ** 5];

    private const BATTERY_CONDITIONS = [
        'normal' => ['Normal', 'good'],
        'service recommended' => ['Service recommended, the battery is worn', 'bad'],
        'poor' => ['Poor, replace the battery', 'bad'],
        'replace soon' => ['Replace soon', 'bad'],
        'replace now' => ['Replace now', 'bad'],
    ];

    public function __construct(private ReportFacts $facts) {}

    /**
     * @param  list<string>  $flags
     * @return list<Card>
     */
    public function cards(string $markdown, array $flags, ?string $deviceType): array
    {
        $sections = ReportSections::parse($markdown);

        return array_values(array_filter([
            $this->age($sections, $flags),
            $this->storage($sections, $this->facts->from($markdown)['free_disk']),
            $this->battery($sections, $deviceType),
        ]));
    }

    /**
     * @param  list<string>  $flags
     * @return Card|null
     */
    private function age(ReportSections $sections, array $flags): ?array
    {
        $model = $sections->header('model');
        $identifier = $this->capture('/\(([A-Za-z]+\d+,\d+)\)$/', $model);
        $name = $identifier === null ? $model : trim(substr((string) $model, 0, -strlen($identifier) - 2));
        $year = $identifier === null ? null : MacModelYears::year($identifier);
        $system = $this->operatingSystem($sections);

        return $this->card('age', 'Age and support', [
            $this->row('Model', $name === '' ? $model : $name),
            $this->row('First released', $year === null ? null : (string) $year),
            $this->row('Operating system', $system),
            $system === null ? null : $this->support($flags),
            $this->row('Last update installed', $this->lastUpdate($sections)),
        ]);
    }

    /**
     * @param  list<string>  $flags
     * @return Row
     */
    private function support(array $flags): array
    {
        $unsupported = array_filter($flags, fn (string $flag): bool => preg_match(FlagAdvisor::UNSUPPORTED_OS, $flag) === 1) !== [];

        return $unsupported
            ? ['label' => 'Security support', 'value' => 'Out of security support', 'tone' => 'bad']
            : ['label' => 'Security support', 'value' => 'No support problem found', 'tone' => 'good'];
    }

    private function operatingSystem(ReportSections $sections): ?string
    {
        $version = $sections->header('macos');

        if ($version !== null) {
            return 'macOS '.trim((string) preg_replace('/\s*\([^)]*\)$/', '', $version));
        }

        $version = $this->capture('/^ProductVersion:\s*(\S+)/m', $sections->text('macOS version'));

        return $version === null ? $sections->header('windows') : 'macOS '.$version;
    }

    private function lastUpdate(ReportSections $sections): ?string
    {
        $text = $sections->text('Software update age') ?? $sections->text('Windows Update');
        $found = $text !== null && preg_match('/^Newest installed (?:macOS )?update:\s*(\d{4})-(\d{2})-(\d{2})\s*\((\d+) days? ago\)/m', $text, $match) === 1;

        if (! $found || ! checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
            return null;
        }

        $days = (int) $match[4];

        return CarbonImmutable::createFromDate((int) $match[1], (int) $match[2], (int) $match[3])->format('j F Y').", {$days} ".Str::plural('day', $days).' before the scan';
    }

    /**
     * @return Card|null
     */
    private function storage(ReportSections $sections, ?string $freeDisk): ?array
    {
        $capacity = $this->macCapacity($sections->text('Disk usage')) ?? $this->windowsCapacity($sections->text('Storage (volumes and free space)'));
        $health = $this->macHealth($sections->text('Storage (boot volume)')) ?? $this->windowsHealth($sections->text('Storage (physical disks)'));

        return $this->card('storage', 'Storage', [
            $this->row('Free', $this->freeText($capacity, $this->capture('/^(\d{1,3})%$/', $freeDisk))),
            $capacity === null ? null : $this->row('Used', $this->format(max($capacity['size'] - $capacity['free'], 0.0)).' of '.$this->format($capacity['size'])),
            isset($health['health']) ? $this->healthRow($health['health']) : null,
            $this->row('Drive type', $health['drive'] ?? null),
        ], $this->folders($sections->text('Performance: biggest folders in home')));
    }

    /**
     * @param  array{size: float, free: float}|null  $capacity
     */
    private function freeText(?array $capacity, ?string $percent): ?string
    {
        $amount = $capacity === null ? null : $this->format($capacity['free']);

        return match (true) {
            $amount !== null && $percent !== null => "{$amount} ({$percent}%)",
            $amount !== null => $amount,
            $percent !== null => "{$percent}%",
            default => null,
        };
    }

    /**
     * @return array{size: float, free: float}|null
     */
    private function macCapacity(?string $text): ?array
    {
        foreach (explode("\n", (string) $text) as $line) {
            if (preg_match('/^\S+\s+(\S+)\s+(\S+)\s+(\S+)\s+\d+%(?:\s+\S+){0,3}\s+\/$/', $line, $match) !== 1) {
                continue;
            }

            $size = $this->bytes($match[1]);
            $free = $this->bytes($match[3]);

            if ($size !== null && $free !== null) {
                return ['size' => $size, 'free' => $free];
            }
        }

        return null;
    }

    /**
     * @return array{size: float, free: float}|null
     */
    private function windowsCapacity(?string $text): ?array
    {
        $volumes = [];

        foreach (explode("\n", (string) $text) as $line) {
            if (preg_match('/^(\w:)\s+.*?(\d+)\s+(\d+)\s+(\d+)\s*$/', $line, $match) === 1) {
                $volumes[$match[1]] = ['size' => (float) $match[2] * self::UNITS['G'], 'free' => (float) $match[3] * self::UNITS['G']];
            }
        }

        return $volumes['C:'] ?? array_values($volumes)[0] ?? null;
    }

    /**
     * @return array{health: string|null, drive: string|null}|null
     */
    private function macHealth(?string $text): ?array
    {
        $smart = $this->capture('/^\s*SMART Status:\s*(.+?)\s*$/mi', $text);
        $solid = $this->capture('/^\s*Solid State:\s*(\S+)/mi', $text);

        if ($smart === null && $solid === null) {
            return null;
        }

        return [
            'health' => match ($smart) {
                null => null,
                'Verified' => 'Healthy',
                'Not Supported' => 'Not reported by this drive',
                default => $this->problem($smart),
            },
            'drive' => match (strtolower((string) $solid)) {
                'yes' => 'Solid state drive',
                'no' => 'Spinning hard drive',
                default => null,
            },
        ];
    }

    /**
     * @return array{health: string|null, drive: string|null}|null
     */
    private function windowsHealth(?string $text): ?array
    {
        $kinds = [];
        $problem = null;

        foreach (explode("\n", (string) $text) as $line) {
            if (preg_match('/\s(SSD|HDD|SCM|Unspecified)\s+(\S+)\s+(\d+)\s+(\S+)\s+\S.*$/', $line, $match) !== 1) {
                continue;
            }

            $kinds[$match[1]] = true;

            if ($match[4] !== 'Healthy') {
                $problem ??= $this->problem($match[4]);
            }
        }

        if ($kinds === []) {
            return null;
        }

        return [
            'health' => $problem ?? 'Healthy',
            'drive' => match (true) {
                isset($kinds['SSD'], $kinds['HDD']) => 'Both solid state and spinning',
                isset($kinds['SSD']) => 'Solid state drive',
                isset($kinds['HDD']) => 'Spinning hard drive',
                default => null,
            },
        ];
    }

    /**
     * @return Row
     */
    private function healthRow(string $health): array
    {
        $tone = match (true) {
            $health === 'Healthy' => 'good',
            str_starts_with($health, 'Problem reported') => 'bad',
            default => 'neutral',
        };

        return ['label' => 'Health', 'value' => $health, 'tone' => $tone];
    }

    private function problem(string $status): string
    {
        return 'Problem reported: '.$this->clean($status);
    }

    /**
     * @return array{title: string, items: list<Row>}|null
     */
    private function folders(?string $text): ?array
    {
        $items = [];

        foreach (explode("\n", (string) $text) as $line) {
            if (preg_match('/^\s*(\S+)\s+(\S.*?)\s*$/', $line, $match) !== 1) {
                continue;
            }

            $bytes = $this->bytes($match[1]);

            if ($bytes !== null) {
                $items[] = ['label' => $this->folderName($match[2]), 'value' => $this->format($bytes), 'tone' => 'neutral'];
            }
        }

        $items = array_slice($items, 0, self::FOLDER_LIMIT);

        return $items === [] ? null : ['title' => 'Biggest folders', 'items' => $items];
    }

    private function folderName(string $path): string
    {
        return $this->clean($this->capture('#^/(?:Users|home)/[^/]+/(.+)$#', $path) ?? basename($path));
    }

    /**
     * @return Card|null
     */
    private function battery(ReportSections $sections, ?string $deviceType): ?array
    {
        if (preg_match('/^(\S+) cycles,\s*(.+)$/i', (string) $sections->header('battery'), $line) !== 1) {
            return null;
        }

        $mac = preg_match('/^condition (.+?), max capacity (.+)$/i', $line[2], $parts) === 1;
        $condition = $mac ? $this->known($parts[1]) : null;
        $capacity = $this->capture('/^(\d{1,3})%/', $mac ? $parts[2] : $line[2]);
        $cycles = ctype_digit($line[1]) ? $line[1] : null;

        if ($cycles === null && $condition === null && $capacity === null && $deviceType !== 'laptop') {
            return null;
        }

        return $this->card('battery', 'Battery', [
            $this->row('Charge cycles', $cycles ?? 'Not reported'),
            $mac ? $this->conditionRow($condition) : null,
            $this->row('Maximum capacity', $capacity === null ? 'Not reported' : "{$capacity}% of when it was new"),
        ]);
    }

    private function known(string $value): ?string
    {
        return strtolower(trim($value)) === 'n/a' ? null : trim($value);
    }

    /**
     * @return Row
     */
    private function conditionRow(?string $condition): array
    {
        if ($condition === null) {
            return ['label' => 'Condition', 'value' => 'Not reported', 'tone' => 'neutral'];
        }

        [$words, $tone] = self::BATTERY_CONDITIONS[strtolower($condition)] ?? [$this->clean($condition), 'neutral'];

        return ['label' => 'Condition', 'value' => $words, 'tone' => $tone];
    }

    /**
     * @param  list<Row|null>  $rows
     * @param  array{title: string, items: list<Row>}|null  $list
     * @return Card|null
     */
    private function card(string $id, string $title, array $rows, ?array $list = null): ?array
    {
        $rows = array_values(array_filter($rows));

        if ($rows === [] && $list === null) {
            return null;
        }

        return ['id' => $id, 'title' => $title, 'rows' => $rows, 'list' => $list];
    }

    /**
     * @return Row|null
     */
    private function row(string $label, ?string $value): ?array
    {
        return $value === null || $value === '' ? null : ['label' => $label, 'value' => $this->clean($value), 'tone' => 'neutral'];
    }

    private function clean(string $value): string
    {
        return Str::limit(trim($value), self::VALUE_LIMIT, '');
    }

    private function capture(string $pattern, ?string $text): ?string
    {
        return $text !== null && preg_match($pattern, $text, $match) === 1 ? $match[1] : null;
    }

    private function bytes(string $token): ?float
    {
        if (preg_match('/^(\d+(?:\.\d+)?)([BKMGTP]?)i?B?$/', $token, $match) !== 1) {
            return null;
        }

        return (float) $match[1] * self::UNITS[$match[2] === '' ? 'B' : $match[2]];
    }

    private function format(float $bytes): string
    {
        foreach (['TB' => 'T', 'GB' => 'G', 'MB' => 'M', 'KB' => 'K', 'B' => 'B'] as $label => $unit) {
            if ($bytes >= self::UNITS[$unit]) {
                $value = $bytes / self::UNITS[$unit];

                return ($value >= 10 ? (string) round($value) : (string) round($value, 1))." {$label}";
            }
        }

        return '0 B';
    }
}
