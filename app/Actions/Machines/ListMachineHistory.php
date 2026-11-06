<?php

declare(strict_types=1);

namespace App\Actions\Machines;

use App\Models\Machine;
use App\Models\Report;
use App\Services\Reports\ReportFacts;
use App\Services\Reports\ReportParser;
use App\Services\Reports\ReportSections;
use App\Services\Reports\ReportVerdict;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @phpstan-type Row array{id: int, created_at: string|null, kind: string, script_version: string|null, ran_as_admin: bool|null, free_disk: string|null, flags_count: int, verdict: string|null}
 */
final readonly class ListMachineHistory
{
    public const int PER_PAGE = 50;

    public function __construct(private ReportFacts $facts, private ReportParser $parser, private ReportVerdict $verdict) {}

    /**
     * @return LengthAwarePaginator<int, Row>
     */
    public function handle(Machine $machine): LengthAwarePaginator
    {
        return $machine->reports()->latest()->latest('id')->paginate(self::PER_PAGE)
            ->through(fn (Report $report): array => $this->row($report));
    }

    /**
     * @return Row
     */
    private function row(Report $report): array
    {
        $freeDisk = $this->facts->from($report->markdown)['free_disk'];

        return [
            'id' => $report->id,
            'created_at' => $report->created_at?->toJSON(),
            'kind' => $report->kind->value,
            'script_version' => $this->parser->parse($report->markdown)->scriptVersion,
            'ran_as_admin' => $report->ran_as_admin,
            'free_disk' => $freeDisk,
            'flags_count' => count($report->flags),
            'verdict' => ReportVerdict::outcome($this->verdict->rows(ReportSections::parse($report->markdown), $report->flags, $freeDisk)),
        ];
    }
}
