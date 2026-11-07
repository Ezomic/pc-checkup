<?php

declare(strict_types=1);

namespace App\Services\Reports;

use Illuminate\Support\Str;

/**
 * @phpstan-import-type Card from ReportDetails
 * @phpstan-import-type Row from ReportDetails
 * @phpstan-import-type Parts from ReportParts
 * @phpstan-import-type Module from ReportParts
 * @phpstan-import-type PartsDrive from ReportParts
 *
 * @phpstan-type Pair array{0: string, 1: string|null}
 */
final readonly class ReportPartsCard
{
    private const NOT_UPGRADEABLE = [
        'unified' => 'No, the memory is part of the Apple chip',
        'soldered' => 'No, the memory is soldered to the board',
        'lpddr' => 'No, LPDDR memory is soldered to the board',
    ];

    private const MAXIMUM_NOTE = 'The most memory supported is what the firmware reports and is sometimes too low. The maker\'s specifications have the final word.';

    private const RECORDED_NOTE = 'Sizes, types and speeds only: serial and part numbers were not recorded.';

    private const OWNER_NOTE = 'Only you see this: it is not on the pages you share with clients.';

    /**
     * @param  Parts  $parts
     * @return Card|null
     */
    public function card(array $parts): ?array
    {
        if (! $parts['section']) {
            return null;
        }

        $soldered = self::NOT_UPGRADEABLE[$parts['fit'] ?? ''] ?? null;
        $maximum = $soldered === null ? $parts['maximumGb'] : null;
        $rows = $this->rows([
            ['Memory', $this->memory($parts, $soldered !== null)],
            ['Memory type', $parts['fit'] === 'unified' ? $parts['unifiedType'] : ReportParts::describe($parts['modules'])],
            $this->upgradeable($parts, $soldered),
            ['Most memory supported', $maximum === null ? null : "{$maximum} GB"],
            ...($soldered === null ? $this->slots($parts['modules']) : []),
            ...$this->drives($parts['drives']),
        ]);

        if (array_diff(array_column($rows, 'label'), ['Memory']) === []) {
            return null;
        }

        return [
            'id' => 'parts',
            'title' => 'Parts',
            'rows' => $rows,
            'list' => null,
            'notes' => [...$parts['notes'], ...($maximum === null ? [] : [self::MAXIMUM_NOTE]), self::RECORDED_NOTE, self::OWNER_NOTE],
        ];
    }

    /**
     * @param  Parts  $parts
     */
    private function memory(array $parts, bool $soldered): ?string
    {
        if ($parts['installedGb'] === null) {
            return null;
        }

        $amount = rtrim(rtrim(number_format($parts['installedGb'], 1, '.', ''), '0'), '.').' GB';

        return $soldered || $parts['slots'] === null || $parts['free'] === null
            ? $amount
            : "{$amount} in ".($parts['slots'] - $parts['free'])." of {$parts['slots']} slots";
    }

    /**
     * @param  Parts  $parts
     * @return Pair
     */
    private function upgradeable(array $parts, ?string $soldered): array
    {
        $free = $parts['free'];

        return match (true) {
            $soldered !== null => ['Upgradeable', $soldered],
            $parts['fit'] === 'socketed' => ['Upgradeable', match (true) {
                $free === null => 'Yes',
                $free > 0 => "Yes, {$free} free ".Str::plural('slot', $free),
                default => 'Only by swapping modules, all slots are in use',
            }],
            $free !== null => ['Free slots', $free > 0 ? (string) $free : 'None'],
            default => ['Upgradeable', null],
        };
    }

    /**
     * @param  list<Module>  $modules
     * @return list<Pair>
     */
    private function slots(array $modules): array
    {
        $mixed = count(ReportParts::speeds($modules)) > 1;
        $pairs = [];

        foreach ($modules as $index => $module) {
            $size = $module['bytes'] === null ? 'Empty' : ByteSize::format($module['bytes']);
            $speed = $mixed && $module['speed'] !== null ? ", {$module['speed']} MHz" : '';
            $pairs[] = [$module['slot'] === '' ? 'Slot '.($index + 1) : $module['slot'], $size.$speed];
        }

        return $pairs;
    }

    /**
     * @param  list<PartsDrive>  $drives
     * @return list<Pair>
     */
    private function drives(array $drives): array
    {
        $pairs = [];

        foreach ($drives as $index => $drive) {
            $pairs[] = ['Drive '.($index + 1), $this->kind($drive).($drive['bytes'] === null ? '' : ', '.ByteSize::format($drive['bytes']))];
        }

        return $pairs;
    }

    /**
     * @param  PartsDrive  $drive
     */
    private function kind(array $drive): string
    {
        $bus = strtoupper((string) $drive['bus']);

        return match (true) {
            $bus === 'NVME' => 'NVMe SSD',
            in_array($bus, ['SD', 'MMC'], true) => 'eMMC flash storage (usually soldered)',
            $drive['media'] === 'SSD' && $bus === 'SATA' => 'SATA SSD',
            $drive['media'] === 'SSD' && $bus === 'PCIE' => 'PCIe SSD',
            $drive['media'] === 'SSD' => 'SSD',
            $drive['media'] === 'HDD' && $bus === 'SATA' => 'SATA hard drive (HDD)',
            $drive['media'] === 'HDD' => 'Hard drive (HDD)',
            $drive['bus'] !== null => "{$drive['bus']} drive",
            default => 'Drive',
        };
    }

    /**
     * @param  list<Pair>  $pairs
     * @return list<Row>
     */
    private function rows(array $pairs): array
    {
        $rows = [];
        $seen = [];

        foreach ($pairs as [$label, $value]) {
            if ($value === null) {
                continue;
            }

            $unique = $label;

            for ($number = 2; isset($seen[$unique]); $number++) {
                $unique = "{$label} ({$number})";
            }

            $seen[$unique] = true;
            $rows[] = ['label' => $unique, 'value' => $value, 'tone' => 'neutral'];
        }

        return $rows;
    }
}
