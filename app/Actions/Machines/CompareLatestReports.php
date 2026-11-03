<?php

declare(strict_types=1);

namespace App\Actions\Machines;

use App\Models\Machine;
use App\Models\Report;
use App\Services\Flags\FlagAdvisor;
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

        [$current, $previous] = $reports;
        $now = $this->issues($current);
        $before = $this->issues($previous);

        return [
            'previous_at' => $previous->created_at?->toJSON(),
            'new' => array_values(array_diff_key($now, $before)),
            'fixed' => array_values(array_diff_key($before, $now)),
            'open' => count(array_intersect_key($now, $before)),
            'disk' => $this->change($current, $previous, 'free_disk', '/\A(\d+)%\z/'),
            'memory' => $this->change($current, $previous, 'memory', '/\A(\d+(?:\.\d+)?)\s*GB\z/i'),
            'same_rights' => $current->ran_as_admin !== null && $current->ran_as_admin === $previous->ran_as_admin,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function issues(Report $report): array
    {
        $issues = [];

        foreach ($this->advisor->adviseAll((array) $report->flags) as $advice) {
            $issues[$advice->key] ??= $advice->headline;
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

        return (float) $toMatch[1] === (float) $fromMatch[1] ? null : ['from' => $from, 'to' => $to];
    }
}
