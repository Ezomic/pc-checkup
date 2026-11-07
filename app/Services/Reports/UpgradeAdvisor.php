<?php

declare(strict_types=1);

namespace App\Services\Reports;

use Illuminate\Support\Str;

/**
 * One concrete upgrade for the owner page, from the age of the machine, its drives, its memory and the
 * verdict: an SSD for a spinning hard drive first, else memory up to TARGET_MEMORY_GB, and memory only
 * when the scan says it sits in sockets. Never for a machine past the age at which the verdict plans a
 * replacement, or when the verdict says replace. An unknown age does not hold the advice back.
 *
 * @phpstan-import-type Card from ReportDetails
 * @phpstan-import-type Parts from ReportParts
 * @phpstan-import-type PartsDrive from ReportParts
 *
 * @phpstan-type Advice array{part: 'ssd'|'memory', text: string, memoryType: string|null, moduleGb: int|null, bus: string|null, driveGb: int|null, form: string|null}
 */
final readonly class UpgradeAdvisor
{
    public const TARGET_MEMORY_GB = 8;

    public const OLDEST_WORTH_UPGRADING_YEARS = 8;

    private const REPLACE = 'Replace';

    /**
     * @param  list<Card>  $cards
     * @return Advice|null
     */
    public function advise(ReportSections $sections, array $cards): ?array
    {
        $years = ReportMachineAge::of($sections)['years'] ?? null;

        if ($this->verdict($cards) === self::REPLACE || ($years !== null && $years > self::OLDEST_WORTH_UPGRADING_YEARS)) {
            return null;
        }

        $parts = (new ReportParts)->read($sections);

        return $this->drive($parts['drives'], $years) ?? $this->memory($parts);
    }

    /**
     * @param  list<Card>  $cards
     */
    private function verdict(array $cards): ?string
    {
        foreach ($cards as $card) {
            if ($card['id'] === 'verdict') {
                return ReportVerdict::outcome($card['rows']);
            }
        }

        return null;
    }

    /**
     * @param  list<PartsDrive>  $drives
     * @return Advice|null
     */
    private function drive(array $drives, ?int $years): ?array
    {
        $spinning = array_values(array_filter($drives, fn (array $drive): bool => $drive['media'] === 'HDD'));

        if ($spinning === [] || in_array('SSD', array_column($drives, 'media'), true)) {
            return null;
        }

        $size = $spinning[0]['bytes'] === null ? '' : ByteSize::format($spinning[0]['bytes']).' ';
        $bus = strtoupper((string) $spinning[0]['bus']) === 'SATA' ? 'SATA' : null;
        $machine = $years === null || $years < 1 ? 'this machine' : "a machine of about {$years} ".Str::plural('year', $years).' old';

        return [
            'part' => 'ssd',
            'text' => "Swap the {$size}spinning hard drive for ".($bus === null ? 'an SSD' : 'a SATA SSD').": the biggest speed gain for {$machine}.",
            'memoryType' => null,
            'moduleGb' => null,
            'bus' => $bus,
            'driveGb' => $spinning[0]['bytes'] === null ? null : (int) round($spinning[0]['bytes'] / 1024 ** 3),
            'form' => null,
        ];
    }

    /**
     * @param  Parts  $parts
     * @return Advice|null
     */
    private function memory(array $parts): ?array
    {
        $installed = $parts['installedGb'];

        if ($installed === null || $installed >= self::TARGET_MEMORY_GB || $parts['fit'] !== 'socketed' || $parts['free'] === null || $parts['slots'] === null) {
            return null;
        }

        return $parts['free'] > 0 ? $this->add($parts, $installed) : $this->swap($parts, $installed, $parts['slots'] - $parts['free']);
    }

    /**
     * @param  Parts  $parts
     * @return Advice|null
     */
    private function add(array $parts, float $installed): ?array
    {
        $needed = (int) ceil(self::TARGET_MEMORY_GB - $installed);
        $largest = (int) ceil(max([0.0, ...array_map(fn (array $module): float => (float) $module['bytes'], ReportParts::installed($parts['modules']))]) / 1024 ** 3);

        foreach ([$this->buyable(max($needed, $largest)), $this->buyable($needed)] as $module) {
            if ($parts['maximumGb'] === null || $installed + $module <= $parts['maximumGb']) {
                return $this->advice($parts, $module, "Add a module of {$module} GB".$this->details($parts).' in the free slot', $installed, $installed + $module);
            }
        }

        return null;
    }

    /**
     * @param  Parts  $parts
     * @return Advice|null
     */
    private function swap(array $parts, float $installed, int $count): ?array
    {
        $module = $this->buyable((int) ceil(self::TARGET_MEMORY_GB / max($count, 1)));
        $total = $module * $count;

        if ($parts['maximumGb'] === null || $total > $parts['maximumGb']) {
            return null;
        }

        $action = $count === 1
            ? "Replace the memory module with a module of {$module} GB"
            : "Replace the {$count} memory modules with {$count} modules of {$module} GB";

        return $this->advice($parts, $module, $action.$this->details($parts), $installed, $total);
    }

    /**
     * @param  Parts  $parts
     * @return Advice
     */
    private function advice(array $parts, int $module, string $action, float $from, float $to): array
    {
        $installed = ReportParts::installed($parts['modules']);
        $types = array_values(array_unique(array_filter(array_column($installed, 'type'))));
        $forms = array_values(array_unique(array_column($installed, 'form')));

        return [
            'part' => 'memory',
            'text' => "{$action} to go from {$this->gigabytes($from)} to {$this->gigabytes($to)} GB of memory.",
            'memoryType' => count($types) === 1 ? $types[0] : null,
            'moduleGb' => $module,
            'bus' => null,
            'driveGb' => null,
            'form' => count($forms) === 1 ? $forms[0] : null,
        ];
    }

    /**
     * @param  Parts  $parts
     */
    private function details(array $parts): string
    {
        $description = ReportParts::describe($parts['modules']);

        return $description === null ? '' : " ({$description})";
    }

    private function buyable(int $gigabytes): int
    {
        $size = 1;

        while ($size < $gigabytes) {
            $size *= 2;
        }

        return $size;
    }

    private function gigabytes(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 1, '.', ''), '0'), '.');
    }
}
