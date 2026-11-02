<?php

declare(strict_types=1);

namespace App\Actions\Machines;

use App\Enums\BenchmarkMetric;
use App\Enums\ReportKind;
use App\Models\Machine;
use App\Models\Report;

final class BuildReportInsights
{
    private const HISTORY_RUNS = 6;

    /**
     * @return array{afterFix: array<string, mixed>|null, benchmark: array<string, mixed>|null}
     */
    public function handle(Machine $machine): array
    {
        $afterFix = $machine->reports()
            ->with('beforeReport')
            ->where('kind', ReportKind::AfterFix)
            ->latest()
            ->latest('id')
            ->first();

        $runs = array_values($machine->reports()
            ->whereNotNull('benchmark')
            ->latest()
            ->latest('id')
            ->limit(self::HISTORY_RUNS)
            ->get()
            ->filter(fn (Report $report): bool => $this->metrics($report) !== [])
            ->all());

        return [
            'afterFix' => $afterFix === null ? null : $this->afterFix($afterFix),
            'benchmark' => $runs === [] ? null : $this->benchmark($runs, $afterFix),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function afterFix(Report $after): array
    {
        $before = $after->beforeReport;

        return [
            'id' => $after->id,
            'created_at' => $after->created_at,
            'fixes' => $after->fixes ?? [],
            'flags' => $after->flags,
            'before' => $before === null ? null : [
                'id' => $before->id,
                'created_at' => $before->created_at,
                'flags' => $before->flags,
            ],
            'resolved' => $before === null ? [] : array_values(array_diff($before->flags, $after->flags)),
            'open' => $before === null ? [] : array_values(array_intersect($before->flags, $after->flags)),
            'introduced' => $before === null ? [] : array_values(array_diff($after->flags, $before->flags)),
        ];
    }

    /**
     * @param  list<Report>  $runs
     * @return array<string, mixed>
     */
    private function benchmark(array $runs, ?Report $afterFix): array
    {
        $history = [];

        foreach ($runs as $index => $run) {
            $history[] = $this->historyRow($run, $runs, $index);
        }

        return [
            'latest' => [
                'id' => $runs[0]->id,
                'created_at' => $runs[0]->created_at,
                'kind' => $runs[0]->kind->value,
                'metrics' => $this->ratedMetrics($runs[0]),
            ],
            'history' => $history,
            'comparison' => $this->comparison($afterFix),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ratedMetrics(Report $report): array
    {
        $rows = [];

        foreach ($this->metrics($report) as $key => $value) {
            $metric = BenchmarkMetric::from($key);

            $rows[] = [
                'key' => $key,
                'label' => $metric->label(),
                'unit' => $metric->unit(),
                'value' => $value,
                'rating' => $metric->rate($value),
                'healthy' => $metric->healthy(),
                'poor' => $metric->poor(),
                'higher_is_better' => $metric->higherIsBetter(),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<Report>  $runs
     * @return array<string, mixed>
     */
    private function historyRow(Report $run, array $runs, int $index): array
    {
        $values = [];

        foreach ($this->metrics($run) as $key => $value) {
            $metric = BenchmarkMetric::from($key);
            $previous = $this->earlierValue($runs, $index, $key);
            $delta = $previous === null ? null : $value - $previous;

            $values[] = [
                'key' => $key,
                'label' => $metric->label(),
                'unit' => $metric->unit(),
                'value' => $value,
                'delta' => $delta,
                'improved' => $delta === null ? null : $metric->improved($delta),
            ];
        }

        return [
            'id' => $run->id,
            'created_at' => $run->created_at,
            'kind' => $run->kind->value,
            'values' => $values,
        ];
    }

    /**
     * @param  list<Report>  $runs
     */
    private function earlierValue(array $runs, int $index, string $key): int|float|null
    {
        foreach (array_slice($runs, $index + 1) as $older) {
            $metrics = $this->metrics($older);

            if (array_key_exists($key, $metrics)) {
                return $metrics[$key];
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function comparison(?Report $afterFix): ?array
    {
        $before = $afterFix?->beforeReport;

        if ($afterFix === null || $before === null) {
            return null;
        }

        $beforeMetrics = $this->metrics($before);
        $rows = [];

        foreach ($this->metrics($afterFix) as $key => $after) {
            if (! array_key_exists($key, $beforeMetrics)) {
                continue;
            }

            $metric = BenchmarkMetric::from($key);
            $delta = $after - $beforeMetrics[$key];

            $rows[] = [
                'key' => $key,
                'label' => $metric->label(),
                'unit' => $metric->unit(),
                'before' => $beforeMetrics[$key],
                'after' => $after,
                'delta' => $delta,
                'improved' => $metric->improved($delta),
            ];
        }

        return $rows === [] ? null : $rows;
    }

    /**
     * @return array<string, int|float>
     */
    private function metrics(Report $report): array
    {
        $metrics = [];

        foreach (BenchmarkMetric::cases() as $metric) {
            $value = $report->benchmark[$metric->value] ?? null;

            if (is_int($value) || is_float($value)) {
                $metrics[$metric->value] = $value;
            }
        }

        return $metrics;
    }
}
