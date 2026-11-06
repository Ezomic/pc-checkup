<?php

declare(strict_types=1);

namespace App\Services\Reports;

use Illuminate\Support\Str;

/**
 * @phpstan-import-type Card from ReportDetails
 * @phpstan-import-type PlainBar from ReportDetails
 *
 * @phpstan-type Place array{key: string, bytes: float, count: int|null}
 * @phpstan-type BigFile array{name: string, bytes: float, kind: string, folder: string|null}
 */
final readonly class ReportReclaimable
{
    public const MINIMUM_FILE_MB = 50;

    private const FILE_LIMIT = 10;

    private const NAME_LIMIT = 80;

    private const FOLDER_LIMIT = 60;

    private const MAX_COUNT = 999999999999;

    private const MINIMUM_SHARE = 1;

    private const BACKUPS = 'backups';

    private const OTHER_KIND = 'Other';

    private const KINDS = ['Photos', 'Videos', 'Music', 'Documents', 'Archives', 'Apps', self::OTHER_KIND];

    private const DISK_CLEANUP = 'Windows can clear this for you. Open Disk Cleanup, or Settings, System, Storage, Temporary files, instead of deleting it by hand.';

    private const PLACES = [
        'trash' => ['Trash', 'item', 'Empty the Trash. Look through it first, because emptying it cannot be undone.'],
        'recycle' => ['Recycle Bin', 'item', 'Empty the Recycle Bin. Look through it first, because emptying it cannot be undone.'],
        'caches' => ['App caches', 'item', 'Safe to clear: apps rebuild them when they need them.'],
        'installers' => ['Installers in Downloads', 'file', 'Delete them after the app is installed. You can download them again if you need one.'],
        self::BACKUPS => ['Old iPhone and iPad backups', 'backup', 'Check that you no longer need them before you delete one.'],
        'derived' => ['Xcode build files', 'item', 'Safe to delete: Xcode builds them again when you open a project.'],
        'devicesupport' => ['Xcode device support files', 'item', 'Safe to delete: Xcode downloads them again when you plug in an iPhone or iPad.'],
        'temp' => ['Temporary files', 'item', 'Safe to clear: apps and Windows make them again when they need them.'],
        'systemtemp' => ['Windows temporary files', 'item', self::DISK_CLEANUP],
        'updates' => ['Downloaded Windows updates', 'item', self::DISK_CLEANUP],
        'windowsold' => ['Previous Windows version (Windows.old)', 'item', 'Windows removes it by itself after a while. Disk Cleanup can remove it sooner, which is safer than deleting it by hand.'],
    ];

    /**
     * @return Card|null
     */
    public function card(ReportSections $sections): ?array
    {
        $text = $sections->text('Space you can get back');

        if ($text === null) {
            return null;
        }

        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $places = $this->places($lines);
        $files = $this->files($lines);

        if ($places === [] && $files === []) {
            return null;
        }

        $bars = [];

        if ($places !== []) {
            $bars[] = ['title' => 'What you can clear', 'items' => $this->placeBars($places)];
        }

        if ($files !== []) {
            $bars[] = ['title' => 'Biggest files', 'items' => $this->fileBars($files)];
        }

        return [
            'id' => 'reclaimable',
            'title' => 'Space you can get back',
            'summary' => $this->summary($places),
            'rows' => [],
            'bars' => $bars,
            'notes' => $this->notes($lines, $places, $files),
            'list' => null,
        ];
    }

    /**
     * @param  list<string>  $lines
     * @return list<Place>
     */
    private function places(array $lines): array
    {
        $places = [];

        foreach ($lines as $line) {
            $parts = explode('|', trim($line));
            $key = strtolower($parts[1] ?? '');

            if (count($parts) !== 4 || $parts[0] !== 'reclaim' || ! isset(self::PLACES[$key]) || isset($places[$key])) {
                continue;
            }

            $bytes = ByteSize::parse($parts[2]);

            if ($bytes !== null && $bytes > 0) {
                $count = LocaleNumber::count($parts[3]);
                $places[$key] = ['key' => $key, 'bytes' => $bytes, 'count' => $count !== null && $count <= self::MAX_COUNT ? $count : null];
            }
        }

        $places = array_values($places);
        usort($places, fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

        return $places;
    }

    /**
     * @param  list<string>  $lines
     * @return list<BigFile>
     */
    private function files(array $lines): array
    {
        $files = [];

        foreach ($lines as $line) {
            $parts = explode('|', trim($line));
            $name = count($parts) === 5 && $parts[0] === 'file' ? $this->clean($parts[1], self::NAME_LIMIT) : null;
            $bytes = $name === null ? null : ByteSize::parse($parts[2]);

            if ($name === null || $bytes === null || $bytes <= 0) {
                continue;
            }

            $folder = $this->clean($parts[4], self::FOLDER_LIMIT);
            $key = mb_strtolower("{$name}|{$folder}");

            if (! isset($files[$key]) || $files[$key]['bytes'] < $bytes) {
                $files[$key] = ['name' => $name, 'bytes' => $bytes, 'kind' => $this->kind($parts[3]), 'folder' => $folder];
            }
        }

        $files = array_values($files);
        usort($files, fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

        return array_slice($files, 0, self::FILE_LIMIT);
    }

    private function kind(string $value): string
    {
        foreach (self::KINDS as $kind) {
            if (strcasecmp($kind, trim($value)) === 0) {
                return $kind;
            }
        }

        return self::OTHER_KIND;
    }

    private function clean(string $name, int $limit): ?string
    {
        $spaced = (string) preg_replace('/[\t\n\r\f\x0B\p{Zs}\p{Zl}\p{Zp}]+/u', ' ', mb_scrub($name));
        $stripped = (string) preg_replace('~[\p{Cc}/\\\\|]|(?![\x{200C}\x{200D}])\p{Cf}~u', '', $spaced);
        $clean = trim(mb_substr(trim((string) preg_replace('/ {2,}/', ' ', $stripped)), 0, $limit));

        return in_array($clean, ['', '.', '..'], true) ? null : $clean;
    }

    /**
     * @param  list<Place>  $places
     */
    private function summary(array $places): ?string
    {
        $total = array_sum(array_column(array_filter($places, fn (array $place): bool => $place['key'] !== self::BACKUPS), 'bytes'));

        return $total > 0 ? 'About '.ByteSize::format($total).' can usually be cleared.' : null;
    }

    /**
     * @param  list<Place>  $places
     * @return list<PlainBar>
     */
    private function placeBars(array $places): array
    {
        $biggest = $places[0]['bytes'];

        return array_map(function (array $place) use ($biggest): array {
            [$label, $noun, $advice] = self::PLACES[$place['key']];
            $count = $place['count'] === null || $place['count'] === 0 ? null : number_format($place['count']).' '.Str::plural($noun, $place['count']).'.';

            return [
                'label' => $label,
                'value' => ByteSize::format($place['bytes']),
                'detail' => implode(' ', array_filter([$count, $advice])),
                'share' => $this->share($place['bytes'], $biggest),
            ];
        }, $places);
    }

    /**
     * @param  list<BigFile>  $files
     * @return list<PlainBar>
     */
    private function fileBars(array $files): array
    {
        $biggest = $files[0]['bytes'];

        return array_map(fn (array $file): array => [
            'label' => $file['name'],
            'value' => ByteSize::format($file['bytes']),
            'detail' => "{$file['kind']}, in ".($file['folder'] ?? 'your user folder'),
            'share' => $this->share($file['bytes'], $biggest),
        ], $files);
    }

    private function share(float $bytes, float $biggest): int
    {
        return max((int) round($bytes / $biggest * 100), self::MINIMUM_SHARE);
    }

    /**
     * @param  list<string>  $lines
     * @param  list<Place>  $places
     * @param  list<BigFile>  $files
     * @return list<string>
     */
    private function notes(array $lines, array $places, array $files): array
    {
        $snapshots = $this->snapshots($lines);
        $unreadable = $this->unreadable($lines, array_column($places, 'key'));

        return [
            ...($this->partial($lines) ? ['The scan stopped at its time limit, so these sizes are a minimum and there may be more to clear.'] : []),
            ...($unreadable === [] ? [] : ['Could not be looked at, so they are not counted: '.implode(', ', $unreadable).'.']),
            ...(in_array(self::BACKUPS, array_column($places, 'key'), true) ? ['Old iPhone and iPad backups are not counted in the total, because you may still need them.'] : []),
            ...($snapshots === 0 ? [] : [$snapshots === 1
                ? '1 local Time Machine snapshot is kept on this Mac. macOS frees it itself when it needs the space, so there is nothing for you to do.'
                : number_format($snapshots).' local Time Machine snapshots are kept on this Mac. macOS frees them itself when it needs the space, so there is nothing for you to do.']),
            ...($files === [] ? [] : [
                'Only files of '.self::MINIMUM_FILE_MB.' MB or more are listed. Hidden folders, apps, the Library and AppData folders and files that only exist in the cloud are left out.',
                'Only you see the file names and folder names in this card: they are not on the pages you share with clients. The scan only looked, it deleted nothing.',
            ]),
        ];
    }

    /**
     * @param  list<string>  $lines
     * @param  list<string>  $measured
     * @return list<string>
     */
    private function unreadable(array $lines, array $measured): array
    {
        $labels = [];

        foreach ($lines as $line) {
            $parts = explode('|', trim($line));
            $key = strtolower($parts[1] ?? '');

            if (count($parts) === 2 && $parts[0] === 'unreadable' && isset(self::PLACES[$key]) && ! in_array($key, $measured, true)) {
                $labels[self::PLACES[$key][0]] = true;
            }
        }

        return array_keys($labels);
    }

    /**
     * @param  list<string>  $lines
     */
    private function snapshots(array $lines): int
    {
        foreach ($lines as $line) {
            $parts = explode('|', trim($line));

            if (count($parts) === 2 && $parts[0] === 'snapshots') {
                $count = LocaleNumber::count($parts[1]);

                return $count !== null && $count <= self::MAX_COUNT ? $count : 0;
            }
        }

        return 0;
    }

    /**
     * @param  list<string>  $lines
     */
    private function partial(array $lines): bool
    {
        foreach ($lines as $line) {
            if (preg_match('/^scan:\s*(\S+)/i', trim($line), $match) === 1) {
                return strtolower($match[1]) !== 'complete';
            }
        }

        return true;
    }
}
