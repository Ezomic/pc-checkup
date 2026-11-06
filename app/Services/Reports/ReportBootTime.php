<?php

declare(strict_types=1);

namespace App\Services\Reports;

use Carbon\CarbonImmutable;

/**
 * @phpstan-import-type Card from ReportDetails
 */
final readonly class ReportBootTime
{
    public const GOOD_UNTIL_SECONDS = 60;

    public const SLOW_AFTER_SECONDS = 120;

    private const MAX_SECONDS = 7200;

    private const MAX_SAMPLES = 10;

    private const FIRST_YEAR = 2000;

    private const WINDOWS_SECTION = 'Boot time';

    private const MAC_SECTION = 'Reboot history and boot time';

    private const OWNER_NOTE = 'Only you see this: it is not on the pages you share with clients.';

    public static function duration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds} sec";
        }

        $rest = $seconds % 60;

        return intdiv($seconds, 60).' min'.($rest === 0 ? '' : " {$rest} sec");
    }

    /**
     * @return Card|null
     */
    public function card(ReportSections $sections): ?array
    {
        return $this->windows($sections) ?? $this->mac($sections);
    }

    public function seconds(ReportSections $sections): ?int
    {
        return $this->read($this->lines($sections), 'boot-seconds');
    }

    /**
     * @param  list<string>  $lines
     */
    private function read(array $lines, string $key): ?int
    {
        $seconds = LocaleNumber::count(ReportDates::keyValue($lines, $key));

        return $seconds !== null && $seconds >= 1 && $seconds <= self::MAX_SECONDS ? $seconds : null;
    }

    /**
     * @return list<string>
     */
    private function lines(ReportSections $sections): array
    {
        $text = $sections->text(self::WINDOWS_SECTION);

        return $text === null ? [] : (preg_split('/\r\n|\r|\n/', $text) ?: []);
    }

    /**
     * @return Card|null
     */
    private function windows(ReportSections $sections): ?array
    {
        $lines = $this->lines($sections);
        $typical = $this->read($lines, 'boot-seconds');

        if ($typical === null) {
            return null;
        }

        $latest = $this->read($lines, 'boot-latest-seconds');
        $date = ReportDates::parse(ReportDates::keyValue($lines, 'boot-latest'));
        $when = $date === null ? '' : ' ('.$date->format('Y-m-d').')';
        $samples = min(self::MAX_SAMPLES, max(1, LocaleNumber::count(ReportDates::keyValue($lines, 'boot-samples')) ?? 1));
        $tone = match (true) {
            $typical <= self::GOOD_UNTIL_SECONDS => 'good',
            $typical > self::SLOW_AFTER_SECONDS => 'bad',
            default => 'neutral',
        };

        return [
            'id' => 'boot-time',
            'title' => 'Startup time',
            'rows' => $samples < 2
                ? [['label' => 'Last startup', 'value' => self::duration($typical).$when, 'tone' => $tone]]
                : array_values(array_filter([
                    ['label' => 'Typical startup', 'value' => self::duration($typical), 'tone' => $tone],
                    $latest === null ? null : ['label' => 'Latest startup', 'value' => self::duration($latest).$when, 'tone' => 'neutral'],
                    ['label' => 'Based on', 'value' => "the last {$samples} startups", 'tone' => 'neutral'],
                ])),
            'notes' => array_values(array_filter([
                'Windows records how long each start took. A start right after an update is slower than usual.',
                $tone === 'bad' ? 'Slow starts usually come from many startup programs or an old hard drive.' : null,
                self::OWNER_NOTE,
            ])),
            'list' => null,
        ];
    }

    /**
     * @return Card|null
     */
    private function mac(ReportSections $sections): ?array
    {
        $text = $sections->text(self::MAC_SECTION);

        if ($text === null || preg_match('/kern\.boottime:[^\n]*?\bsec\s*=\s*(\d{1,12})\b/', $text, $match) !== 1) {
            return null;
        }

        $at = CarbonImmutable::createFromTimestampUTC((int) $match[1]);

        if ($at->year < self::FIRST_YEAR || $at->isFuture()) {
            return null;
        }

        return [
            'id' => 'boot-time',
            'title' => 'Startup time',
            'rows' => [['label' => 'Last restarted', 'value' => $at->format('Y-m-d'), 'tone' => 'neutral']],
            'notes' => ['macOS does not record how long a start took, so only the last restart is shown.', self::OWNER_NOTE],
            'list' => null,
        ];
    }
}
