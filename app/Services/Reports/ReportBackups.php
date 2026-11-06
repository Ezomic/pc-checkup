<?php

declare(strict_types=1);

namespace App\Services\Reports;

use Carbon\CarbonImmutable;

/**
 * @phpstan-import-type Card from ReportDetails
 * @phpstan-import-type Row from ReportDetails
 *
 * @phpstan-type Source array{label: string, readable: bool, days: int|null, never: bool, date: string|null, setUp: bool, folders: list<string>|null}
 */
final readonly class ReportBackups
{
    public const GOOD_WITHIN_DAYS = 7;

    public const STALE_AFTER_DAYS = 30;

    public const VERDICT_LABEL = 'Backup';

    private const FOLDERS = ['Desktop', 'Documents', 'Pictures'];

    private const DATED = ['Time Machine' => 'timemachine', 'File History' => 'filehistory'];

    private const CLOUD = ['iCloud Drive Desktop and Documents' => 'icloud-folders', 'OneDrive folder backup' => 'onedrive-folders'];

    /**
     * @return Card|null
     */
    public function card(ReportSections $sections): ?array
    {
        $text = $sections->text('Backup freshness');
        $lines = $text === null ? [] : (preg_split('/\r\n|\r|\n/', $text) ?: []);
        $scanned = ReportDates::parse(ReportDates::keyValue($lines, 'scanned'));

        if ($scanned === null) {
            return null;
        }

        $sources = $this->sources($lines, $scanned);
        $restore = $this->restorePoint(ReportDates::keyValue($lines, 'restorepoint-last'), $scanned);
        $rows = array_values(array_filter([
            $this->verdict($sources),
            ...array_map(fn (array $source): ?array => $this->row($source), $sources),
            $restore === null ? null : ['label' => 'Last restore point', 'value' => $restore, 'tone' => 'neutral'],
        ]));

        if ($rows === []) {
            return null;
        }

        return [
            'id' => 'backups',
            'title' => 'Backups',
            'rows' => $rows,
            'notes' => $this->notes($sources),
            'list' => null,
        ];
    }

    /**
     * @param  list<string>  $lines
     * @return list<Source>
     */
    private function sources(array $lines, CarbonImmutable $scanned): array
    {
        $sources = [];

        foreach (self::DATED as $label => $key) {
            $state = $this->read($lines, $key);

            if ($state !== null) {
                $sources[] = $this->dated($label, $state, $this->read($lines, "{$key}-last"), $scanned);
            }
        }

        foreach (self::CLOUD as $label => $key) {
            $value = $this->read($lines, $key);

            if ($value !== null) {
                $sources[] = $this->cloud($label, $value);
            }
        }

        return $sources;
    }

    /**
     * @param  list<string>  $lines
     */
    private function read(array $lines, string $key): ?string
    {
        $value = ReportDates::keyValue($lines, $key);

        return $value === null || $value === '' ? null : strtolower($value);
    }

    /**
     * @return Source
     */
    private function dated(string $label, string $state, ?string $last, CarbonImmutable $scanned): array
    {
        $date = ReportDates::parse($last);
        $days = $date === null ? null : ReportDates::daysBetween($date, $scanned);
        $setUp = $state === 'set up';

        return [
            'label' => $label,
            'readable' => $setUp || $state === 'not set up',
            'days' => $setUp ? $days : null,
            'never' => $setUp && $last === 'none',
            'date' => $setUp && $days !== null ? $date->format('Y-m-d') : null,
            'setUp' => $setUp,
            'folders' => null,
        ];
    }

    /**
     * @return Source
     */
    private function cloud(string $label, string $value): array
    {
        $found = [];

        foreach (explode(',', $value) as $name) {
            $folder = ucfirst(trim($name));

            if (in_array($folder, self::FOLDERS, true)) {
                $found[$folder] = $folder;
            }
        }

        $readable = $value === 'none' || $found !== [];

        return ['label' => $label, 'readable' => $readable, 'days' => null, 'never' => false, 'date' => null, 'setUp' => false, 'folders' => $readable ? array_values($found) : null];
    }

    private function restorePoint(?string $value, CarbonImmutable $scanned): ?string
    {
        if ($value !== null && strtolower($value) === 'none') {
            return 'None found';
        }

        $date = ReportDates::parse($value);
        $days = $date === null ? null : ReportDates::daysBetween($date, $scanned);

        return $date === null || $days === null ? null : $date->format('Y-m-d').' ('.$this->ago($days).')';
    }

    private function ago(int $days): string
    {
        return match ($days) {
            0 => 'today',
            1 => 'yesterday',
            default => "{$days} days ago",
        };
    }

    /**
     * @return 'good'|'bad'|'neutral'
     */
    private function tone(int $days): string
    {
        return match (true) {
            $days <= self::GOOD_WITHIN_DAYS => 'good',
            $days <= self::STALE_AFTER_DAYS => 'neutral',
            default => 'bad',
        };
    }

    /**
     * @param  list<Source>  $sources
     * @return Row|null
     */
    private function verdict(array $sources): ?array
    {
        $days = array_filter(array_column($sources, 'days'), fn (?int $value): bool => $value !== null);
        $cloudOn = array_filter($sources, fn (array $source): bool => $source['folders'] !== null && $source['folders'] !== []) !== [];
        $setUp = array_filter($sources, fn (array $source): bool => $source['setUp'] && ! $source['never']) !== [];
        $never = array_filter($sources, fn (array $source): bool => $source['never']) !== [];
        $allReadable = array_filter($sources, fn (array $source): bool => ! $source['readable']) === [];
        $label = self::VERDICT_LABEL;

        return match (true) {
            $days !== [] => $this->make($label, 'Last backup '.$this->ago(min($days)), $this->tone(min($days))),
            $cloudOn => $this->make($label, 'Your folders are kept in the cloud, but no backup copy was found', 'neutral'),
            $setUp => $this->make($label, 'Set up, but the date of the last backup could not be read', 'neutral'),
            $never => $this->make($label, 'Set up, but no backup has been made yet', 'bad'),
            $sources !== [] && $allReadable => $this->make($label, 'No backup found', 'bad'),
            default => null,
        };
    }

    /**
     * @param  Source  $source
     * @return Row|null
     */
    private function row(array $source): ?array
    {
        if (! $source['readable']) {
            return null;
        }

        $label = $source['label'];

        return match (true) {
            $source['folders'] !== null => $this->make($label, $source['folders'] === [] ? 'Not turned on' : implode(', ', $source['folders']), 'neutral'),
            ! $source['setUp'] => $this->make($label, 'Not set up', 'neutral'),
            $source['never'] => $this->make($label, 'Set up, no backup yet', 'bad'),
            $source['days'] !== null => $this->make($label, "Last backup {$source['date']} (".$this->ago($source['days']).')', $this->tone($source['days'])),
            default => $this->make($label, 'Set up', 'neutral'),
        };
    }

    /**
     * @param  'good'|'bad'|'neutral'  $tone
     * @return Row
     */
    private function make(string $label, string $value, string $tone): array
    {
        return ['label' => $label, 'value' => $value, 'tone' => $tone];
    }

    /**
     * @param  list<Source>  $sources
     * @return list<string>
     */
    private function notes(array $sources): array
    {
        $missing = array_column(array_filter($sources, fn (array $source): bool => ! $source['readable']), 'label');

        return [
            ...($missing === [] ? [] : ['The scan could not check: '.implode(', ', $missing).'.']),
            'iCloud and OneDrive keep your files in sync, which is not a dated backup. Other backup software cannot be seen by this scan.',
        ];
    }
}
