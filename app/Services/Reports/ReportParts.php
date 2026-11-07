<?php

declare(strict_types=1);

namespace App\Services\Reports;

/**
 * What the owner needs to buy parts that fit: the memory modules and slots and the internal drives.
 * Read from the parts step of the scan ("Memory modules" on Windows, "Memory and drives" on a Mac) and
 * from what older steps already record: the slots and maximum of the Windows 11 readiness step, the
 * drives of the Windows health step or the physical disks table, and the boot volume of a Mac.
 *
 * @phpstan-import-type Card from ReportDetails
 *
 * @phpstan-type Module array{slot: string, bytes: float|null, type: string|null, speed: int|null, form: string|null}
 * @phpstan-type PartsDrive array{media: string|null, bus: string|null, bytes: float|null}
 * @phpstan-type Fit 'socketed'|'soldered'|'lpddr'|'unified'|null
 * @phpstan-type Parts array{section: bool, modules: list<Module>, slots: int|null, free: int|null, maximumGb: int|null, installedGb: float|null, fit: Fit, unifiedType: string|null, drives: list<PartsDrive>, notes: list<string>}
 * @phpstan-type Readiness array{slots: int|null, used: int|null, socketed: bool, maximumGb: int|null, memoryGb: float|null}
 */
final readonly class ReportParts
{
    public const MAX_MODULES = 16;

    public const MAX_DRIVES = 8;

    public const SOCKETS = ['DIMM', 'SODIMM'];

    private const WINDOWS_SECTION = 'Memory modules';

    private const MAC_SECTION = 'Memory and drives';

    private const READINESS_SECTION = 'Windows 11 readiness';

    private const DISKS_SECTION = 'Storage (physical disks)';

    private const BOOT_SECTION = 'Storage (boot volume)';

    private const SLOT_LIMIT = 40;

    private const SANE_MAX_SLOTS = 64;

    private const SANE_MAX_MEMORY_GB = 8192;

    private const SANE_MAX_MODULE_BYTES = 512 * 1024 ** 3;

    private const MIN_SPEED_MHZ = 100;

    private const MAX_SPEED_MHZ = 20000;

    private const TYPE = '/^(?:LP)?DDR\d?[A-Z]?(?: ECC)?$/';

    private const BUS = '/^[A-Za-z0-9][A-Za-z0-9 .-]{0,19}$/';

    private const MEDIA = ['SSD', 'HDD'];

    private const UNKNOWN_BUS = 'unspecified';

    private const STATUS_NOTES = [
        'memory' => ['could not check' => 'The memory could not be read.', 'partial' => 'The memory scan stopped at its time limit, so a slot may be missing.'],
        'drives' => ['could not check' => 'The drives could not be read.', 'partial' => 'The drive scan stopped at its time limit, so a drive may be missing.'],
        'scan' => ['could not check' => 'The memory could not be read.'],
    ];

    /**
     * @return Card|null
     */
    public function card(ReportSections $sections): ?array
    {
        return (new ReportPartsCard)->card($this->read($sections));
    }

    /**
     * @return Parts
     */
    public function read(ReportSections $sections): array
    {
        $macText = $sections->text(self::MAC_SECTION);
        $windowsText = $sections->text(self::WINDOWS_SECTION);
        $lines = $this->lines($macText ?? $windowsText);
        $modules = $this->modules($lines);
        $installed = self::installed($modules);
        $readiness = $this->readiness($sections->text(self::READINESS_SECTION));
        $values = $this->values($lines);
        $mac = $macText !== null;
        $slots = $mac ? ($modules === [] ? null : count($modules)) : $readiness['slots'];
        $used = $mac || $installed !== [] ? count($installed) : $readiness['used'];
        $unifiedType = $values['memory-type'] ?? '';

        return [
            'section' => trim($macText ?? $windowsText ?? '') !== '',
            'modules' => $modules,
            'slots' => $slots,
            'free' => $slots === null || $used === null ? null : max($slots - $used, 0),
            'maximumGb' => $mac ? null : $readiness['maximumGb'],
            'installedGb' => $this->installedGb($installed, $readiness['memoryGb'], $sections->header('memory')),
            'fit' => $this->fit($installed, $values, $mac, $readiness['socketed']),
            'unifiedType' => preg_match(self::TYPE, $unifiedType) === 1 ? $unifiedType : null,
            'drives' => $mac ? $this->macDrives($lines) : $this->olderDrives($sections),
            'notes' => $this->notes($values),
        ];
    }

    /**
     * The type, form factor and speed the installed modules share, such as "DDR4 SODIMM, 2400 MHz".
     *
     * @param  list<Module>  $modules
     */
    public static function describe(array $modules): ?string
    {
        $installed = self::installed($modules);
        $types = array_values(array_unique(array_filter(array_column($installed, 'type'))));
        $forms = array_values(array_unique(array_filter(array_column($installed, 'form'))));
        $speeds = self::speeds($installed);
        $kind = trim(implode('/', $types).' '.implode('/', $forms));
        $speed = match (count($speeds)) {
            0 => '',
            1 => "{$speeds[0]} MHz",
            default => "{$speeds[0]} to ".$speeds[count($speeds) - 1].' MHz',
        };

        return implode(', ', array_filter([$kind, $speed])) ?: null;
    }

    /**
     * @param  list<Module>  $modules
     * @return list<Module>
     */
    public static function installed(array $modules): array
    {
        return array_values(array_filter($modules, fn (array $module): bool => $module['bytes'] !== null));
    }

    /**
     * @param  list<Module>  $modules
     * @return list<int>
     */
    public static function speeds(array $modules): array
    {
        $speeds = array_values(array_unique(array_filter(array_column(self::installed($modules), 'speed'))));
        sort($speeds);

        return $speeds;
    }

    /**
     * @return list<string>
     */
    private function lines(?string $text): array
    {
        return array_map('trim', preg_split('/\r\n|\r|\n/', (string) $text) ?: []);
    }

    /**
     * @param  list<string>  $lines
     * @return array<string, string>
     */
    private function values(array $lines): array
    {
        $values = [];

        foreach ($lines as $line) {
            if (preg_match('/^([a-z-]+):\s*(.*)$/', $line, $match) === 1) {
                $values[$match[1]] ??= trim($match[2]);
            }
        }

        return $values;
    }

    /**
     * @param  list<string>  $lines
     * @return list<Module>
     */
    private function modules(array $lines): array
    {
        $modules = [];

        foreach ($lines as $line) {
            $parts = explode('|', $line);

            if (count($parts) !== 6 || $parts[0] !== 'module' || count($modules) >= self::MAX_MODULES) {
                continue;
            }

            $empty = strtolower(trim($parts[2])) === 'empty';
            $bytes = $empty ? null : $this->moduleBytes($parts[2]);

            if (! $empty && $bytes === null) {
                continue;
            }

            $modules[] = [
                'slot' => $this->slot($parts[1]),
                'bytes' => $bytes,
                'type' => $empty || preg_match(self::TYPE, trim($parts[3])) !== 1 ? null : trim($parts[3]),
                'speed' => $empty ? null : $this->speed($parts[4]),
                'form' => in_array(trim($parts[5]), self::SOCKETS, true) && ! $empty ? trim($parts[5]) : null,
            ];
        }

        return $modules;
    }

    private function moduleBytes(string $size): ?float
    {
        $bytes = ByteSize::parse($size);

        return $bytes === null || $bytes <= 0 || $bytes > self::SANE_MAX_MODULE_BYTES ? null : $bytes;
    }

    private function slot(string $name): string
    {
        $clean = trim((string) preg_replace(['/[\x00-\x1f\x7f]/u', '/\s+/u'], ['', ' '], mb_scrub($name, 'UTF-8')));

        return trim(mb_substr($clean, 0, self::SLOT_LIMIT));
    }

    private function speed(string $speed): ?int
    {
        $mhz = preg_match('/^(\d{1,5}) MHz$/', trim($speed), $match) === 1 ? (int) $match[1] : 0;

        return $mhz >= self::MIN_SPEED_MHZ && $mhz <= self::MAX_SPEED_MHZ ? $mhz : null;
    }

    /**
     * @param  list<Module>  $installed
     */
    private function installedGb(array $installed, ?float $readinessGb, ?string $header): ?float
    {
        if ($installed !== []) {
            return (float) array_sum(array_column($installed, 'bytes')) / 1024 ** 3;
        }

        return $readinessGb ?? (new ReportSlowness)->ramGb($header);
    }

    /**
     * @param  list<Module>  $installed
     * @param  array<string, string>  $values
     * @return Fit
     */
    private function fit(array $installed, array $values, bool $mac, bool $socketedBefore): ?string
    {
        $types = array_filter(array_column($installed, 'type'));
        $forms = array_column($installed, 'form');
        $upgradeable = strtolower($values['memory-upgradeable'] ?? '');

        return match (true) {
            $mac && strtolower($values['memory-unified'] ?? '') === 'yes' => 'unified',
            $mac && $upgradeable === 'no' => 'soldered',
            array_filter($types, fn (string $type): bool => str_starts_with($type, 'LP')) !== [] => 'lpddr',
            $mac => $upgradeable === 'yes' ? 'socketed' : null,
            $installed !== [] => in_array(null, $forms, true) ? null : 'socketed',
            default => $socketedBefore ? 'socketed' : null,
        };
    }

    /**
     * @return Readiness
     */
    private function readiness(?string $text): array
    {
        $lines = [];

        foreach ($this->lines($text) as $line) {
            if (preg_match('/^([A-Za-z ]+?):\s*(.*)$/', $line, $match) === 1) {
                $lines[strtolower($match[1])] ??= trim($match[2]);
            }
        }

        $slots = preg_match('/^(\d{1,3}) total, (\d{1,3}) used$/', $lines['memory slots'] ?? '', $match) === 1 ? [(int) $match[1], (int) $match[2]] : null;
        $sane = $slots !== null && $slots[0] > 0 && $slots[0] <= self::SANE_MAX_SLOTS && $slots[1] <= $slots[0];
        $maximum = preg_match('/^(\d{1,5}) GB$/', $lines['memory maximum'] ?? '', $found) === 1 ? (int) $found[1] : 0;

        return [
            'slots' => $sane ? $slots[0] : null,
            'used' => $sane ? $slots[1] : null,
            'socketed' => ($lines['memory form'] ?? '') === 'socketed',
            'maximumGb' => $maximum > 0 && $maximum <= self::SANE_MAX_MEMORY_GB ? $maximum : null,
            'memoryGb' => (new ReportSlowness)->ramGb($lines['memory'] ?? null),
        ];
    }

    /**
     * @param  list<string>  $lines
     * @return list<PartsDrive>
     */
    private function macDrives(array $lines): array
    {
        $drives = [];

        foreach ($lines as $line) {
            $parts = explode('|', $line);

            if (count($parts) === 4 && $parts[0] === 'drive') {
                $drives[] = $this->drive(trim($parts[1]), trim($parts[2]), ByteSize::parse($parts[3]));
            }
        }

        return $this->usable($drives);
    }

    /**
     * The drives older steps record: the Windows health step, else the Windows physical disks table,
     * else the boot volume of a Mac.
     *
     * @return list<PartsDrive>
     */
    private function olderDrives(ReportSections $sections): array
    {
        $health = (new ReportWindowsHealth)->read($sections)['drives'] ?? [];
        $drives = array_map(fn (array $drive): array => $this->drive((string) $drive['media'], (string) $drive['bus'], $drive['bytes']), $health);

        if ($drives === []) {
            foreach ($this->lines($sections->text(self::DISKS_SECTION)) as $line) {
                if (preg_match('/\s(SSD|HDD|SCM|Unspecified)\s+(\S+)\s+(\d+)\s+(\S+)\s+\S.*$/', $line, $match) === 1) {
                    $drives[] = $this->drive($match[1], $match[2], ByteSize::parse("{$match[3]} GB"));
                }
            }
        }

        return $this->usable($drives === [] ? $this->bootVolume($sections->text(self::BOOT_SECTION)) : $drives);
    }

    /**
     * @return list<PartsDrive>
     */
    private function bootVolume(?string $text): array
    {
        $solid = preg_match('/^\s*Solid State:\s*(Yes|No)\s*$/mi', (string) $text, $match) === 1 ? strtolower($match[1]) : null;
        $protocol = preg_match('/^\s*Protocol:\s*(\S.*?)\s*$/mi', (string) $text, $found) === 1 ? $found[1] : '';

        if ($solid === null) {
            return [];
        }

        $bus = match (true) {
            stripos($protocol, 'PCI') === 0 => 'PCIe',
            strcasecmp($protocol, 'SATA') === 0 => 'SATA',
            strcasecmp($protocol, 'NVMe') === 0 => 'NVMe',
            default => '',
        };

        return [$this->drive($solid === 'yes' ? 'SSD' : 'HDD', $bus, null)];
    }

    /**
     * @return PartsDrive
     */
    private function drive(string $media, string $bus, ?float $bytes): array
    {
        $bus = trim($bus);

        return [
            'media' => in_array(strtoupper($media), self::MEDIA, true) ? strtoupper($media) : null,
            'bus' => preg_match(self::BUS, $bus) === 1 && strtolower($bus) !== self::UNKNOWN_BUS ? $bus : null,
            'bytes' => $bytes !== null && $bytes > 0 ? $bytes : null,
        ];
    }

    /**
     * @param  list<PartsDrive>  $drives
     * @return list<PartsDrive>
     */
    private function usable(array $drives): array
    {
        $internal = array_filter($drives, fn (array $drive): bool => strtoupper((string) $drive['bus']) !== 'USB' && ($drive['media'] !== null || $drive['bus'] !== null || $drive['bytes'] !== null));

        return array_slice(array_values($internal), 0, self::MAX_DRIVES);
    }

    /**
     * @param  array<string, string>  $values
     * @return list<string>
     */
    private function notes(array $values): array
    {
        $notes = [];

        foreach (self::STATUS_NOTES as $key => $meanings) {
            $status = strtolower($values[$key] ?? '');
            $meaning = $meanings[str_starts_with($status, 'partial') ? 'partial' : $status] ?? null;

            if ($meaning !== null && ! in_array($meaning, $notes, true)) {
                $notes[] = $meaning;
            }
        }

        return $notes;
    }
}
