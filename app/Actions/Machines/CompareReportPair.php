<?php

declare(strict_types=1);

namespace App\Actions\Machines;

use App\Models\Report;
use App\Services\Flags\FlagAdvisor;
use App\Services\Reports\LocaleNumber;
use App\Services\Reports\ReportFacts;
use App\Services\Reports\ReportSections;
use App\Services\Reports\ReportVerdict;

final readonly class CompareReportPair
{
    public function __construct(private FlagAdvisor $advisor, private ReportFacts $facts, private ReportVerdict $verdict) {}

    /**
     * @return array{before: array{id: int, created_at: string|null}, after: array{id: int, created_at: string|null}, new: list<string>, fixed: list<string>, open: int, disk: array{from: string, to: string}|null, memory: array{from: string, to: string}|null, verdict: array{from: string, to: string}|null, same_rights: bool}
     */
    public function handle(Report $one, Report $two): array
    {
        [$before, $after] = $this->ordered($one, $two);
        $now = $this->issues($after);
        $then = $this->issues($before);

        return [
            'before' => ['id' => $before->id, 'created_at' => $before->created_at?->toJSON()],
            'after' => ['id' => $after->id, 'created_at' => $after->created_at?->toJSON()],
            'new' => array_values(array_diff_key($now, $then)),
            'fixed' => array_values(array_diff_key($then, $now)),
            'open' => count(array_intersect_key($now, $then)),
            'disk' => $this->change($before, $after, 'free_disk', '/\A(\d+)%\z/'),
            'memory' => $this->change($before, $after, 'memory', '/\A('.LocaleNumber::PATTERN.')\s*GB\z/i'),
            'verdict' => $this->verdictChange($before, $after),
            'same_rights' => $after->ran_as_admin !== null && $after->ran_as_admin === $before->ran_as_admin,
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
     * @return array<string, string>
     */
    private function issues(Report $report): array
    {
        $issues = [];

        foreach ($this->advisor->adviseAll($report->flags) as $advice) {
            $issues[$advice->key] ??= $advice->headline;
        }

        return $issues;
    }

    /**
     * @param  'free_disk'|'memory'  $fact
     * @return array{from: string, to: string}|null
     */
    private function change(Report $before, Report $after, string $fact, string $pattern): ?array
    {
        $from = $this->facts->from($before->markdown)[$fact];
        $to = $this->facts->from($after->markdown)[$fact];

        if ($from === null || $to === null) {
            return null;
        }

        if (preg_match($pattern, $from, $fromMatch) !== 1 || preg_match($pattern, $to, $toMatch) !== 1) {
            return null;
        }

        return LocaleNumber::float($fromMatch[1]) === LocaleNumber::float($toMatch[1]) ? null : ['from' => $from, 'to' => $to];
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
