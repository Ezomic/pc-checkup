<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Machine;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * @phpstan-type Point array{at: string, value: float, label: string}
 * @phpstan-type Trend array{id: 'disk'|'battery'|'memory', title: string, points: list<Point>, latest: string, change: string, sentence: string|null, tone: 'bad'|'neutral'}
 * @phpstan-type Reading array{at: CarbonInterface, gb: float|null, percent: float|null, cycles: float|null, memory: float|null}
 * @phpstan-type Sample array{at: CarbonInterface, value: float}
 */
final readonly class ReportTrends
{
    private const REPORTS = 12;

    private const MIN_POINTS = 3;

    private const MIN_DAYS = 14;

    private const MIN_R_SQUARED = 0.6;

    private const FLAT_BELOW = 0.5;

    private const BAD_WITHIN_MONTHS = 3;

    private const MAX_FILL_MONTHS = 24.0;

    private const SHORT_SPAN_DAYS = 45;

    private const MEMORY_STEADY_WITHIN = 5.0;

    private const MAX_CYCLES = 20000;

    private const MAX_PERCENT = 100;

    private const GIGABYTE = 1024 ** 3;

    private const MAX_GIGABYTES = 1024.0 * 1024.0;

    private const SECONDS_PER_DAY = 86400;

    public function __construct(private ReportDetails $details, private ReportFacts $facts) {}

    /**
     * @return list<Trend>
     */
    public function forMachine(Machine $machine): array
    {
        $readings = [];

        $reports = $machine->reports()
            ->select(['id', 'markdown', 'created_at'])
            ->latest()
            ->latest('id')
            ->limit(self::REPORTS)
            ->cursor();

        foreach ($reports as $report) {
            if ($report->created_at !== null) {
                $readings[] = $this->read($report->created_at, $report->markdown);
            }
        }

        return $this->build(array_reverse($readings));
    }

    /**
     * @param  iterable<array{at: CarbonInterface, markdown: string}>  $scans  Oldest first.
     * @return list<Trend>
     */
    public function fromScans(iterable $scans): array
    {
        $readings = [];

        foreach ($scans as $scan) {
            $readings[] = $this->read($scan['at'], $scan['markdown']);
        }

        return $this->build($readings);
    }

    /**
     * @return Reading
     */
    private function read(CarbonInterface $at, string $markdown): array
    {
        $sections = ReportSections::parse($markdown);
        $capacity = $this->details->capacity($sections);
        $usable = $capacity !== null && $capacity['size'] > 0.0 && $capacity['size'] <= self::MAX_GIGABYTES * self::GIGABYTE && $capacity['free'] <= $capacity['size'];

        return [
            'at' => $at,
            'gb' => $capacity !== null && $usable ? round($capacity['free'] / self::GIGABYTE, 1) : null,
            'percent' => $this->number($this->facts->from($markdown)['free_disk'], '/^(\d{1,3})%$/', self::MAX_PERCENT),
            'cycles' => $this->number($sections->header('battery'), '/^(\d{1,5}) cycles\b/i', self::MAX_CYCLES),
            'memory' => $this->number($sections->text('Memory pressure'), '/free percentage:\s*(\d{1,3})%/i', self::MAX_PERCENT),
        ];
    }

    private function number(?string $text, string $pattern, int $max): ?float
    {
        if ($text === null || preg_match($pattern, $text, $match) !== 1) {
            return null;
        }

        return (float) $match[1] <= $max ? (float) $match[1] : null;
    }

    /**
     * @param  list<Reading>  $readings
     * @return list<Trend>
     */
    private function build(array $readings): array
    {
        return array_values(array_filter([
            $this->disk($readings),
            $this->battery($readings),
            $this->memory($readings),
        ]));
    }

    /**
     * @param  list<Reading>  $readings
     * @param  'gb'|'percent'|'cycles'|'memory'  $key
     * @return list<Sample>
     */
    private function samples(array $readings, string $key): array
    {
        $samples = [];

        foreach ($readings as $reading) {
            if ($reading[$key] !== null) {
                $samples[] = ['at' => $reading['at'], 'value' => $reading[$key]];
            }
        }

        return $samples;
    }

    /**
     * @param  list<Sample>  $samples
     */
    private function qualifies(array $samples): bool
    {
        return count($samples) >= self::MIN_POINTS
            && $this->days($samples[0]['at'], $samples[count($samples) - 1]['at']) >= self::MIN_DAYS;
    }

    private function days(CarbonInterface $from, CarbonInterface $to): float
    {
        return ($to->getTimestamp() - $from->getTimestamp()) / self::SECONDS_PER_DAY;
    }

    /**
     * @param  list<Reading>  $readings
     * @return Trend|null
     */
    private function disk(array $readings): ?array
    {
        foreach (['gb', 'percent'] as $key) {
            $samples = $this->samples($readings, $key);

            if ($this->qualifies($samples)) {
                [$sentence, $tone] = $this->diskSentence($samples);

                return $this->trend('disk', 'Free disk space', $key, $samples, $sentence, $tone);
            }
        }

        return null;
    }

    /**
     * @param  list<Sample>  $samples
     * @return array{string|null, 'bad'|'neutral'}
     */
    private function diskSentence(array $samples): array
    {
        $first = $samples[0]['at'];
        $latest = $samples[count($samples) - 1];
        $fit = TrendMath::fit(array_map(fn (array $sample): array => ['x' => $this->days($first, $sample['at']), 'y' => $sample['value']], $samples));

        if ($fit === null || $fit['r2'] < self::MIN_R_SQUARED || abs($fit['slope'] * $this->days($first, $latest['at'])) < self::FLAT_BELOW) {
            return [null, 'neutral'];
        }

        $months = TrendMath::monthsUntilFull($latest['value'], $fit['slope']);

        if ($months === null) {
            return ['Free space is going up.', 'neutral'];
        }

        $rounded = (int) round($months);
        $when = match (true) {
            $months > self::MAX_FILL_MONTHS => 'more than 2 years',
            $rounded < 1 => 'less than a month',
            default => "about {$rounded} ".Str::plural('month', $rounded),
        };

        return ["At this pace the disk is full in {$when}.", $rounded <= self::BAD_WITHIN_MONTHS ? 'bad' : 'neutral'];
    }

    /**
     * @param  list<Reading>  $readings
     * @return Trend|null
     */
    private function battery(array $readings): ?array
    {
        $samples = $this->samples($readings, 'cycles');
        $start = 0;

        foreach ($samples as $index => $sample) {
            if ($index > 0 && $sample['value'] < $samples[$index - 1]['value']) {
                $start = $index;
            }
        }

        $samples = array_slice($samples, $start);

        if (! $this->qualifies($samples)) {
            return null;
        }

        return $this->trend('battery', 'Battery charge cycles', 'cycles', $samples, $this->batterySentence($samples), 'neutral');
    }

    /**
     * @param  list<Sample>  $samples
     */
    private function batterySentence(array $samples): string
    {
        $gained = (int) round($samples[count($samples) - 1]['value'] - $samples[0]['value']);
        $days = $this->days($samples[0]['at'], $samples[count($samples) - 1]['at']);
        $months = max(1, (int) round($days / TrendMath::DAYS_PER_MONTH));
        $short = $days < self::SHORT_SPAN_DAYS;
        $duration = $short ? (int) round($days).' days' : "{$months} ".Str::plural('month', $months);

        if ($gained === 0) {
            return "No new charge cycles in {$duration}.";
        }

        $rate = (int) round($short ? $gained / ($days / TrendMath::DAYS_PER_MONTH) : $gained / $months);
        $perMonth = $rate < 1 ? 'less than 1 a month' : "about {$rate} a month";

        return "The battery gained {$gained} ".Str::plural('cycle', $gained)." in {$duration}, {$perMonth}.";
    }

    /**
     * @param  list<Reading>  $readings
     * @return Trend|null
     */
    private function memory(array $readings): ?array
    {
        $samples = $this->samples($readings, 'memory');

        if (! $this->qualifies($samples)) {
            return null;
        }

        $delta = $samples[count($samples) - 1]['value'] - $samples[0]['value'];
        $sentence = match (true) {
            abs($delta) < self::MEMORY_STEADY_WITHIN => 'Free memory has stayed about the same.',
            $delta > 0 => 'Free memory is going up.',
            default => 'Free memory is going down.',
        };

        return $this->trend('memory', 'Free memory', 'memory', $samples, $sentence, 'neutral');
    }

    /**
     * @param  'disk'|'battery'|'memory'  $id
     * @param  'gb'|'percent'|'cycles'|'memory'  $key
     * @param  list<Sample>  $samples
     * @param  'bad'|'neutral'  $tone
     * @return Trend
     */
    private function trend(string $id, string $title, string $key, array $samples, ?string $sentence, string $tone): array
    {
        $latest = $samples[count($samples) - 1]['value'];

        return [
            'id' => $id,
            'title' => $title,
            'points' => array_map(fn (array $sample): array => [
                'at' => $sample['at']->toIso8601ZuluString(),
                'value' => $sample['value'],
                'label' => $this->label($key, $sample['value']),
            ], $samples),
            'latest' => $this->label($key, $latest),
            'change' => $this->change($key, $latest - $samples[0]['value']),
            'sentence' => $sentence,
            'tone' => $tone,
        ];
    }

    /**
     * @param  'gb'|'percent'|'cycles'|'memory'  $key
     */
    private function label(string $key, float $value): string
    {
        return match ($key) {
            'gb' => $this->gigabytes($value),
            'cycles' => (int) $value.' '.Str::plural('cycle', (int) $value),
            default => (int) round($value).'%',
        };
    }

    /**
     * @param  'gb'|'percent'|'cycles'|'memory'  $key
     */
    private function change(string $key, float $delta): string
    {
        $size = abs($delta);

        if ($size < 0.05) {
            return 'No change';
        }

        $text = match ($key) {
            'gb' => $this->gigabytes($size),
            'cycles' => (int) round($size).' '.Str::plural('cycle', (int) round($size)),
            default => (int) round($size).' '.Str::plural('point', (int) round($size)),
        };

        return ($delta > 0 ? '+' : '-').$text;
    }

    private function gigabytes(float $gb): string
    {
        return match (true) {
            $gb >= 1024 => round($gb / 1024, 1).' TB',
            $gb >= 10 => round($gb).' GB',
            default => round($gb, 1).' GB',
        };
    }
}
