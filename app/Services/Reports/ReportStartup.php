<?php

declare(strict_types=1);

namespace App\Services\Reports;

use Illuminate\Support\Str;

/**
 * @phpstan-import-type Card from ReportDetails
 * @phpstan-import-type Row from ReportDetails
 *
 * @phpstan-type Entry array{raw: string, developer: string}
 * @phpstan-type Found array{rows: list<Row>, entries: list<Entry>, notes: list<string>}
 * @phpstan-type Ranked array{name: string, value: string, rank: int}
 */
final readonly class ReportStartup
{
    public const LOGIN_ITEMS_HIGH = 8;

    public const BACKGROUND_ITEMS_HIGH = 25;

    public const LAUNCH_ITEMS_HIGH = 30;

    public const WINDOWS_STARTUP_HIGH = 15;

    private const LIST_LIMIT = 6;

    private const NAME_LIMIT = 40;

    private const BACKGROUND_LINE_CAP = 150;

    private const RANK_RANDOM = 0;

    private const RANK_APPLE_STYLE = 1;

    private const RANK_UNKNOWN = 2;

    private const RANK_KNOWN = 3;

    private const UNKNOWN = 'Not recognised';

    private const RANDOM = 'Odd looking name';

    private const APPLE_STYLE = 'Apple style name, unusual place';

    private const SYSTEM = '/^(?:com\.microsoft\.|securityhealth(?![a-z])|windows ?(?:security|defender)|microsoftedgeautolaunch|msedge(?:autolaunch)?(?![a-z])|ctfmon(?![a-z])|microsoft (?:edge|office|onenote|outlook))/';

    private const KNOWN = [
        ['/(?<![a-z])onedrive/', 'OneDrive', 'Syncs OneDrive files'],
        ['/(?<![a-z])dropbox/', 'Dropbox', 'Syncs Dropbox files'],
        ['/(?<![a-z])spotify/', 'Spotify', 'Music app'],
        ['/(?<![a-z])(?:ms)?teams(?![a-z])|microsoft\.?teams/', 'Teams', 'Chat and video meetings'],
        ['/(?<![a-z])zoom(?![a-z])|zoomus/', 'Zoom', 'Video meetings'],
        ['/(?<![a-z])slack(?![a-z])/', 'Slack', 'Chat app'],
        ['/(?<![a-z])adobe/', 'Adobe', 'Adobe updater or helper'],
        ['/(?<![a-z])(?:google|keystone)/', 'Google', 'Google updater or helper'],
        ['/(?<![a-z])steam(?![a-z])|valvesoftware/', 'Steam', 'Game store'],
        ['/(?<![a-z])icloud/', 'iCloud', 'Syncs iCloud files'],
    ];

    private const WEIGHT_NOTE = 'The scan does not record how much each item slows startup, so this list puts unfamiliar names first instead of the heaviest. Names are matched by wording only: a familiar name is not proof an item is genuine.';

    private const OVERLAP_NOTE = 'Login items, background items and launch agents overlap, so the same app can be counted in more than one row.';

    private const BACKGROUND_CAPPED_NOTE = 'The scan cut the background items list short, so there may be more than the count shows.';

    /**
     * @return Card|null
     */
    public function card(ReportSections $sections): ?array
    {
        $found = array_values(array_filter([$this->mac($sections), $this->windows($sections)]));
        $rows = array_merge(...array_column($found, 'rows'));

        if ($rows === []) {
            return null;
        }

        $list = $this->list(array_merge(...array_column($found, 'entries')));

        return [
            'id' => 'startup',
            'title' => 'Startup items',
            'rows' => $rows,
            'list' => $list,
            'notes' => [...array_merge(...array_column($found, 'notes')), ...($list === null ? [] : [self::WEIGHT_NOTE])],
        ];
    }

    /**
     * @return Found|null
     */
    private function mac(ReportSections $sections): ?array
    {
        $login = $this->loginItems($sections->text('Login items'));
        $background = $this->background($sections->text('Background items (macOS 13+)'));
        $launch = $this->launch(
            $sections->text('Startup items: LaunchAgents and LaunchDaemons'),
            $sections->text('Performance: login item and startup counts'),
        );
        $measures = [
            ['Login items', $login === null ? null : count($login), self::LOGIN_ITEMS_HIGH, null],
            ['Background items', $background === null ? null : count($background['items']), self::BACKGROUND_ITEMS_HIGH, null],
            ['Launch agents and daemons', $launch['total'], self::LAUNCH_ITEMS_HIGH, $launch['detail']],
        ];
        $rows = $this->rows($measures);

        if ($rows === []) {
            return null;
        }

        return [
            'rows' => $rows,
            'entries' => [
                ...$this->entries($login ?? []),
                ...($background === null ? [] : $background['items']),
                ...$this->entries($launch['names']),
            ],
            'notes' => [
                ...$this->highNote($measures, 'on this Mac', 'Switch off what you do not need in System Settings, under General, then Login Items & Extensions.'),
                ...($background !== null && $background['capped'] ? [self::BACKGROUND_CAPPED_NOTE] : []),
                ...(count($rows) > 1 ? [self::OVERLAP_NOTE] : []),
            ],
        ];
    }

    /**
     * @return Found|null
     */
    private function windows(ReportSections $sections): ?array
    {
        $text = $sections->text('Startup programs');

        if ($text === null || preg_match('/^Count:\s*(\d{1,5})\s*$/m', $text, $match) !== 1) {
            return null;
        }

        $count = (int) $match[1];
        $names = $this->programs($text);
        $measures = [['Startup programs', $count, self::WINDOWS_STARTUP_HIGH, null]];

        return [
            'rows' => $this->rows($measures),
            'entries' => $this->entries($names),
            'notes' => [
                ...$this->highNote($measures, 'with Windows', 'Switch off what you do not need in Task Manager, on the Startup tab.'),
                ...($names !== [] && count($names) < $count ? ['The scan named '.count($names).' of the '.number_format($count).' startup programs.'] : []),
            ],
        ];
    }

    /**
     * @param  list<array{string, int|null, int, string|null}>  $measures
     * @return list<Row>
     */
    private function rows(array $measures): array
    {
        $rows = [];

        foreach ($measures as [$label, $count, $high, $detail]) {
            if ($count !== null) {
                $rows[] = [
                    'label' => $label,
                    'value' => number_format($count).($detail === null ? '' : " ({$detail})"),
                    'tone' => $count > $high ? 'bad' : 'neutral',
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  list<array{string, int|null, int, string|null}>  $measures
     * @return list<string>
     */
    private function highNote(array $measures, string $where, string $advice): array
    {
        $over = [];

        foreach ($measures as [$label, $count, $high]) {
            if ($count !== null && $count > $high) {
                $over[] = number_format($count).' '.strtolower($label);
            }
        }

        if ($over === []) {
            return [];
        }

        $last = array_pop($over);

        return ['A lot starts by itself '.$where.': '.($over === [] ? $last : implode(', ', $over).' and '.$last).'. '.$advice];
    }

    /**
     * @return list<string>|null
     */
    private function loginItems(?string $text): ?array
    {
        if ($text === null || preg_match('/execution error|got an error|\(-\d{3,5}\)\s*$/i', $text) === 1) {
            return null;
        }

        return array_values(array_filter(array_map('trim', preg_split('/[,\n]/', $text) ?: []), fn (string $name): bool => $name !== ''));
    }

    /**
     * @return array{items: list<Entry>, capped: bool}|null
     */
    private function background(?string $text): ?array
    {
        $records = [];
        $lines = 0;

        foreach (preg_split('/\R/', (string) $text) ?: [] as $line) {
            if (preg_match('/^\s*(Developer Name|Name|Type|Disposition):\s*(.*?)\s*$/', $line, $match) !== 1) {
                continue;
            }

            $lines++;

            if ($match[1] === 'Name') {
                $records[] = ['name' => $match[2], 'developer' => '', 'type' => '', 'disposition' => ''];

                continue;
            }

            if ($records !== []) {
                $records[array_key_last($records)][match ($match[1]) {
                    'Developer Name' => 'developer',
                    'Type' => 'type',
                    default => 'disposition',
                }] = $match[2];
            }
        }

        $items = [];

        foreach ($records as $record) {
            $type = strtolower($record['type']);

            if ($type !== '' && ! str_starts_with($type, 'developer') && preg_match('/\bdisabled\b/i', $record['disposition']) !== 1) {
                $items[$record['name'].'|'.$record['developer'].'|'.$type] = ['raw' => $record['name'] === '(null)' ? '' : $record['name'], 'developer' => $record['developer']];
            }
        }

        $items = array_values($items);

        return $items === [] ? null : ['items' => $items, 'capped' => $lines >= self::BACKGROUND_LINE_CAP];
    }

    /**
     * @return array{total: int|null, detail: string|null, names: list<string>}
     */
    private function launch(?string $listing, ?string $counts): array
    {
        $agents = [];
        $daemons = [];
        $daemon = false;
        $listed = false;

        foreach (preg_split('/\R/', (string) $listing) ?: [] as $line) {
            $line = trim($line);
            $listed = $listed || ($line !== '' && ! str_starts_with($line, 'ls:'));

            if (str_starts_with($line, '/') && str_ends_with($line, ':')) {
                $daemon = str_contains($line, 'LaunchDaemons');
            } elseif (preg_match('/\.plist$/i', $line) === 1 && ! str_starts_with($line, '/')) {
                if ($daemon) {
                    $daemons[] = $line;
                } else {
                    $agents[] = $line;
                }
            }
        }

        if ($listed) {
            return [
                'total' => count($agents) + count($daemons),
                'detail' => number_format(count($agents)).' '.Str::plural('agent', count($agents)).', '.number_format(count($daemons)).' '.Str::plural('daemon', count($daemons)),
                'names' => [...$agents, ...$daemons],
            ];
        }

        $total = $counts !== null && preg_match('/^LaunchAgents\/Daemons:\s*\n\s*(\d{1,5})\s*$/m', $counts, $match) === 1 ? (int) $match[1] : null;

        return ['total' => $total, 'detail' => null, 'names' => []];
    }

    /**
     * @return list<string>
     */
    private function programs(string $text): array
    {
        $lines = preg_split('/\R/', $text) ?: [];
        $names = [];
        $starts = null;

        foreach ($lines as $line) {
            if ($starts === null) {
                $starts = $this->columnStarts($line);

                continue;
            }

            if (trim($line) === '') {
                break;
            }

            $name = trim(mb_substr($line, $starts[0], $starts[1] - $starts[0]));

            if ($name !== '' && trim(mb_substr($line, $starts[1])) !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @return list<int>|null
     */
    private function columnStarts(string $line): ?array
    {
        if (preg_match('/^-+(?:\s+-+)+\s*$/', $line) !== 1) {
            return null;
        }

        preg_match_all('/-+/', $line, $found, PREG_OFFSET_CAPTURE);

        return array_map(fn (array $dashes): int => $dashes[1], $found[0]);
    }

    /**
     * @param  list<string>  $names
     * @return list<Entry>
     */
    private function entries(array $names): array
    {
        return array_map(fn (string $name): array => ['raw' => $name, 'developer' => ''], $names);
    }

    /**
     * @param  list<Entry>  $entries
     * @return array{title: string, items: list<Row>}|null
     */
    private function list(array $entries): ?array
    {
        $ranked = [];

        foreach ($entries as $entry) {
            $described = $this->describe($entry);

            if ($described !== null) {
                $ranked[mb_strtolower($described['name'])] ??= $described;
            }
        }

        $ranked = array_values($ranked);
        usort($ranked, fn (array $a, array $b): int => $a['rank'] <=> $b['rank']);

        if ($ranked === []) {
            return null;
        }

        return [
            'title' => 'Worth a look',
            'items' => array_map(
                fn (array $item): array => ['label' => $item['name'], 'value' => $item['value'], 'tone' => 'neutral'],
                array_slice($ranked, 0, self::LIST_LIMIT),
            ),
        ];
    }

    /**
     * @param  Entry  $entry
     * @return Ranked|null
     */
    private function describe(array $entry): ?array
    {
        $base = $this->base($entry['raw']);
        $name = $this->shorten($base);

        if ($name === null || preg_match('/^apple\b/i', $entry['developer']) === 1) {
            return null;
        }

        $key = mb_strtolower($base);

        foreach (self::KNOWN as [$pattern, $known, $description]) {
            if (preg_match($pattern, $key) === 1) {
                return ['name' => $known, 'value' => $description, 'rank' => self::RANK_KNOWN];
            }
        }

        return match (true) {
            preg_match(self::SYSTEM, $key) === 1 => null,
            str_starts_with($key, 'com.apple.') => ['name' => $name, 'value' => self::APPLE_STYLE, 'rank' => self::RANK_APPLE_STYLE],
            $this->looksRandom($name) => ['name' => $name, 'value' => self::RANDOM, 'rank' => self::RANK_RANDOM],
            default => ['name' => $name, 'value' => self::UNKNOWN, 'rank' => self::RANK_UNKNOWN],
        };
    }

    private function base(string $raw): string
    {
        $clean = (string) preg_replace('/\p{C}+/u', '', $raw);
        $parts = preg_split('#[\\\\/]#', $clean) ?: [];
        $file = trim((string) end($parts));

        return trim((string) preg_replace('/\.(?:plist|exe|lnk|app)$/i', '', $file));
    }

    private function shorten(string $base): ?string
    {
        $label = preg_match('/^[A-Za-z][\w-]*(?:\.[\w@-]+){2,}$/', $base) === 1 ? (string) substr($base, (int) strrpos($base, '.') + 1) : $base;
        $name = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^\p{L}\p{N} ._+\-]/u', '', $label)));
        $name = trim(mb_substr($name, 0, self::NAME_LIMIT));

        return $name === '' ? null : $name;
    }

    private function looksRandom(string $name): bool
    {
        return preg_match('/^[0-9a-f-]{12,}$/i', $name) === 1
            || preg_match('/^[b-df-hj-np-tv-xz0-9]{8,}$/i', $name) === 1;
    }
}
