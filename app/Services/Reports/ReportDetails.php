<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Services\Flags\FlagAdvisor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * @phpstan-type Row array{label: string, value: string, tone: 'good'|'bad'|'neutral'}
 * @phpstan-type PlainBar array{label: string, value: string, detail: string|null, share: int}
 * @phpstan-type Bar array{label: string, value: string, detail: string|null, share: int, inside?: array{title: string, items: list<PlainBar>}}
 * @phpstan-type Card array{id: string, title: string, rows: list<Row>, list: array{title: string, items: list<Row>}|null, summary?: string|null, bars?: list<array{title: string, items: list<Bar>}>, notes?: list<string>}
 *
 * @phpstan-import-type Breakdown from ReportStorage
 */
final readonly class ReportDetails
{
    private const FOLDER_LIMIT = 5;

    private const VALUE_LIMIT = 80;

    private const SPINNING_DRIVE = 'Spinning hard drive';

    private const MINIMUM_SHARE = 1;

    private const NAMED_SHARE = 20;

    private const HOME_TITLE = 'Biggest folders in your user folder';

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
        $freeDisk = $this->facts->from($markdown)['free_disk'];

        return array_values(array_filter([
            $this->age($sections, $flags),
            $this->storage($sections, $freeDisk),
            $this->storageBreakdown($sections),
            $this->battery($sections, $deviceType),
            $this->card('security', 'Security summary', (new ReportSecurity)->rows($sections)),
            $this->card('stability', 'Stability', (new ReportStability)->rows($sections, $flags)),
            $this->card('slowness', 'Why is it slow?', (new ReportSlowness)->rows($sections, $freeDisk, $this->spinningDrive($sections))),
            (new ReportStartup)->card($sections),
            (new ReportUpgrade)->card($sections),
            $this->card('verdict', 'Worth keeping?', (new ReportVerdict)->rows($sections, $flags, $freeDisk)),
        ]));
    }

    private function spinningDrive(ReportSections $sections): bool
    {
        $health = $this->macHealth($sections->text('Storage (boot volume)')) ?? $this->windowsHealth($sections->text('Storage (physical disks)'));

        return ($health['drive'] ?? null) === self::SPINNING_DRIVE;
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
            ...$this->newestMacOs($model, $sections),
            $this->row('Last update installed', $this->lastUpdate($sections)),
        ]);
    }

    /**
     * @return list<Row>
     */
    private function newestMacOs(?string $model, ReportSections $sections): array
    {
        $newest = MacNewestOs::forModel($model);
        $name = $newest === null ? null : MacNewestOs::name($newest);
        $running = MacNewestOs::running($sections->header('macos') ?? $this->capture('/^ProductVersion:\s*(\S+)/m', $sections->text('macOS version')));

        if ($newest === null || $name === null || ($running !== null && version_compare($running, $newest, '>'))) {
            return [];
        }

        return array_values(array_filter([
            $this->row('Newest macOS this Mac can run', $name),
            $running !== null && version_compare($running, $newest, '<') && MacNewestOs::isSupportedByApple($newest) ? $this->row('Can be updated to', $name) : null,
        ]));
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
        $capacity = $this->capacity($sections);
        $health = $this->macHealth($sections->text('Storage (boot volume)')) ?? $this->windowsHealth($sections->text('Storage (physical disks)'));
        $breakdown = (new ReportStorage)->read($sections);
        $explained = $breakdown !== null && $breakdown['version'] >= 2 && ($breakdown['home'] !== null || $breakdown['folders'] !== [] || $breakdown['homeFolders'] !== []);

        return $this->card('storage', 'Storage', [
            $this->row('Free', $this->freeText($capacity, $this->capture('/^(\d{1,3})%$/', $freeDisk))),
            $capacity === null ? null : $this->row('Used', $this->format(max($capacity['size'] - $capacity['free'], 0.0)).' of '.$this->format($capacity['size'])),
            isset($health['health']) ? $this->healthRow($health['health']) : null,
            $this->row('Drive type', $health['drive'] ?? null),
        ], $explained ? null : $this->folders($sections->text('Performance: biggest folders in home')));
    }

    /**
     * @return array{size: float, free: float}|null
     */
    public function capacity(ReportSections $sections): ?array
    {
        return $this->macCapacity($sections->text('Disk usage')) ?? $this->windowsCapacity($sections->text('Storage (volumes and free space)'));
    }

    /**
     * @return Card|null
     */
    private function storageBreakdown(ReportSections $sections): ?array
    {
        $read = (new ReportStorage)->read($sections);

        if ($read === null) {
            return null;
        }

        $capacity = $this->capacity($sections);
        $used = $capacity === null ? 0.0 : max($capacity['size'] - $capacity['free'], 0.0);
        $counted = array_sum(array_column($read['categories'], 'bytes'));
        $notMeasured = $this->notMeasured($read, $used);
        $drive = $notMeasured === null ? $read['roots'] : [...$read['roots'], ['name' => 'Not measured', 'bytes' => $notMeasured, 'count' => null]];
        $mine = $read['home'] === null ? $read['folders'] : [['name' => 'Everything in your user folder', 'bytes' => $read['home'], 'count' => null], ...$read['folders']];
        $groups = [
            ['Biggest folders on the drive', $drive, $used, 'used space', false],
            [self::HOME_TITLE, $read['homeFolders'], $used, 'used space', false],
            ['Your folders', $mine, $used, 'used space', false],
            ['Library and AppData', $read['library'], $used, 'used space', false],
            ['Kinds of files in your user folder', $read['categories'], $counted, 'what was counted', true],
            ['Biggest file endings', $read['extensions'], $counted, 'what was counted', true],
        ];
        $bars = [];

        foreach ($groups as [$title, $items, $whole, $of, $withCount]) {
            if ($items !== []) {
                $drawn = $this->bars($items, $whole, $of, $withCount, $title === 'Biggest file endings');
                $bars[] = ['title' => $title, 'items' => $title === self::HOME_TITLE ? $this->withInside($drawn, $read) : $drawn];
            }
        }

        return [
            'id' => 'storage-breakdown',
            'title' => 'Storage breakdown',
            'summary' => $this->summary($read, $used, $notMeasured),
            'rows' => [],
            'list' => null,
            'bars' => $bars,
            'notes' => $this->breakdownNotes($read, $notMeasured !== null),
        ];
    }

    /**
     * @param  Breakdown  $read
     */
    private function notMeasured(array $read, float $used): ?float
    {
        if ($read['version'] < 2 || $read['rootsTotal'] === null || $used <= 0) {
            return null;
        }

        $rest = $used - $read['rootsTotal'];

        return $rest > 0 ? $rest : null;
    }

    /**
     * @param  Breakdown  $read
     */
    private function summary(array $read, float $used, ?float $notMeasured): ?string
    {
        $top = array_slice($read['roots'], 0, 3);
        $named = $this->biggestHomeFolder($read, $used);
        $sentence = null;

        if ($top !== []) {
            $names = array_map(fn (array $item): string => $item['name'].' ('.$this->format($item['bytes']).')', $top);
            $lead = array_sum(array_column($top, 'bytes')) * 2 >= $used && $used > 0 ? 'Most of the space is used by' : 'The biggest folders found are';
            $sentence = "{$lead} ".(count($names) > 1 ? implode(', ', array_slice($names, 0, -1)).' and '.end($names) : $names[0]).'.';
        } elseif ($read['home'] !== null && $named === null) {
            $sentence = 'Your user folder uses '.$this->format($read['home']).'.';
        }

        if ($named !== null) {
            $sentence = trim("{$sentence} {$named}");
        }

        if ($notMeasured !== null) {
            $sentence = trim("{$sentence} ".$this->format($notMeasured).' was not measured.');
        }

        return $sentence;
    }

    /**
     * @param  Breakdown  $read
     */
    private function biggestHomeFolder(array $read, float $used): ?string
    {
        $folder = $read['homeFolders'][0] ?? null;
        $whole = $used > 0 ? $used : (float) $read['home'];

        if ($folder === null || $whole <= 0 || $whole * self::NAMED_SHARE > $folder['bytes'] * 100) {
            return null;
        }

        $biggest = "{$folder['name']} (".$this->format($folder['bytes']).')';

        return $read['home'] === null
            ? "The biggest folder in your user folder is {$biggest}."
            : 'Your user folder uses '.$this->format($read['home'])."; the biggest part is {$biggest}.";
    }

    /**
     * @param  list<PlainBar>  $drawn
     * @param  Breakdown  $read
     * @return list<Bar>
     */
    private function withInside(array $drawn, array $read): array
    {
        $bars = [];

        foreach ($drawn as $index => $bar) {
            $folder = $read['homeFolders'][$index] ?? null;
            $items = array_column($read['homeInside'], 'items', 'parent')[$folder['name'] ?? ''] ?? [];
            $bars[] = $items === [] || $folder === null ? $bar : [...$bar, 'inside' => ['title' => "Inside {$folder['name']}", 'items' => $this->bars($items, $folder['bytes'], $folder['name'], false, false)]];
        }

        return $bars;
    }

    /**
     * @param  Breakdown  $read
     * @return list<string>
     */
    private function breakdownNotes(array $read, bool $hasNotMeasured): array
    {
        $both = in_array('Applications (in your user folder)', array_column($read['folders'], 'name'), true) && in_array('Applications', array_column($read['roots'], 'name'), true);
        $named = $read['homeFolders'] !== [];

        return [
            ...($read['partial'] ? ['The scan stopped at its time limit, so these sizes are a minimum and the real numbers may be bigger.'] : []),
            ...($read['partial'] && $named ? ['The scan stopped at its time limit, so a big folder may be missing from the folders in your user folder.'] : []),
            ...($read['missing'] === [] ? [] : [($read['partial'] ? 'Not measured before the time ran out: ' : 'Could not be read: ').implode(', ', $read['missing']).'.']),
            ...($hasNotMeasured ? ['Not measured is used space that the folders above do not add up to, such as hidden system files, snapshots and anything the scan could not read.'] : []),
            ...($both ? ['Applications in your user folder is a separate folder from the Applications folder on the drive.'] : []),
            ...($named ? ['The names and sizes of the biggest folders in your user folder were recorded so this card can say what takes the space. Only you see them: they are not on the pages you share with clients.'] : []),
            ...($read['categories'] !== [] || $read['extensions'] !== [] ? ['Kinds of files count your own user folder only. '.($named ? 'For them only sizes and counts were recorded, never file names.' : 'Only sizes and counts were recorded, no file names.')] : []),
        ];
    }

    /**
     * @param  list<array{name: string, bytes: float, count: int|null}>  $items
     * @return list<PlainBar>
     */
    private function bars(array $items, float $whole, string $of, bool $withCount, bool $endings): array
    {
        $biggest = max(1.0, ...array_column($items, 'bytes'));

        return array_map(function (array $item) use ($biggest, $whole, $of, $withCount, $endings): array {
            $percent = $whole > 0 && $item['bytes'] > 0 ? $this->percentText(min($item['bytes'] / $whole * 100, 100.0), $of) : null;
            $files = $withCount && $item['count'] !== null ? number_format($item['count']).' '.Str::plural('file', $item['count']) : null;
            $share = $item['bytes'] > 0 ? max((int) round($item['bytes'] / $biggest * 100), self::MINIMUM_SHARE) : 0;
            $other = $withCount && ! $endings && $item['name'] === 'Other' ? 'mostly system and app files' : null;

            return [
                'label' => ($endings && ctype_alnum($item['name']) ? '.' : '').$item['name'],
                'value' => $this->format($item['bytes']),
                'detail' => implode(', ', array_filter([$files, $percent, $other])) ?: null,
                'share' => $share,
            ];
        }, $items);
    }

    private function percentText(float $percent, string $of): string
    {
        return ($percent < 0.5 ? 'under 1%' : round($percent).'%')." of {$of}";
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
                'no' => self::SPINNING_DRIVE,
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
                isset($kinds['HDD']) => self::SPINNING_DRIVE,
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
        $amount = preg_match('/^('.LocaleNumber::PATTERN.')([BKMGTP]?)i?B?$/', $token, $match) === 1 ? LocaleNumber::float($match[1]) : null;

        return $amount === null ? null : $amount * self::UNITS[$match[2] === '' ? 'B' : $match[2]];
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
