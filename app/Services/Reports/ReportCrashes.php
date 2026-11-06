<?php

declare(strict_types=1);

namespace App\Services\Reports;

/**
 * @phpstan-import-type Card from ReportDetails
 *
 * @phpstan-type Crash array{name: string, count: int}
 */
final readonly class ReportCrashes
{
    public const ROW_LIMIT = 5;

    public const REPEAT_AT = 5;

    private const MAX_COUNT = 99999;

    private const MAX_DAYS = 365;

    /**
     * @return Card|null
     */
    public function card(ReportSections $sections): ?array
    {
        $text = $sections->text('Recent app crashes');
        $lines = $text === null ? [] : (preg_split('/\r\n|\r|\n/', $text) ?: []);
        $scan = strtolower((string) ReportDates::keyValue($lines, 'scan'));
        $days = LocaleNumber::count(ReportDates::keyValue($lines, 'window-days'));
        $partial = str_starts_with($scan, 'partial');
        $apps = $this->apps($lines);

        if ($days === null || $days < 1 || $days > self::MAX_DAYS || ($scan !== 'complete' && ! $partial) || ($partial && $apps === [])) {
            return null;
        }

        $total = max(array_sum(array_column($apps, 'count')), min(LocaleNumber::count(ReportDates::keyValue($lines, 'total')) ?? 0, self::MAX_COUNT));

        return [
            'id' => 'crashing-apps',
            'title' => 'Apps that crashed',
            'summary' => $total === 0 ? "No app crashes in the last {$days} days" : $this->crashes($total)." in the last {$days} days",
            'rows' => array_map(fn (array $app): array => [
                'label' => $app['name'],
                'value' => $this->crashes($app['count']),
                'tone' => $app['count'] >= self::REPEAT_AT ? 'bad' : 'neutral',
            ], array_slice($apps, 0, self::ROW_LIMIT)),
            'notes' => array_values(array_filter([
                count($apps) > self::ROW_LIMIT ? 'Only the five apps that crashed most are listed.' : null,
                $partial ? 'The scan stopped at its time limit, so there may be more crashes than counted.' : null,
                $total > 0 ? 'Names only, from the crash logs. An app that keeps crashing is the first one to update, repair or reinstall.' : null,
                'Only you see this: it is not on the pages you share with clients.',
            ])),
            'list' => null,
        ];
    }

    /**
     * @param  list<string>  $lines
     * @return list<Crash>
     */
    private function apps(array $lines): array
    {
        $apps = [];

        foreach ($lines as $line) {
            $parts = explode('|', trim($line));
            $name = count($parts) === 3 && $parts[0] === 'app' ? ReportAppName::clean($parts[1]) : null;
            $count = $name === null ? null : LocaleNumber::count($parts[2]);

            if ($name === null || $count === null || $count < 1 || $count > self::MAX_COUNT) {
                continue;
            }

            $key = mb_strtolower($name);
            $apps[$key] = ['name' => $apps[$key]['name'] ?? $name, 'count' => ($apps[$key]['count'] ?? 0) + $count];
        }

        $apps = array_values($apps);
        usort($apps, fn (array $a, array $b): int => [$b['count'], mb_strtolower($a['name'])] <=> [$a['count'], mb_strtolower($b['name'])]);

        return $apps;
    }

    private function crashes(int $count): string
    {
        return number_format($count).($count === 1 ? ' crash' : ' crashes');
    }
}
