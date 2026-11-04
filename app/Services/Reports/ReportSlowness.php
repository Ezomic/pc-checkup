<?php

declare(strict_types=1);

namespace App\Services\Reports;

use Illuminate\Support\Str;

/**
 * @phpstan-import-type Row from ReportDetails
 *
 * @phpstan-type Reason array{title: string, advice: string, severity: int}
 * @phpstan-type Program array{name: string, heavy: bool}
 * @phpstan-type Readings array{
 *     freeMemory: int|null,
 *     swapMb: float|null,
 *     ramGb: float|null,
 *     freeDisk: int|null,
 *     spinning: bool,
 *     slowestSpeed: float|null,
 *     idle: float|null,
 *     load: float|null,
 *     cores: int|null,
 *     speedLimit: int|null,
 *     lowPower: bool|null,
 *     spotlight: float|null,
 *     cpuPrograms: list<Program>,
 *     memoryPrograms: list<Program>
 * }
 */
final readonly class ReportSlowness
{
    private const SHOWN = 3;

    private const LOW = 1;

    private const MEDIUM = 2;

    private const HIGH = 3;

    private const MEMORY_FREE_LOW_PERCENT = 15;

    private const MEMORY_FREE_CRITICAL_PERCENT = 8;

    private const SWAP_HEAVY_MB = 2048;

    private const SWAP_SANE_MAX_MB = 1000000;

    private const RAM_SMALL_GB = 8;

    private const RAM_SANE_MAX_GB = 16384;

    private const DISK_FREE_LOW_PERCENT = 10;

    private const DISK_FREE_CRITICAL_PERCENT = 5;

    private const DISK_FREE_TARGET_PERCENT = 15;

    private const DISK_SLOW_MB_PER_SECOND = 100;

    private const CPU_IDLE_BUSY_PERCENT = 15;

    private const LOAD_PER_CORE = 1.0;

    private const MAC_PROGRAM_BUSY_PERCENT = 80.0;

    private const WINDOWS_PROGRAM_BUSY_PERCENT = 40.0;

    private const PROGRAM_MEMORY_HEAVY_PERCENT = 35.0;

    private const SPOTLIGHT_BUSY_PERCENT = 20.0;

    private const FULL_SPEED_PERCENT = 100;

    private const NAME_LIMIT = 40;

    private const IGNORED_PROGRAMS = ['Idle', 'kernel_task'];

    private const UNITS_IN_MB = ['K' => 1 / 1024, 'M' => 1, 'G' => 1024, 'T' => 1024 ** 2];

    /**
     * @return list<Row>
     */
    public function rows(ReportSections $sections, ?string $freeDisk, bool $spinningDrive): array
    {
        $readings = $this->readings($sections, $freeDisk, $spinningDrive);

        if (! $this->hasData($readings)) {
            return [];
        }

        $reasons = $this->reasons($readings);

        return [
            $this->verdict($reasons),
            ...array_map(fn (array $reason): array => [
                'label' => $reason['title'],
                'value' => $reason['advice'],
                'tone' => $reason['severity'] >= self::HIGH ? 'bad' : 'neutral',
            ], $reasons),
        ];
    }

    /**
     * @return Readings
     */
    private function readings(ReportSections $sections, ?string $freeDisk, bool $spinningDrive): array
    {
        $ramGb = $this->ramGb($sections->header('memory'));
        $performance = $sections->text('Performance: load, swap, memory, throttling');

        return [
            'freeMemory' => $this->freeMemory($sections->text('Memory pressure'), $sections->text('CPU and memory')),
            'swapMb' => $this->swapMb($performance),
            'ramGb' => $ramGb,
            'freeDisk' => $this->percent('/^(\d{1,3})\s*%?$/', $freeDisk),
            'spinning' => $spinningDrive,
            'slowestSpeed' => $this->slowestSpeed($sections->text('Performance: disk write and read speed (512 MB test)')),
            'idle' => $this->idle($sections->text('Performance: 10 second CPU sample (second snapshot is the real one)')),
            'load' => $this->load($performance),
            'cores' => $this->cores($sections->text('Model identifier and chip')),
            'speedLimit' => $this->percent('/CPU_Speed_Limit\s*=\s*(\d{1,3})(?!\d)/', $performance),
            'lowPower' => $this->lowPower($performance),
            'spotlight' => $this->spotlight($sections->text('Performance: Spotlight indexing')),
            'cpuPrograms' => [
                ...$this->macPrograms($sections->text('Top processes by CPU'), true),
                ...$this->windowsCpuPrograms($sections->text('Top processes by CPU (5 second sample, grouped by name)')),
            ],
            'memoryPrograms' => [
                ...$this->macPrograms($sections->text('Top processes by memory'), false),
                ...$this->windowsMemoryPrograms($sections->text('Top processes by memory (grouped by name)'), $ramGb),
            ],
        ];
    }

    /**
     * @param  Readings  $readings
     */
    private function hasData(array $readings): bool
    {
        return array_filter($readings, fn (mixed $reading): bool => $reading !== null && $reading !== false && $reading !== []) !== [];
    }

    /**
     * @param  Readings  $readings
     * @return list<Reason>
     */
    private function reasons(array $readings): array
    {
        $reasons = array_values(array_filter([
            $this->lowMemory($readings['freeMemory']),
            $this->heavySwap($readings['swapMb']),
            $this->smallMemory($readings['ramGb']),
            $this->fullDrive($readings['freeDisk']),
            $this->spinningDrive($readings['spinning']),
            $this->slowDrive($readings['slowestSpeed'], $readings['spinning']),
            $this->busyProcessor($readings['idle'], $readings['load'], $readings['cores']),
            $this->busyProgram($readings['cpuPrograms']),
            $this->hungryProgram($readings['memoryPrograms']),
            $this->tooHot($readings['speedLimit']),
            $this->lowPowerMode($readings['lowPower']),
            $this->indexing($readings['spotlight']),
        ]));

        usort($reasons, fn (array $a, array $b): int => $b['severity'] <=> $a['severity']);

        return array_slice($reasons, 0, self::SHOWN);
    }

    /**
     * @param  list<Reason>  $reasons
     * @return Row
     */
    private function verdict(array $reasons): array
    {
        if ($reasons === []) {
            return ['label' => 'Verdict', 'value' => 'Nothing obviously slowing it down', 'tone' => 'good'];
        }

        $count = count($reasons);
        $serious = max(array_column($reasons, 'severity')) >= self::HIGH;

        return ['label' => 'Verdict', 'value' => "Probably slow because of {$count} ".Str::plural('thing', $count), 'tone' => $serious ? 'bad' : 'neutral'];
    }

    /**
     * @return Reason|null
     */
    private function lowMemory(?int $free): ?array
    {
        if ($free === null || $free >= self::MEMORY_FREE_LOW_PERCENT) {
            return null;
        }

        $advice = 'Close programs and browser tabs you are not using. If it keeps happening, adding more memory would help.';

        return $free < self::MEMORY_FREE_CRITICAL_PERCENT
            ? $this->reason('Memory is almost full', $advice, self::HIGH)
            : $this->reason('Memory is getting full', $advice, self::MEDIUM);
    }

    /**
     * @return Reason|null
     */
    private function heavySwap(?float $swapMb): ?array
    {
        return $swapMb === null || $swapMb < self::SWAP_HEAVY_MB ? null : $this->reason(
            'The drive is being used as extra memory',
            'The computer ran out of memory and is using the drive instead, which is much slower. Close programs you do not need and restart it.',
            self::MEDIUM,
        );
    }

    /**
     * @return Reason|null
     */
    private function smallMemory(?float $ramGb): ?array
    {
        return $ramGb === null || $ramGb >= self::RAM_SMALL_GB ? null : $this->reason(
            'Only '.round($ramGb, 1).' GB of memory',
            'Programs and web pages today need more room than that. Adding memory, if this model allows it, is a cheap way to speed it up.',
            self::MEDIUM,
        );
    }

    /**
     * @return Reason|null
     */
    private function fullDrive(?int $free): ?array
    {
        if ($free === null || $free >= self::DISK_FREE_LOW_PERCENT) {
            return null;
        }

        $advice = 'A full drive slows everything down. Delete or move big files, old downloads and photos until at least '.self::DISK_FREE_TARGET_PERCENT.'% is free.';

        return $free < self::DISK_FREE_CRITICAL_PERCENT
            ? $this->reason('The drive is almost full', $advice, self::HIGH)
            : $this->reason('The drive is getting full', $advice, self::MEDIUM);
    }

    /**
     * @return Reason|null
     */
    private function spinningDrive(bool $spinning): ?array
    {
        return $spinning ? $this->reason(
            'The computer has an old style spinning drive',
            'Spinning drives are far slower than modern solid state drives. Replacing it with a solid state drive is the biggest speed up available.',
            self::HIGH,
        ) : null;
    }

    /**
     * @return Reason|null
     */
    private function slowDrive(?float $speed, bool $spinning): ?array
    {
        return $speed === null || $spinning || $speed >= self::DISK_SLOW_MB_PER_SECOND ? null : $this->reason(
            'The drive is slow to read and write',
            'The speed test managed only '.round($speed).' MB a second, far below a healthy drive. The drive may be failing or too full, so back up important files soon.',
            self::HIGH,
        );
    }

    /**
     * @return Reason|null
     */
    private function busyProcessor(?float $idle, ?float $load, ?int $cores): ?array
    {
        $idleBusy = $idle !== null && $idle <= self::CPU_IDLE_BUSY_PERCENT;
        $loadBusy = $load !== null && $cores !== null && $load >= $cores * self::LOAD_PER_CORE;

        return $idleBusy || $loadBusy ? $this->reason(
            'The processor is working flat out',
            'Close programs you are not using and restart the computer. If it stays busy with nothing open, ask for a check for unwanted software.',
            self::MEDIUM,
        ) : null;
    }

    /**
     * @param  list<Program>  $programs
     * @return Reason|null
     */
    private function busyProgram(array $programs): ?array
    {
        $name = $this->firstHeavy($programs);

        return $name === null ? null : $this->reason(
            "{$name} is working the processor hard",
            'Close it, or quit and open it again. If it keeps doing this, it may be stuck or need an update.',
            self::MEDIUM,
        );
    }

    /**
     * @param  list<Program>  $programs
     * @return Reason|null
     */
    private function hungryProgram(array $programs): ?array
    {
        $name = $this->firstHeavy($programs);

        return $name === null ? null : $this->reason(
            "{$name} is using a lot of memory",
            'Close it when you are not using it, or quit and open it again. Programs that grow over time often need an update.',
            self::LOW,
        );
    }

    /**
     * @return Reason|null
     */
    private function tooHot(?int $speedLimit): ?array
    {
        return $speedLimit === null || $speedLimit >= self::FULL_SPEED_PERCENT ? null : $this->reason(
            'The processor is slowing itself down',
            'The computer is slowing down to stay cool. Make sure the vents are clear, keep it off soft surfaces and clean out the dust.',
            self::HIGH,
        );
    }

    /**
     * @return Reason|null
     */
    private function lowPowerMode(?bool $lowPower): ?array
    {
        return $lowPower === true ? $this->reason(
            'Low Power Mode is on',
            'It saves power by slowing the computer down. Turn it off in the battery settings to get full speed.',
            self::LOW,
        ) : null;
    }

    /**
     * @return Reason|null
     */
    private function indexing(?float $spotlight): ?array
    {
        return $spotlight === null || $spotlight < self::SPOTLIGHT_BUSY_PERCENT ? null : $this->reason(
            'The Mac is busy cataloguing its files',
            'This is temporary and usually finishes within a day. Leave the Mac plugged in and check again tomorrow.',
            self::LOW,
        );
    }

    /**
     * @return Reason
     */
    private function reason(string $title, string $advice, int $severity): array
    {
        return ['title' => $title, 'advice' => $advice, 'severity' => $severity];
    }

    /**
     * @param  list<Program>  $programs
     */
    private function firstHeavy(array $programs): ?string
    {
        foreach ($programs as $program) {
            if ($program['heavy']) {
                return $program['name'];
            }
        }

        return null;
    }

    private function freeMemory(?string $mac, ?string $windows): ?int
    {
        return $this->percent('/System-wide memory free percentage:\s*(\d{1,3})(?!\d)/i', $mac)
            ?? $this->percent('/available:\s*(\d{1,3})(?!\d)%\s+of visible memory/i', $windows);
    }

    private function percent(string $pattern, ?string $text): ?int
    {
        if ($text === null || preg_match($pattern, $text, $match) !== 1) {
            return null;
        }

        return (int) $match[1] <= 100 ? (int) $match[1] : null;
    }

    private function swapMb(?string $text): ?float
    {
        if ($text === null || preg_match('/\bused\s*=\s*(\d+(?:\.\d+)?)\s*([KMGT])\b/', $text, $match) !== 1) {
            return null;
        }

        $megabytes = (float) $match[1] * self::UNITS_IN_MB[$match[2]];

        return $megabytes <= self::SWAP_SANE_MAX_MB ? $megabytes : null;
    }

    public function ramGb(?string $value): ?float
    {
        if ($value === null || preg_match('/^(\d+(?:\.\d+)?)\s*(MB|GB|TB)$/i', trim($value), $match) !== 1) {
            return null;
        }

        $gigabytes = (float) $match[1] * match (strtoupper($match[2])) {
            'MB' => 1 / 1024,
            'GB' => 1,
            default => 1024,
        };

        return $gigabytes > 0 && $gigabytes <= self::RAM_SANE_MAX_GB ? $gigabytes : null;
    }

    private function slowestSpeed(?string $text): ?float
    {
        if ($text === null || preg_match_all('/\((\d+)\s+bytes\/sec\)/', $text, $found) === 0) {
            return null;
        }

        $speeds = array_filter(array_map(fn (string $bytes): float => (float) $bytes / 1000000, $found[1]), fn (float $speed): bool => $speed > 0);

        return $speeds === [] ? null : min($speeds);
    }

    private function idle(?string $text): ?float
    {
        $found = $text !== null && preg_match('/CPU usage:.*?(?<![\d.])(\d{1,3}(?:\.\d+)?)%\s+idle/', $text, $match) === 1;

        return $found && (float) $match[1] <= 100 ? (float) $match[1] : null;
    }

    private function load(?string $text): ?float
    {
        $found = $text !== null && preg_match('/vm\.loadavg:\s*\{\s*[\d.]+\s+([\d.]+)\s+[\d.]+\s*\}/', $text, $match) === 1;

        return $found && is_numeric($match[1]) ? (float) $match[1] : null;
    }

    private function cores(?string $text): ?int
    {
        $found = $text !== null && preg_match('/^(\d{1,3})$/m', $text, $match) === 1;

        return $found && (int) $match[1] > 0 ? (int) $match[1] : null;
    }

    private function lowPower(?string $text): ?bool
    {
        $found = $text !== null && preg_match('/lowpowermode\s+([01])(?!\d)/i', $text, $match) === 1;

        return $found ? $match[1] === '1' : null;
    }

    private function spotlight(?string $text): ?float
    {
        if ($text === null || ! str_contains($text, 'Indexing')) {
            return null;
        }

        preg_match_all('/^\s*\d+\s+(\d+(?:\.\d+)?)\s+(?:mds|mdworker)\S*\s*$/m', $text, $found);

        return $found[1] === [] ? 0.0 : max(array_map('floatval', $found[1]));
    }

    /**
     * @return list<Program>
     */
    private function macPrograms(?string $text, bool $byProcessor): array
    {
        $programs = [];

        foreach (explode("\n", (string) $text) as $line) {
            if (preg_match('/^\s*\d+\s+(\d+(?:\.\d+)?)\s+(\d+(?:\.\d+)?)\s+(\S.*?)\s*$/', $line, $match) !== 1) {
                continue;
            }

            $name = $this->programName($match[3]);

            if ($name !== null) {
                $programs[] = ['name' => $name, 'heavy' => $byProcessor
                    ? (float) $match[1] >= self::MAC_PROGRAM_BUSY_PERCENT
                    : (float) $match[2] >= self::PROGRAM_MEMORY_HEAVY_PERCENT];
            }
        }

        return $programs;
    }

    /**
     * @return list<Program>
     */
    private function windowsCpuPrograms(?string $text): array
    {
        $programs = [];

        foreach (explode("\n", (string) $text) as $line) {
            if (preg_match('/^(\S.*?)\s+(\d+(?:\.\d+)?)\s+\d+\s+\d+\s*$/', $line, $match) !== 1) {
                continue;
            }

            $name = $this->programName($match[1]);

            if ($name !== null) {
                $programs[] = ['name' => $name, 'heavy' => (float) $match[2] >= self::WINDOWS_PROGRAM_BUSY_PERCENT];
            }
        }

        return $programs;
    }

    /**
     * @return list<Program>
     */
    private function windowsMemoryPrograms(?string $text, ?float $ramGb): array
    {
        $programs = [];

        foreach (explode("\n", (string) $text) as $line) {
            if (preg_match('/^(\S.*?)\s+(\d+)\s+\d+\s*$/', $line, $match) !== 1) {
                continue;
            }

            $name = $this->programName($match[1]);

            if ($name !== null) {
                $share = $ramGb === null ? 0.0 : (float) $match[2] / 1024 / $ramGb * 100;
                $programs[] = ['name' => $name, 'heavy' => $share >= self::PROGRAM_MEMORY_HEAVY_PERCENT];
            }
        }

        return $programs;
    }

    private function programName(string $raw): ?string
    {
        $name = trim((string) preg_replace('/\p{C}+/u', '', $raw));
        $name = (string) preg_replace('~^(?:/|[A-Za-z]:\\\\).*[\\\\/]~', '', $name);

        if ($name === '' || in_array($name, self::IGNORED_PROGRAMS, true) || preg_match('/^(?:mds|mdworker)/', $name) === 1) {
            return null;
        }

        return Str::limit($name, self::NAME_LIMIT, '…');
    }
}
