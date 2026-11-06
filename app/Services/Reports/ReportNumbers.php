<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Report;

/**
 * The measurements two scans can be compared on. A key is only present when the scan states it
 * in a form that can be read, so a missing key never means a bad number.
 */
final readonly class ReportNumbers
{
    public const array BATTERY_CONDITIONS = ['normal', 'service_recommended', 'replace_soon', 'replace_now', 'poor'];

    private const STARTUP_LABELS = ['Startup programs', 'Login items'];

    public function __construct(private ReportFacts $facts, private ReportStartup $startup) {}

    /**
     * @return array{free_disk?: int, memory?: float, startup?: int, battery_cycles?: int, battery_condition?: string, disk_read?: float, disk_write?: float}
     */
    public function from(Report $report): array
    {
        $sections = ReportSections::parse($report->markdown);
        $facts = $this->facts->from($report->markdown);
        $battery = $this->battery($sections);

        return array_filter([
            'free_disk' => $this->capture('/\A(\d+)%\z/', $facts['free_disk']),
            'memory' => $this->memory($facts['memory']),
            'startup' => $this->startupCount($sections),
            'battery_cycles' => $battery['cycles'],
            'battery_condition' => $battery['condition'],
            'disk_read' => $this->speed($report, 'disk_read_mb_s'),
            'disk_write' => $this->speed($report, 'disk_write_mb_s'),
        ], fn (int|float|string|null $value): bool => $value !== null);
    }

    private function capture(string $pattern, ?string $text): ?int
    {
        return $text !== null && preg_match($pattern, $text, $match) === 1 ? (int) $match[1] : null;
    }

    private function memory(?string $text): ?float
    {
        if ($text === null || preg_match('/\A('.LocaleNumber::PATTERN.')\s*GB\z/i', $text, $match) !== 1) {
            return null;
        }

        return LocaleNumber::float($match[1]);
    }

    private function startupCount(ReportSections $sections): ?int
    {
        foreach ($this->startup->card($sections)['rows'] ?? [] as $row) {
            if (in_array($row['label'], self::STARTUP_LABELS, true)) {
                return LocaleNumber::count(str_replace(',', '', $row['value']));
            }
        }

        return null;
    }

    /**
     * @return array{cycles: int|null, condition: string|null}
     */
    private function battery(ReportSections $sections): array
    {
        if (preg_match('/^(\S+) cycles,\s*(.+)$/i', (string) $sections->header('battery'), $line) !== 1) {
            return ['cycles' => null, 'condition' => null];
        }

        $condition = preg_match('/^condition (.+?),/i', $line[2], $parts) === 1
            ? str_replace(' ', '_', strtolower(trim($parts[1])))
            : null;

        return [
            'cycles' => ctype_digit($line[1]) ? (int) $line[1] : null,
            'condition' => in_array($condition, self::BATTERY_CONDITIONS, true) ? $condition : null,
        ];
    }

    private function speed(Report $report, string $key): ?float
    {
        $value = $report->benchmark[$key] ?? null;

        return (is_int($value) || is_float($value)) && $value > 0 ? (float) $value : null;
    }
}
