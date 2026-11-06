<?php

declare(strict_types=1);

namespace App\Actions\Machines;

use App\Models\Machine;
use App\Models\Report;
use App\Services\Flags\FlagAdvice;
use App\Services\Flags\FlagAdvisor;
use App\Services\Reports\LocaleNumber;
use App\Services\Reports\ReportFacts;

final readonly class CompareLatestReports
{
    public function __construct(private FlagAdvisor $advisor, private ReportFacts $facts) {}

    /**
     * @return array{previous_at: string|null, new: list<string>, fixed: list<string>, open: int, disk: array{from: string, to: string}|null, memory: array{from: string, to: string}|null, same_rights: bool}|null
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
     * @return array{previous_at: string|null, new: list<string>, fixed: list<string>, open: int, disk: array{from: string, to: string}|null, memory: array{from: string, to: string}|null, same_rights: bool}
     */
    public function between(Report $previous, Report $current): array
    {
        $diff = $this->diff($previous, $current);

        return [
            'previous_at' => $previous->created_at?->toJSON(),
            'new' => array_map(fn (FlagAdvice $advice): string => $advice->headline, $diff['new']),
            'fixed' => array_map(fn (FlagAdvice $advice): string => $advice->headline, $diff['fixed']),
            'open' => count($diff['open']),
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
     * @return array{new: list<FlagAdvice>, fixed: list<FlagAdvice>, open: list<FlagAdvice>}
     */
    public function diff(Report $previous, Report $current): array
    {
        $now = $this->issues($current);
        $before = $this->issues($previous);

        return [
            'new' => array_values(array_diff_key($now, $before)),
            'fixed' => array_values(array_diff_key($before, $now)),
            'open' => array_values(array_intersect_key($now, $before)),
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
