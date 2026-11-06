<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Report;
use App\Services\Reports\ReportRedactor;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

final class RedactReportsCommand extends Command
{
    protected $signature = 'reports:redact {--dry-run : Only count the reports that would change, change nothing}';

    protected $description = 'Remove tokens, passwords, binary blobs and the OS user name from stored reports, soft deleted ones included';

    public function handle(ReportRedactor $redactor): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $total = 0;
        $changed = 0;

        Report::withTrashed()->select(['id', 'markdown'])->chunkById(100, function (Collection $reports) use ($redactor, $dryRun, &$total, &$changed): void {
            foreach ($reports as $report) {
                $total++;
                $redacted = $redactor->redact($report->markdown);

                if ($redacted === $report->markdown) {
                    continue;
                }

                $changed++;

                if (! $dryRun) {
                    // Straight to the query builder: the checksum stays that of the original scan, and
                    // updated_at is not touched, so nothing about the report looks edited.
                    Report::withTrashed()->whereKey($report->id)->toBase()->update(['markdown' => $redacted]);
                }
            }
        });

        $this->components->info($dryRun
            ? "{$changed} of {$total} reports contain secrets or a user name."
            : "Redacted {$changed} of {$total} reports.");

        return self::SUCCESS;
    }
}
