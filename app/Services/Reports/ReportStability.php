<?php

declare(strict_types=1);

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Str;

/**
 * @phpstan-import-type Row from ReportDetails
 */
final readonly class ReportStability
{
    private const PANIC_LIMIT = 10;

    private const EVENT_LIMIT = 15;

    private const NORMAL_SHUTDOWN = 5;

    private const LONGEST_UPTIME_DAYS = 3650;

    private const MINUTE = 60;

    private const HOUR = 3600;

    private const DAY = 86400;

    /**
     * @param  list<string>  $flags
     * @return list<Row>
     */
    public function rows(ReportSections $sections, array $flags): array
    {
        $panics = $this->panics($sections->text('Kernel panic reports'));
        $boot = $this->boot($sections->text('Reboot history and boot time'), $sections->header('generated'));
        $events = $this->events($sections->text('Crashes and unexpected shutdowns (last 30 days)'), $flags);

        return array_values(array_filter([
            $panics['count'],
            $panics['latest'],
            $this->shutdown($sections->text('Recent shutdown causes')),
            $boot['restart'],
            $boot['uptime'],
            $this->loginItems($sections->text('Login items')),
            $this->startupItems($sections->text('Performance: login item and startup counts'), $sections->text('Startup items: LaunchAgents and LaunchDaemons')),
            $events['blue'],
            $events['unexpected'],
            $this->windowsUptime($flags),
            $this->startupPrograms($sections->text('Startup programs')),
        ]));
    }

    /**
     * @return array{count: Row|null, latest: Row|null}
     */
    private function panics(?string $text): array
    {
        $none = ['count' => null, 'latest' => null];

        if ($text === null || $text === '') {
            return $none;
        }

        preg_match_all('/([^\s\/]+\.panic)[ \t]*$/m', $text, $found);
        $names = array_values(array_unique($found[1]));
        $listed = array_filter(explode("\n", $text), fn (string $line): bool => trim($line) !== '' && ! str_starts_with(trim($line), 'ls:')) !== [];

        if ($names === []) {
            return ['count' => $listed ? $this->row('Kernel panics', 'None found', 'good') : null, 'latest' => null];
        }

        $count = count($names);
        $latest = $this->latestPanic($names);

        return [
            'count' => $this->row('Kernel panics', $count >= self::PANIC_LIMIT ? self::PANIC_LIMIT.' or more' : (string) $count, 'bad'),
            'latest' => $latest === null ? null : $this->row('Latest kernel panic', $latest, 'neutral'),
        ];
    }

    /**
     * @param  list<string>  $names
     */
    private function latestPanic(array $names): ?string
    {
        $dates = [];

        foreach ($names as $name) {
            if (preg_match('/(\d{4})-(\d{2})-(\d{2})-\d{6}/', $name, $match) === 1 && checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
                $dates[] = "{$match[1]}-{$match[2]}-{$match[3]}";
            }
        }

        if ($dates === []) {
            return null;
        }

        rsort($dates);

        return CarbonImmutable::parse($dates[0])->format('j F Y');
    }

    /**
     * @return Row|null
     */
    private function shutdown(?string $text): ?array
    {
        if ($text === null || preg_match_all('/Previous shutdown cause:\s*(-?\d{1,4})(?!\d)/', $text, $found) === 0) {
            return null;
        }

        $codes = $found[1];
        $code = (int) end($codes);

        return match (true) {
            $code === self::NORMAL_SHUTDOWN => $this->row('Most recent shutdown', 'Normal shutdown', 'good'),
            in_array($code, self::unexpectedCodes(), true) => $this->row('Most recent shutdown', "Unexpected shutdown (code {$code})", 'bad'),
            default => $this->row('Most recent shutdown', "Code {$code}", 'neutral'),
        };
    }

    /**
     * @return list<int>
     */
    private static function unexpectedCodes(): array
    {
        return [...range(-67, -60), ...range(-72, -69), -74, -75, ...range(-86, -78), -95, -96, -98, ...range(-130, -100)];
    }

    /**
     * @return array{restart: Row|null, uptime: Row|null}
     */
    private function boot(?string $text, ?string $generated): array
    {
        $none = ['restart' => null, 'uptime' => null];

        if ($text === null) {
            return $none;
        }

        return [
            'restart' => $this->restart($text),
            'uptime' => $this->macUptime($text, $generated),
        ];
    }

    /**
     * @return Row|null
     */
    private function restart(string $text): ?array
    {
        if (preg_match('/\}\s+[A-Za-z]{3}\s+([A-Za-z]{3})\s+(\d{1,2})\s+(\d{2}):(\d{2}):\d{2}\s+(\d{4})\s*$/m', $text, $match) !== 1) {
            return null;
        }

        $asked = "{$match[1]} ".(int) $match[2]." {$match[5]} {$match[3]}:{$match[4]}";
        $when = DateTimeImmutable::createFromFormat('!M j Y H:i', $asked);

        return $when === false || $when->format('M j Y H:i') !== $asked
            ? null
            : $this->row('Last restart', $when->format('j F Y, H:i'), 'neutral');
    }

    /**
     * @return Row|null
     */
    private function macUptime(string $text, ?string $generated): ?array
    {
        $scan = $generated === null ? false : strtotime($generated);

        if ($scan === false || preg_match('/kern\.boottime:\s*\{\s*sec\s*=\s*(\d{1,12})\b/', $text, $match) !== 1) {
            return null;
        }

        $seconds = $scan - (int) $match[1];

        if ($seconds < self::MINUTE || $seconds > self::LONGEST_UPTIME_DAYS * self::DAY) {
            return null;
        }

        return $this->row('Uptime at the scan', $this->duration($seconds), 'neutral');
    }

    private function duration(int $seconds): string
    {
        return match (true) {
            $seconds >= self::DAY => $this->plural(intdiv($seconds, self::DAY), 'day'),
            $seconds >= self::HOUR => $this->plural(intdiv($seconds, self::HOUR), 'hour'),
            default => $this->plural(intdiv($seconds, self::MINUTE), 'minute'),
        };
    }

    /**
     * @return Row|null
     */
    private function loginItems(?string $text): ?array
    {
        if ($text === null || preg_match('/execution error|got an error|\(-\d{3,5}\)\s*$/i', $text) === 1) {
            return null;
        }

        $names = array_filter(array_map('trim', preg_split('/[,\n]/', $text) ?: []), fn (string $name): bool => $name !== '');

        return $this->row('Login items', (string) count($names), 'neutral');
    }

    /**
     * @return Row|null
     */
    private function startupItems(?string $counts, ?string $listing): ?array
    {
        if ($counts !== null && preg_match('/^LaunchAgents\/Daemons:\s*\n\s*(\d{1,5})\s*$/m', $counts, $match) === 1) {
            return $this->row('Background startup items', $match[1], 'neutral');
        }

        $lines = array_filter(array_map('trim', explode("\n", (string) $listing)), fn (string $line): bool => $line !== '');

        if ($lines === []) {
            return null;
        }

        $items = array_filter($lines, fn (string $line): bool => ! str_starts_with($line, '/') && ! str_starts_with($line, 'ls:'));

        return $this->row('Background startup items', (string) count($items), 'neutral');
    }

    /**
     * @param  list<string>  $flags
     * @return array{blue: Row|null, unexpected: Row|null}
     */
    private function events(?string $text, array $flags): array
    {
        $rows = 0;
        $blue = 0;
        $power = 0;
        $logged = 0;

        foreach (explode("\n", (string) $text) as $line) {
            if (preg_match('/\s(41|1001|6008)\s+(\S.*?)\s*$/', $line, $match) !== 1) {
                continue;
            }

            $rows++;

            if ($match[1] === '41') {
                $power++;
            } elseif ($match[1] === '6008') {
                $logged++;
            } elseif (preg_match('/SystemErrorReporting|BugCheck/i', $match[2]) === 1) {
                $blue++;
            }
        }

        $checked = $rows > 0 || trim((string) $text) === '(none)';
        $capped = $rows >= self::EVENT_LIMIT;
        $flagged = $this->flagNumber($flags, '/^(\d{1,5}) unexpected shutdown\(s\) in the last 30 days/');

        return [
            'blue' => $checked ? $this->eventRow('Blue screen crashes', $blue, $capped) : null,
            'unexpected' => $flagged !== null
                ? $this->eventRow('Unexpected shutdowns', $flagged, false)
                : ($checked ? $this->eventRow('Unexpected shutdowns', $power > 0 ? $power : $logged, $capped) : null),
        ];
    }

    /**
     * @return Row|null
     */
    private function eventRow(string $label, int $count, bool $capped): ?array
    {
        return match (true) {
            $count > 0 => $this->row($label, $capped ? "{$count} or more" : (string) $count, 'bad'),
            $capped => null,
            default => $this->row($label, 'None in 30 days', 'good'),
        };
    }

    /**
     * @param  list<string>  $flags
     * @return Row|null
     */
    private function windowsUptime(array $flags): ?array
    {
        $days = $this->flagNumber($flags, '/^Up for (\d{1,5}) days since the last full boot/');

        return $days === null ? null : $this->row('Up since the last full boot', $this->plural($days, 'day'), 'neutral');
    }

    /**
     * @return Row|null
     */
    private function startupPrograms(?string $text): ?array
    {
        return $text !== null && preg_match('/^Count:\s*(\d{1,5})\s*$/m', $text, $match) === 1
            ? $this->row('Startup programs', $match[1], 'neutral')
            : null;
    }

    /**
     * @param  list<string>  $flags
     */
    private function flagNumber(array $flags, string $pattern): ?int
    {
        foreach ($flags as $flag) {
            if (preg_match($pattern, $flag, $match) === 1) {
                return (int) $match[1];
            }
        }

        return null;
    }

    private function plural(int $count, string $word): string
    {
        return $count.' '.Str::plural($word, $count);
    }

    /**
     * @param  'good'|'bad'|'neutral'  $tone
     * @return Row
     */
    private function row(string $label, string $value, string $tone): array
    {
        return ['label' => $label, 'value' => $value, 'tone' => $tone];
    }
}
