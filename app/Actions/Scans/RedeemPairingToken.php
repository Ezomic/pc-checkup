<?php

declare(strict_types=1);

namespace App\Actions\Scans;

use App\Actions\Clients\AssignMachineToClient;
use App\Actions\Machines\ImportReport;
use App\Models\Report;
use App\Models\ScanPairing;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RedeemPairingToken
{
    public const WRONG_MACHINE = 'This report is from another computer than the machine chosen when the scan was approved. Run the script again and choose the right machine.';

    public function __construct(
        private readonly ImportReport $import,
        private readonly AssignMachineToClient $assign,
    ) {}

    /**
     * Null for any token that cannot be redeemed, without saying why.
     */
    public function handle(string $token, string $markdown): ?Report
    {
        if (! ScanPairing::wellFormed($token, ScanPairing::TOKEN_LENGTH)) {
            return null;
        }

        // One transaction, so a report that fails to import, or turns out to be another machine,
        // hands the token back.
        return DB::transaction(function () use ($token, $markdown): ?Report {
            $pairing = ScanPairing::query()->redeemable($token)->first();

            // The conditional update is what makes the token single use, as for scan codes: of two
            // simultaneous uploads only one still finds the row unused.
            if ($pairing === null || ScanPairing::query()->whereKey($pairing->id)->redeemable($token)->update(['used_at' => now()]) !== 1) {
                return null;
            }

            $client = $pairing->client;

            if ($client === null) {
                return null;
            }

            $report = $this->import->handle($pairing->user()->sole(), $markdown);

            if ($pairing->machine_id !== null && $report->machine_id !== $pairing->machine_id) {
                throw ValidationException::withMessages(['markdown' => self::WRONG_MACHINE]);
            }

            $this->assign->handle($report->machine()->sole(), $client->id);
            $pairing->forceFill(['machine_id' => $report->machine_id])->save();

            return $report;
        });
    }
}
