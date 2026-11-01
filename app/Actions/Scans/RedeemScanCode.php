<?php

declare(strict_types=1);

namespace App\Actions\Scans;

use App\Actions\Machines\ImportReport;
use App\Models\Report;
use App\Models\ScanCode;
use Illuminate\Support\Facades\DB;

final class RedeemScanCode
{
    public function __construct(private readonly ImportReport $import) {}

    /**
     * Null for any code that cannot be redeemed, without saying why.
     */
    public function handle(string $code, string $markdown): ?Report
    {
        if (preg_match('/\A[0-9]{6}\z/', $code) !== 1) {
            return null;
        }

        // One transaction, so a report that fails to import hands the code back.
        return DB::transaction(function () use ($code, $markdown): ?Report {
            // The conditional update is what makes the code single use: of two simultaneous
            // requests only one finds the row unused and unexpired, so only one gets a count of 1.
            $claimed = ScanCode::query()->forCode($code)->usable()->update(['used_at' => now()]);

            $scan = $claimed === 1 ? ScanCode::query()->forCode($code)->first() : null;

            if ($scan === null) {
                return null;
            }

            $report = $this->import->handle($scan->user()->sole(), $markdown);
            $scan->forceFill(['report_id' => $report->id])->save();

            return $report;
        });
    }
}
