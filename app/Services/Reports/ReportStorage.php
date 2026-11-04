<?php

declare(strict_types=1);

namespace App\Services\Reports;

/**
 * @phpstan-type Item array{name: string, bytes: float, count: int|null}
 * @phpstan-type Breakdown array{partial: bool, version: int, home: float|null, rootsTotal: float|null, folders: list<Item>, library: list<Item>, roots: list<Item>, missing: list<string>, homeFolders: list<Item>, homeInside: list<array{parent: string, items: list<Item>}>, categories: list<Item>, extensions: list<Item>}
 */
final readonly class ReportStorage
{
    private const ROOT_LIMIT = 6;

    private const EXTENSION_LIMIT = 8;

    private const EXTENSION_LENGTH = 10;

    private const MINIMUM_EXTENSION_BYTES = 100 * 1024;

    private const NAME_LIMIT = 40;

    private const HOME_NAME_LIMIT = 60;

    private const HOME_LIMIT = 8;

    private const INSIDE_PARENTS = 2;

    private const INSIDE_LIMIT = 5;

    private const MAX_BYTES = 1024 ** 5;

    private const BUNDLES_LABEL = 'app and system bundles';

    private const NO_EXTENSION_LABEL = 'no extension';

    private const UNITS = ['' => 1024, 'B' => 1, 'KB' => 1024, 'MB' => 1024 ** 2, 'GB' => 1024 ** 3, 'TB' => 1024 ** 4];

    private const FOLDERS = ['Downloads', 'Documents', 'Desktop', 'Pictures', 'Videos', 'Movies', 'Music', 'Applications', 'Library', 'Program Files', 'AppData'];

    private const LIBRARY = ['Application Support', 'Caches', 'Containers', 'Group Containers', 'Mail', 'Messages', 'Mobile Documents', 'Developer', 'Logs', 'Safari', 'Local', 'Roaming', 'Temp'];

    private const LIBRARY_LABELS = ['Mobile Documents' => 'Mobile Documents (iCloud)', 'Local' => 'AppData Local', 'Roaming' => 'AppData Roaming', 'Temp' => 'Temp files'];

    private const ROOTS = ['Users', 'Applications', 'Library', 'System', 'private', 'usr', 'opt', 'var', 'bin', 'sbin', 'Windows', 'Program Files', 'Program Files (x86)', 'ProgramData'];

    private const CATEGORIES = ['Photos', 'Videos', 'Music', 'Documents', 'Archives', 'Apps', 'Other'];

    private const BUNDLES = ['package', 'app', 'framework', 'bundle', 'lproj', 'plugin', 'kext', 'xpc', 'appex', 'dsym', 'photoslibrary', 'prefpane', 'saver', 'component', 'qlgenerator', 'mdimporter'];

    /**
     * @return Breakdown|null
     */
    public function read(ReportSections $sections): ?array
    {
        $text = $sections->text('Storage breakdown');

        if ($text === null) {
            return null;
        }

        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $version = $this->version($lines);
        $homeFolders = $this->homeFolders($lines);
        $breakdown = [
            'partial' => $this->partial($lines),
            'version' => $version,
            'home' => $this->single($lines, 'home'),
            'rootsTotal' => $this->single($lines, 'roots_total'),
            'folders' => $this->items($lines, 'folder', 2, fn (string $name): ?string => $version >= 2 ? $this->homeLabel($name) : $this->known($name, self::FOLDERS)),
            'library' => $this->items($lines, 'library', 2, fn (string $name): ?string => $this->libraryLabel($name)),
            'roots' => array_slice($this->items($lines, 'root', 2, fn (string $name): ?string => $this->folderName($name), false), 0, self::ROOT_LIMIT),
            'missing' => $this->missing($lines),
            'homeFolders' => $homeFolders,
            'homeInside' => $this->homeInside($lines, $homeFolders),
            'categories' => $this->items($lines, 'type', 3, fn (string $name): ?string => $this->known($name, self::CATEGORIES), false),
            'extensions' => $this->extensions($lines),
        ];

        return $this->empty($breakdown) ? null : $breakdown;
    }

    /**
     * @param  array<string, mixed>  $breakdown
     */
    private function empty(array $breakdown): bool
    {
        return array_filter($breakdown, fn (mixed $value, string $key): bool => ! in_array($key, ['partial', 'version'], true) && $value !== [] && $value !== null, ARRAY_FILTER_USE_BOTH) === [];
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

    /**
     * @param  list<string>  $lines
     */
    private function version(array $lines): int
    {
        foreach ($lines as $line) {
            if (preg_match('/^format:\s*(\d{1,3})\s*$/i', trim($line), $match) === 1) {
                return max((int) $match[1], 1);
            }
        }

        return 1;
    }

    /**
     * @param  list<string>  $lines
     */
    private function single(array $lines, string $kind): ?float
    {
        foreach ($lines as $line) {
            $parts = explode('|', trim($line));

            if (count($parts) === 2 && $parts[0] === $kind && ($bytes = $this->bytes($parts[1])) !== null) {
                return $bytes;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function missing(array $lines): array
    {
        $labels = [];

        foreach ($lines as $line) {
            $parts = explode('|', trim($line));
            $label = count($parts) === 2 && $parts[0] === 'missing' ? $this->missingLabel($parts[1]) : null;

            if ($label !== null && ! in_array($label, $labels, true)) {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    private function missingLabel(string $value): ?string
    {
        [$scope, $name] = str_contains($value, '/') ? explode('/', $value, 2) : ['', $value];

        return match (strtolower(trim($scope))) {
            'root' => $this->known($name, self::ROOTS),
            'home' => $this->homeLabel($name),
            'library' => $this->libraryLabel($name, 'Library: '),
            '' => $this->known($name, self::ROOTS) ?? $this->homeLabel($name) ?? $this->libraryLabel($name, 'Library: '),
            default => null,
        };
    }

    private function homeLabel(string $name): ?string
    {
        $label = $this->known($name, self::FOLDERS);

        return in_array($label, ['Library', 'Applications'], true) ? "{$label} (in your user folder)" : $label;
    }

    private function libraryLabel(string $name, string $prefix = ''): ?string
    {
        $label = $this->known($name, self::LIBRARY);

        return $label === null ? null : $prefix.(self::LIBRARY_LABELS[$label] ?? $label);
    }

    /**
     * @param  list<string>  $lines
     * @return list<Item>
     */
    private function extensions(array $lines): array
    {
        $bundles = ['name' => self::BUNDLES_LABEL, 'bytes' => 0.0, 'count' => null];
        $items = [];

        foreach ($this->items($lines, 'ext', 3, fn (string $name): ?string => $this->extension($name), false) as $item) {
            if (in_array($item['name'], self::BUNDLES, true)) {
                $bundles = ['name' => self::BUNDLES_LABEL, 'bytes' => $bundles['bytes'] + $item['bytes'], 'count' => $this->added($bundles['count'], $item['count'])];

                continue;
            }

            $items[] = $item;
        }

        $items[] = $bundles;
        $items[] = $this->bare($lines);
        $items = array_values(array_filter($items, fn (?array $item): bool => $item !== null && $item['bytes'] >= self::MINIMUM_EXTENSION_BYTES));
        usort($items, fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

        return array_slice($items, 0, self::EXTENSION_LIMIT);
    }

    private function added(?int $total, ?int $count): ?int
    {
        return $total === null && $count === null ? null : (int) $total + (int) $count;
    }

    /**
     * @param  list<string>  $lines
     * @return Item|null
     */
    private function bare(array $lines): ?array
    {
        foreach ($lines as $line) {
            $parts = explode('|', trim($line));

            if (count($parts) === 3 && $parts[0] === 'noext' && ($bytes = $this->bytes($parts[1])) !== null) {
                return ['name' => self::NO_EXTENSION_LABEL, 'bytes' => $bytes, 'count' => $this->count($parts[2])];
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     * @param  callable(string): ?string  $name
     * @return list<Item>
     */
    private function items(array $lines, string $kind, int $fields, callable $name, bool $keepEmpty = true): array
    {
        $items = [];

        foreach ($lines as $line) {
            $parts = explode('|', trim($line));

            if (array_shift($parts) !== $kind || count($parts) !== $fields) {
                continue;
            }

            $label = $name($parts[0]);
            $bytes = $this->bytes($parts[1]);

            if ($label === null || $bytes === null || isset($items[strtolower($label)]) || ($bytes === 0.0 && ! $keepEmpty)) {
                continue;
            }

            $items[strtolower($label)] = ['name' => $label, 'bytes' => $bytes, 'count' => $fields === 3 ? $this->count($parts[2]) : null];
        }

        $items = array_values($items);
        usort($items, fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

        return $items;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function known(string $name, array $allowed): ?string
    {
        foreach ($allowed as $candidate) {
            if (strcasecmp($candidate, trim($name)) === 0) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     * @return list<Item>
     */
    private function homeFolders(array $lines): array
    {
        $items = [];

        foreach ($lines as $line) {
            $parts = explode('|', trim($line));

            if (count($parts) === 3 && $parts[0] === 'home') {
                $this->keepNamed($items, $parts[1], $parts[2]);
            }
        }

        return array_slice($this->biggest($items), 0, self::HOME_LIMIT);
    }

    /**
     * @param  list<string>  $lines
     * @param  list<Item>  $folders
     * @return list<array{parent: string, items: list<Item>}>
     */
    private function homeInside(array $lines, array $folders): array
    {
        $parents = [];

        foreach (array_slice($folders, 0, self::INSIDE_PARENTS) as $folder) {
            $parents[mb_strtolower($folder['name'])] = $folder['name'];
        }

        $found = [];

        foreach ($lines as $line) {
            $parts = explode('|', trim($line));
            $parent = count($parts) === 4 && $parts[0] === 'sub' ? mb_strtolower((string) $this->homeName($parts[1])) : '';

            if (isset($parents[$parent])) {
                $found[$parent] ??= [];
                $this->keepNamed($found[$parent], $parts[2], $parts[3]);
            }
        }

        $inside = [];

        foreach ($parents as $key => $name) {
            $items = array_slice($this->biggest($found[$key] ?? []), 0, self::INSIDE_LIMIT);

            if ($items !== []) {
                $inside[] = ['parent' => $name, 'items' => $items];
            }
        }

        return $inside;
    }

    /**
     * @param  array<string, Item>  $items
     */
    private function keepNamed(array &$items, string $name, string $size): void
    {
        $label = $this->homeName($name);
        $bytes = $this->bytes($size);

        if ($label === null || $bytes === null || $bytes <= 0.0) {
            return;
        }

        $key = mb_strtolower($label);

        if (! isset($items[$key]) || $items[$key]['bytes'] < $bytes) {
            $items[$key] = ['name' => $label, 'bytes' => $bytes, 'count' => null];
        }
    }

    /**
     * @param  array<string, Item>  $items
     * @return list<Item>
     */
    private function biggest(array $items): array
    {
        $items = array_values($items);
        usort($items, fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

        return $items;
    }

    private function homeName(string $name): ?string
    {
        $spaced = (string) preg_replace('/[\t\n\r\f\x0B\p{Zs}\p{Zl}\p{Zp}]+/u', ' ', mb_scrub($name));
        $stripped = (string) preg_replace('~[\p{Cc}/\\\\|]|(?![\x{200C}\x{200D}])\p{Cf}~u', '', $spaced);
        $clean = trim(mb_substr(trim((string) preg_replace('/ {2,}/', ' ', $stripped)), 0, self::HOME_NAME_LIMIT));

        return in_array($clean, ['', '.', '..'], true) ? null : $clean;
    }

    private function folderName(string $name): ?string
    {
        $clean = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^\p{L}\p{N} ._()+\-]/u', '', $name)));
        $clean = mb_substr($clean, 0, self::NAME_LIMIT);

        return $clean === '' ? null : $clean;
    }

    private function extension(string $name): ?string
    {
        $extension = strtolower(trim($name));

        return preg_match('/^[a-z0-9]{1,'.self::EXTENSION_LENGTH.'}$/', $extension) === 1 ? $extension : null;
    }

    private function bytes(string $value): ?float
    {
        if (preg_match('/^(\d+(?:\.\d+)?)\s*(B|KB|MB|GB|TB)?$/i', trim($value), $match) !== 1) {
            return null;
        }

        $bytes = (float) $match[1] * self::UNITS[strtoupper($match[2] ?? '')];

        return $bytes <= self::MAX_BYTES ? $bytes : null;
    }

    private function count(string $value): ?int
    {
        return preg_match('/^\d{1,12}$/', trim($value)) === 1 ? (int) $value : null;
    }
}
