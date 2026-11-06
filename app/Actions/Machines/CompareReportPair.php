<?php

declare(strict_types=1);

namespace App\Actions\Machines;

use App\Models\Report;
use App\Services\Reports\ReportFacts;
use App\Services\Reports\ReportSections;
use App\Services\Reports\ReportVerdict;

final readonly class CompareReportPair
{
    public function __construct(private CompareLatestReports $compare, private ReportFacts $facts, private ReportVerdict $verdict) {}

    /**
     * @return array{before: array{id: int, created_at: string|null}, after: array{id: int, created_at: string|null}, new: list<string>, fixed: list<string>, open: int, disk: array{from: string, to: string}|null, memory: array{from: string, to: string}|null, verdict: array{from: string, to: string}|null, same_rights: bool}
     */
    public function handle(Report $one, Report $two): array
    {
        [$before, $after] = $this->ordered($one, $two);
        $changes = $this->compare->between($before, $after);

        return [
            'before' => ['id' => $before->id, 'created_at' => $before->created_at?->toJSON()],
            'after' => ['id' => $after->id, 'created_at' => $after->created_at?->toJSON()],
            'new' => $changes['new'],
            'fixed' => $changes['fixed'],
            'open' => $changes['open'],
            'disk' => $changes['disk'],
            'memory' => $changes['memory'],
            'verdict' => $this->verdictChange($before, $after),
            'same_rights' => $changes['same_rights'],
        ];
    }

    /**
     * @return array{Report, Report}
     */
    private function ordered(Report $one, Report $two): array
    {
        $oneFirst = [$one->created_at?->getTimestamp(), $one->id] <= [$two->created_at?->getTimestamp(), $two->id];

        return $oneFirst ? [$one, $two] : [$two, $one];
    }

    /**
     * @return array{from: string, to: string}|null
     */
    private function verdictChange(Report $before, Report $after): ?array
    {
        $from = $this->outcome($before);
        $to = $this->outcome($after);

        return $from === null || $to === null || $from === $to ? null : ['from' => $from, 'to' => $to];
    }

    private function outcome(Report $report): ?string
    {
        return ReportVerdict::outcome($this->verdict->rows(ReportSections::parse($report->markdown), $report->flags, $this->facts->from($report->markdown)['free_disk']));
    }
}
