<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Report;
use App\Services\Reports\RemovedSectionStripper;
use App\Services\Reports\ReportRedactor;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

final class RedactReportsCommand extends Command
{
    protected $signature = 'reports:redact
        {--dry-run : Only count the reports that would change, change nothing}
        {--strip-removed-sections : Also cut out whole sections the scans no longer collect: raw Wi-Fi, DNS, hosts file, privacy permissions, certificates and saved networks}';

    protected $description = 'Remove tokens, passwords, binary blobs and the OS user name from stored reports, soft deleted ones included';

    public function handle(ReportRedactor $redactor, RemovedSectionStripper $stripper): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $strip = (bool) $this->option('strip-removed-sections');
        $total = 0;
        $changed = 0;
        $stripped = 0;
        $kept = 0;

        Report::withTrashed()->select(['id', 'markdown'])->chunkById(100, function (Collection $reports) use ($redactor, $stripper, $dryRun, $strip, &$total, &$changed, &$stripped, &$kept): void {
            foreach ($reports as $report) {
                $total++;
                $redacted = $redactor->redact($report->markdown);
                // Redacting first lets the masker learn a user name from a section that is cut next.
                $result = $strip ? $stripper->strip($redacted) : ['markdown' => $redacted, 'removed' => [], 'kept' => []];
                $cleaned = $result['markdown'];
                $kept += count($result['kept']);
                $this->describe($report->id, $result['removed'], $result['kept'], $dryRun);

                if ($redacted !== $report->markdown) {
                    $changed++;
                }

                if ($cleaned !== $redacted) {
                    $stripped++;
                }

                if ($cleaned !== $report->markdown && ! $dryRun) {
                    // Straight to the query builder: the checksum stays that of the original scan, and
                    // updated_at is not touched, so nothing about the report looks edited.
                    Report::withTrashed()->whereKey($report->id)->toBase()->update(['markdown' => $cleaned]);
                }
            }
        });

        $this->components->info($dryRun
            ? "{$changed} of {$total} reports contain secrets or a user name."
            : "Redacted {$changed} of {$total} reports.");

        if ($strip) {
            $this->components->info($dryRun
                ? "{$stripped} of {$total} reports hold sections the scans no longer collect."
                : "Removed old sections from {$stripped} of {$total} reports.");
        }

        if ($kept > 0) {
            $this->warn("Old sections kept to check by hand: {$kept}.");
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $removed
     * @param  list<string>  $kept
     */
    private function describe(int $id, array $removed, array $kept, bool $dryRun): void
    {
        foreach ($kept as $title) {
            $this->warn("Report {$id}: kept \"{$title}\" because where it ends is unclear. Check it by hand.");
        }

        if ($removed !== []) {
            $this->line("Report {$id}: ".($dryRun ? 'would remove' : 'removed').' "'.implode('", "', $removed).'"');
        }
    }
}
