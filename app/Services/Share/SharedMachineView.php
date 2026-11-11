<?php

declare(strict_types=1);

namespace App\Services\Share;

use App\Actions\Machines\CompareLatestReports;
use App\Enums\MachineStatus;
use App\Models\Machine;
use App\Services\Flags\FlagAdvice;
use App\Services\Flags\FlagAdvisor;
use App\Services\Reports\ReportFacts;
use App\Services\Reports\ReportSections;

/**
 * Builds what a client may see, field by field. Nothing here spreads a model or a
 * report, so a column added to either later stays private until it is listed.
 */
final readonly class SharedMachineView
{
    public function __construct(private FlagAdvisor $advisor, private ReportFacts $facts, private CompareLatestReports $compare) {}

    /**
     * @return array{id: int, name: string|null, os: string|null, device_type: string|null, model: string|null, status: string|null, top_issue: array{severity: string, headline: string, advice: string|null}|null, issue_count: int, scanned_at: string|null}
     */
    public function card(Machine $machine): array
    {
        $report = $machine->newestReport;
        $advice = $this->advisor->adviseAll($report->flags ?? []);

        return [
            ...$this->identity($machine),
            'top_issue' => isset($advice[0]) ? $this->advice($advice[0]) : null,
            'issue_count' => count($advice),
            'scanned_at' => $report?->created_at?->toJSON(),
        ];
    }

    /**
     * @return array{id: int, name: string|null, os: string|null, device_type: string|null, model: string|null, status: string|null}
     */
    public function identity(Machine $machine): array
    {
        return [
            'id' => $machine->id,
            'name' => $machine->name,
            'os' => $machine->os,
            'device_type' => $machine->device_type,
            'model' => $machine->model,
            'status' => $machine->status === MachineStatus::Reject ? null : $machine->status->value,
        ];
    }

    /**
     * @return array{scanned_at: string|null, facts: array{free_disk: string|null, memory: string|null, cpu: string|null}, advice: list<array{severity: string, headline: string, advice: string|null}>, fixed_count: int|null}|null
     */
    public function report(Machine $machine): ?array
    {
        $report = $machine->newestReport;

        if ($report === null) {
            return null;
        }

        return [
            'scanned_at' => $report->created_at?->toJSON(),
            'facts' => $this->facts->from($report->markdown),
            'advice' => array_map($this->advice(...), $this->advisor->adviseAll($report->flags, ReportSections::parse($report->markdown))),
            'fixed_count' => $this->fixedCount($machine),
        ];
    }

    // Admin-only flags vanish from a scan run without admin rights and would read as fixed, so unequal or unknown rights give no count.
    private function fixedCount(Machine $machine): ?int
    {
        $changes = $this->compare->handle($machine);

        return $changes !== null && $changes['same_rights'] ? count($changes['fixed']) : null;
    }

    /**
     * @return array{severity: string, headline: string, advice: string|null}
     */
    private function advice(FlagAdvice $advice): array
    {
        // Only a flag no rule matched has no advice, and its headline is the scanner's raw text.
        if ($advice->advice === null) {
            return ['severity' => $advice->severity->value, 'headline' => trans('flags.unknown.headline'), 'advice' => null];
        }

        return [
            'severity' => $advice->severity->value,
            'headline' => $advice->headline,
            'advice' => $advice->advice,
        ];
    }
}
