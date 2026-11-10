<?php

declare(strict_types=1);

namespace App\Actions\Machines;

use App\Models\Machine;
use App\Models\Report;
use App\Services\Flags\FlagAdvice;
use App\Services\Flags\FlagAdvisor;
use App\Services\Flags\FlagChecks;
use App\Services\Reports\LocaleNumber;
use App\Services\Reports\ReportFacts;
use App\Services\Reports\ReportSections;

/**
 * @phpstan-type Changes array{previous_at: string|null, new: list<string>, fixed: list<string>, open: int, newly_checked: list<string>, disk: array{from: string, to: string}|null, memory: array{from: string, to: string}|null, same_rights: bool}
 */
final readonly class CompareLatestReports
{
    public function __construct(private FlagAdvisor $advisor, private ReportFacts $facts, private FlagChecks $checks) {}

    /**
     * @return Changes|null
     */
    public function handle(Machine $machine): ?array
    {
        $reports = $machine->reports()->latest()->latest('id')->limit(2)->get()->all();

        if (count($reports) < 2) {
            return null;
        }

        return $this->between($reports[1], $reports[0]);
    }

    /**
     * @return Changes
     */
    public function between(Report $previous, Report $current): array
    {
        $diff = $this->diff($previous, $current);
        $headline = fn (FlagAdvice $advice): string => $advice->headline;

        return [
            'previous_at' => $previous->created_at?->toJSON(),
            'new' => array_map($headline, $diff['new']),
            'fixed' => array_map($headline, $diff['fixed']),
            'open' => count($diff['open']),
            'newly_checked' => array_map($headline, $diff['newly_checked']),
            'disk' => $this->change($current, $previous, 'free_disk', '/\A(\d+)%\z/'),
            'memory' => $this->change($current, $previous, 'memory', '/\A('.LocaleNumber::PATTERN.')\s*GB\z/i'),
            'same_rights' => $this->sameRights($previous, $current),
        ];
    }

    /**
     * Admin-only flags vanish from a scan run without admin rights and would read as fixed, so only known and equal rights can be trusted.
     */
    public function sameRights(Report $previous, Report $current): bool
    {
        return $current->ran_as_admin !== null && $current->ran_as_admin === $previous->ran_as_admin;
    }

    /**
     * A flag only one of the scans could have raised is neither new nor fixed: the newer scan's are newly checked, the older scan's are left out.
     *
     * @return array{new: list<FlagAdvice>, fixed: list<FlagAdvice>, open: list<FlagAdvice>, newly_checked: list<FlagAdvice>}
     */
    public function diff(Report $previous, Report $current): array
    {
        $now = $this->issues($current);
        $before = $this->issues($previous);
        $newer = ReportSections::parse($current->markdown);
        $older = ReportSections::parse($previous->markdown);

        $appeared = array_diff_key($now, $before);
        $gone = array_diff_key($before, $now);
        $newlyChecked = array_filter($appeared, fn (FlagAdvice $advice): bool => $this->checks->onlyRanIn($advice->rule, $newer, $older));
        $noLongerChecked = array_filter($gone, fn (FlagAdvice $advice): bool => $this->checks->onlyRanIn($advice->rule, $older, $newer));

        return [
            'new' => array_values(array_diff_key($appeared, $newlyChecked)),
            'fixed' => array_values(array_diff_key($gone, $noLongerChecked)),
            'open' => array_values(array_intersect_key($now, $before)),
            'newly_checked' => array_values($newlyChecked),
        ];
    }

    /**
     * @return array<string, FlagAdvice>
     */
    private function issues(Report $report): array
    {
        $issues = [];

        foreach ($this->advisor->adviseAll((array) $report->flags) as $advice) {
            $issues[$advice->key] ??= $advice;
        }

        return $issues;
    }

    /**
     * @param  'free_disk'|'memory'  $fact
     * @return array{from: string, to: string}|null
     */
    private function change(Report $current, Report $previous, string $fact, string $pattern): ?array
    {
        $to = $this->facts->from($current->markdown)[$fact];
        $from = $this->facts->from($previous->markdown)[$fact];

        if ($to === null || $from === null) {
            return null;
        }

        if (preg_match($pattern, $to, $toMatch) !== 1 || preg_match($pattern, $from, $fromMatch) !== 1) {
            return null;
        }

        return LocaleNumber::float($toMatch[1]) === LocaleNumber::float($fromMatch[1]) ? null : ['from' => $from, 'to' => $to];
    }
}
