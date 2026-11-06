<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\AttentionLevel;

/**
 * @phpstan-import-type Row from ReportDetails
 * @phpstan-import-type Drive from ReportWindowsHealth
 * @phpstan-import-type Reason from \App\Services\NeedsAttention
 */
final readonly class ReportDriveHealth
{
    public const WORN_PERCENT = 80;

    private const HEALTHY = 'Healthy';

    private const UNHEALTHY = ['Warning', 'Unhealthy'];

    private const COVERED_FLAGS = '/^Disk .+ (?:health|status) is |SSD wear is /';

    private const ADVICE = 'Back up your files and replace this drive soon.';

    public function __construct(private ReportWindowsHealth $health = new ReportWindowsHealth) {}

    /**
     * @return list<Row>
     */
    public function rows(ReportSections $sections): array
    {
        return array_column($this->findings($sections), 'row');
    }

    /**
     * @return list<Reason>
     */
    public function reasons(ReportSections $sections): array
    {
        $unique = [];

        foreach ($this->findings($sections) as $finding) {
            foreach ($finding['reasons'] as $reason) {
                $unique[$reason['text']] = $reason;
            }
        }

        return array_values($unique);
    }

    /**
     * @return list<string>
     */
    public function coveredFlags(ReportSections $sections): array
    {
        return $this->findings($sections) === [] ? [] : [self::COVERED_FLAGS];
    }

    /**
     * @return list<array{row: Row, reasons: list<Reason>}>
     */
    private function findings(ReportSections $sections): array
    {
        $facts = $this->health->read($sections);
        $seen = [];
        $findings = [];

        foreach ($facts['drives'] ?? [] as $drive) {
            $finding = $this->finding($drive, $seen);

            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * @param  Drive  $drive
     * @param  array<string, int>  $seen
     * @return array{row: Row, reasons: list<Reason>}|null
     */
    private function finding(array $drive, array &$seen): ?array
    {
        $unhealthy = in_array($drive['health'], self::UNHEALTHY, true);
        $worn = $drive['wear'] !== null && $drive['wear'] > self::WORN_PERCENT;
        $errors = $drive['readErrors'] !== null && $drive['readErrors'] > 0;
        $parts = array_values(array_filter([
            $drive['health'],
            $drive['wear'] === null ? null : round($drive['wear']).'% worn',
            $drive['temperature'] === null ? null : "{$drive['temperature']} C",
            $errors ? $drive['readErrors'].' read '.($drive['readErrors'] === 1 ? 'error' : 'errors') : null,
        ]));

        if ($parts === []) {
            return null;
        }

        $problem = ! $drive['usb'] && ($unhealthy || $worn || $errors);
        $seen[$drive['name']] = ($seen[$drive['name']] ?? 0) + 1;
        $label = $drive['name'].($drive['usb'] ? ' (USB)' : '').($seen[$drive['name']] > 1 ? " ({$seen[$drive['name']]})" : '');
        $value = implode(', ', $parts).($problem ? '. '.self::ADVICE : '');

        return [
            'row' => ['label' => $label, 'value' => $value, 'tone' => match (true) {
                $problem => 'bad',
                $drive['health'] === self::HEALTHY => 'good',
                default => 'neutral',
            }],
            'reasons' => $problem ? $this->driveReasons($unhealthy, $worn, $errors) : [],
        ];
    }

    /**
     * @return list<Reason>
     */
    private function driveReasons(bool $unhealthy, bool $worn, bool $errors): array
    {
        return array_values(array_filter([
            $unhealthy ? ['level' => AttentionLevel::Urgent->value, 'text' => 'A drive reports that it is not healthy'] : null,
            $worn ? ['level' => AttentionLevel::Urgent->value, 'text' => 'A drive is more than '.self::WORN_PERCENT.'% worn out'] : null,
            $errors ? ['level' => AttentionLevel::Soon->value, 'text' => 'A drive has reported read errors'] : null,
        ]));
    }
}
