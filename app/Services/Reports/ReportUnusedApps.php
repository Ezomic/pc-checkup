<?php

declare(strict_types=1);

namespace App\Services\Reports;

use Carbon\CarbonImmutable;

/**
 * @phpstan-import-type Card from ReportDetails
 * @phpstan-import-type PlainBar from ReportDetails
 *
 * @phpstan-type App array{name: string, bytes: float, last: string|null}
 */
final readonly class ReportUnusedApps
{
    public const UNUSED_AFTER_MONTHS = 12;

    public const MINIMUM_MB = 100;

    public const ROW_LIMIT = 15;

    private const NAME_LIMIT = 60;

    private const MAX_COUNT = 999999;

    private const UNKNOWN = 'unknown';

    private const ADVICE = 'Uninstall the ones you do not recognise or need, the normal way: drag the app to the Bin on a Mac, or use Settings, Apps on Windows. Leave anything you are unsure about.';

    /**
     * @return Card|null
     */
    public function card(ReportSections $sections): ?array
    {
        $text = $sections->text('Apps you may not need');
        $lines = $text === null ? [] : (preg_split('/\r\n|\r|\n/', $text) ?: []);
        $scanned = ReportDates::parse(ReportDates::keyValue($lines, 'scanned'));

        if ($scanned === null) {
            return null;
        }

        $apps = $this->apps($lines, $scanned->subMonthsNoOverflow(self::UNUSED_AFTER_MONTHS));

        if ($apps === []) {
            return null;
        }

        $known = array_values(array_filter($apps, fn (array $app): bool => $app['last'] !== null));
        $unknown = array_values(array_filter($apps, fn (array $app): bool => $app['last'] === null));
        $biggest = $apps[0]['bytes'];
        $bars = array_values(array_filter([
            $known === [] ? null : ['title' => 'Not opened for a year or more', 'items' => $this->bars($known, $biggest)],
            $unknown === [] ? null : ['title' => 'Installed over a year ago, never opened or last use unknown', 'items' => $this->bars($unknown, $biggest)],
        ]));
        $more = $this->more($lines);

        return [
            'id' => 'unused-apps',
            'title' => 'Apps you may not need',
            'summary' => $this->summary($known, $unknown, $more > 0 || $this->partial($lines)),
            'rows' => [],
            'bars' => $bars,
            'notes' => $this->notes($more, $this->partial($lines), $unknown !== []),
            'list' => null,
        ];
    }

    /**
     * @param  list<string>  $lines
     * @return list<App>
     */
    private function apps(array $lines, CarbonImmutable $cutoff): array
    {
        $apps = [];

        foreach ($lines as $line) {
            $parts = explode('|', trim($line));
            $name = count($parts) === 4 && $parts[0] === 'app' ? $this->clean($parts[1]) : null;
            $bytes = $name === null ? null : ByteSize::parse($parts[2]);
            $last = $this->last($parts[3] ?? '', $cutoff);

            if ($name === null || $bytes === null || $bytes < self::MINIMUM_MB * 1024 ** 2 || $last === false) {
                continue;
            }

            $key = mb_strtolower($name);

            if (! isset($apps[$key]) || $apps[$key]['bytes'] < $bytes) {
                $apps[$key] = ['name' => $name, 'bytes' => $bytes, 'last' => $last];
            }
        }

        $apps = array_values($apps);
        usort($apps, fn (array $a, array $b): int => [$b['bytes'], $a['name']] <=> [$a['bytes'], $b['name']]);

        return array_slice($apps, 0, self::ROW_LIMIT);
    }

    private function last(string $value, CarbonImmutable $cutoff): string|false|null
    {
        if (strcasecmp(trim($value), self::UNKNOWN) === 0) {
            return null;
        }

        $date = ReportDates::parse($value);

        return $date !== null && $date->lessThanOrEqualTo($cutoff) ? $date->format('Y-m-d') : false;
    }

    private function clean(string $name): ?string
    {
        $spaced = (string) preg_replace('/[\t\n\r\f\x0B\p{Zs}\p{Zl}\p{Zp}]+/u', ' ', mb_scrub($name));
        $stripped = (string) preg_replace('~[\p{Cc}/\\\\|]|(?![\x{200C}\x{200D}])\p{Cf}~u', '', $spaced);
        $clean = trim(mb_substr(trim((string) preg_replace('/ {2,}/', ' ', $stripped)), 0, self::NAME_LIMIT));

        return in_array($clean, ['', '.', '..'], true) ? null : $clean;
    }

    /**
     * @param  list<App>  $apps
     * @return list<PlainBar>
     */
    private function bars(array $apps, float $biggest): array
    {
        return array_map(fn (array $app): array => [
            'label' => $app['name'],
            'value' => ByteSize::format($app['bytes']),
            'detail' => $app['last'] === null ? 'Last use unknown' : "Last opened {$app['last']}",
            'share' => max((int) round($app['bytes'] / $biggest * 100), 1),
        ], $apps);
    }

    /**
     * @param  list<App>  $known
     * @param  list<App>  $unknown
     */
    private function summary(array $known, array $unknown, bool $atLeast): string
    {
        $lead = $atLeast ? 'At least about ' : 'About ';

        if ($known !== []) {
            return $lead.ByteSize::format(array_sum(array_column($known, 'bytes'))).' in apps not opened for a year';
        }

        return $lead.ByteSize::format(array_sum(array_column($unknown, 'bytes'))).' in apps installed over a year ago with no record of use';
    }

    /**
     * @return list<string>
     */
    private function notes(int $more, bool $partial, bool $hasUnknown): array
    {
        return [
            self::ADVICE,
            ...($more > 0 ? [number_format($more).' more '.($more === 1 ? 'app is' : 'apps are').' not listed: only the biggest '.self::ROW_LIMIT.' are shown.'] : []),
            ...($partial ? ['The scan stopped at its time limit, so there may be more apps than these.'] : []),
            ...($hasUnknown ? ['Without a record of use the scan cannot be sure an app is unused. Check before you remove one.'] : []),
            'Apps of '.self::MINIMUM_MB.' MB or more are listed. Apple and Microsoft apps and system components are left out.',
            'Only you see these app names: they are not on the pages you share with clients.',
        ];
    }

    /**
     * @param  list<string>  $lines
     */
    private function more(array $lines): int
    {
        foreach ($lines as $line) {
            $parts = explode('|', trim($line));

            if (count($parts) === 2 && $parts[0] === 'more') {
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
        $scan = ReportDates::keyValue($lines, 'scan');

        return $scan === null || strtolower(strtok($scan, ' ') ?: '') !== 'complete';
    }
}
