<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\AttentionLevel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * @phpstan-import-type Card from ReportDetails
 * @phpstan-import-type Row from ReportDetails
 * @phpstan-import-type Reason from \App\Services\NeedsAttention
 */
final readonly class ReportUpdates
{
    public const STALE_AFTER_DAYS = 60;

    private const MAX_DAYS = 36500;

    private const RESTART_REASON = 'waiting for a restart';

    /**
     * @return Card|null
     */
    public function card(ReportSections $sections): ?array
    {
        $installed = $this->installed($sections);
        $restart = $this->restart($sections);
        $rows = array_values(array_filter([
            $installed === null ? null : $this->installedRow($installed, (new MacUpdateOutlook)->read($sections)),
            $restart === null ? null : $this->restartRow($restart, $this->renames($sections)),
        ]));

        return $rows === [] ? null : ['id' => 'updates', 'title' => 'Updates', 'rows' => $rows, 'list' => null];
    }

    /**
     * @return list<Reason>
     */
    public function reasons(ReportSections $sections): array
    {
        $installed = $this->installed($sections);

        return array_values(array_filter([
            $installed !== null && $this->stale($installed['days']) ? ['level' => AttentionLevel::Soon->value, 'text' => 'No update installed for more than '.self::STALE_AFTER_DAYS.' days'] : null,
            $this->restart($sections) === true ? ['level' => AttentionLevel::Soon->value, 'text' => 'A restart is waiting to finish installing updates'] : null,
        ]));
    }

    /**
     * @return array{date: CarbonImmutable, days: int}|null
     */
    private function installed(ReportSections $sections): ?array
    {
        $text = $sections->text('Software update age') ?? $sections->text('Windows Update');
        $found = $text !== null && preg_match('/^Newest installed (?:macOS )?update:\s*(\d{4})-(\d{2})-(\d{2})\s*\((\d{1,6}) days? ago\)/m', $text, $match) === 1;

        if (! $found || ! checkdate((int) $match[2], (int) $match[3], (int) $match[1]) || (int) $match[4] > self::MAX_DAYS) {
            return null;
        }

        return ['date' => CarbonImmutable::createFromDate((int) $match[1], (int) $match[2], (int) $match[3]), 'days' => (int) $match[4]];
    }

    private function restart(ReportSections $sections): ?bool
    {
        $value = mb_strtolower((string) ReportDates::keyValue($this->lines($sections), 'Restart pending'));

        return match (true) {
            $value === 'no' => false,
            str_contains($value, self::RESTART_REASON) => true,
            default => null,
        };
    }

    private function renames(ReportSections $sections): bool
    {
        return mb_strtolower((string) ReportDates::keyValue($this->lines($sections), 'Pending file rename operations (often harmless)')) === 'yes';
    }

    /**
     * @return list<string>
     */
    private function lines(ReportSections $sections): array
    {
        return preg_split('/\r\n|\r|\n/', (string) $sections->text('Windows Update')) ?: [];
    }

    private function stale(int $days): bool
    {
        return $days > self::STALE_AFTER_DAYS;
    }

    /**
     * @param  array{date: CarbonImmutable, days: int}  $installed
     * @param  array{running: string, newest: string|null, newestSupported: bool}|null  $outlook
     * @return Row
     */
    private function installedRow(array $installed, ?array $outlook): array
    {
        $stale = $this->stale($installed['days']);
        $when = $installed['date']->format('j F Y').", {$installed['days']} ".Str::plural('day', $installed['days']).' before the scan';

        return ['label' => 'Last update installed', 'value' => $stale ? "{$when}. {$this->advice($outlook)}" : $when, 'tone' => $stale ? 'bad' : 'good'];
    }

    /**
     * @param  array{running: string, newest: string|null, newestSupported: bool}|null  $outlook
     */
    private function advice(?array $outlook): string
    {
        return match (true) {
            $outlook === null => 'Check for updates and install them.',
            $outlook['newest'] === null => "This Mac already runs the newest macOS it can, {$outlook['running']}, and it no longer gets security updates.",
            ! $outlook['newestSupported'] => "No more updates come out for {$outlook['running']}. The newest macOS this Mac can run, {$outlook['newest']}, no longer gets security updates either.",
            default => "No more updates come out for {$outlook['running']}. The newest macOS this Mac can run is {$outlook['newest']}.",
        };
    }

    /**
     * @return Row
     */
    private function restartRow(bool $pending, bool $renames): array
    {
        return match (true) {
            $pending => ['label' => 'Restart pending', 'value' => 'Yes, to finish installing updates. Restart the computer.', 'tone' => 'bad'],
            $renames => ['label' => 'Restart pending', 'value' => 'No, but some files will be replaced at the next restart', 'tone' => 'neutral'],
            default => ['label' => 'Restart pending', 'value' => 'No', 'tone' => 'good'],
        };
    }
}
