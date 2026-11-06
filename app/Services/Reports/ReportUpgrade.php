<?php

declare(strict_types=1);

namespace App\Services\Reports;

/**
 * @phpstan-import-type Card from ReportDetails
 * @phpstan-import-type Row from ReportDetails
 *
 * @phpstan-type Facts array{firmware: string|null, secureBoot: string|null, tpm: string|null, tpmVersion: string|null, tpmReady: string|null, cpu: string|null, cpuMaker: string|null, ram: int|null, disk: int|null, slots: array{total: int, used: int}|null, socketed: bool, maximum: int|null}
 * @phpstan-type Finding array{label: string, value: string}
 */
final readonly class ReportUpgrade
{
    private const SECTION = 'Windows 11 readiness';

    private const MIN_RAM_GB = 4;

    private const MIN_DISK_GB = 64;

    private const MIN_TPM_VERSION = '2.0';

    private const FIRST_WINDOWS_11_BUILD = 22000;

    private const FIRST_WINDOWS_10_BUILD = 10240;

    private const SANE_MAX_GB = 8192;

    private const SANE_MAX_SLOTS = 64;

    private const YES = 'Yes';

    private const PROBABLY_YES = 'Probably yes';

    private const NOT_AS_IT_IS = 'Not as it is';

    private const NO = 'No';

    private const CANNOT_TELL = 'Cannot tell';

    private const NOTE = 'A rule of thumb from this scan, made without Microsoft\'s list of supported processors. Microsoft\'s PC Health Check app has the final word.';

    /**
     * @return Card|null
     */
    public function card(ReportSections $sections): ?array
    {
        $text = $sections->text(self::SECTION);

        if ($text === null || ! $this->isWindows10($sections)) {
            return null;
        }

        $facts = $this->facts($text);
        [$verdict, $tone, $findings] = $this->decide($facts);
        $rows = [
            ['label' => 'Verdict', 'value' => $verdict, 'tone' => $tone],
            ...array_map(fn (array $finding): array => ['label' => $finding['label'], 'value' => $finding['value'], 'tone' => $tone === 'bad' ? 'bad' : 'neutral'], $findings),
            ...$this->upgradeRow($facts),
            ['label' => 'Good to know', 'value' => self::NOTE, 'tone' => 'neutral'],
        ];

        return ['id' => 'upgrade', 'title' => 'Can this PC run Windows 11?', 'rows' => $rows, 'list' => null];
    }

    private function isWindows10(ReportSections $sections): bool
    {
        $windows = (string) $sections->header('windows');

        if (preg_match('/\bServer\b/i', $windows) === 1) {
            return false;
        }

        if (preg_match('/\(build (\d+)/i', $windows, $match) === 1) {
            return (int) $match[1] >= self::FIRST_WINDOWS_10_BUILD && (int) $match[1] < self::FIRST_WINDOWS_11_BUILD;
        }

        return preg_match('/\bWindows 10\b/i', $windows) === 1;
    }

    /**
     * @return Facts
     */
    private function facts(string $text): array
    {
        $lines = [];

        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^([A-Za-z0-9 ]+?):\s*(\S.*?)\s*$/', $line, $match) === 1) {
                $lines[strtolower($match[1])] ??= $match[2];
            }
        }

        $read = fn (string $key): ?string => isset($lines[$key]) && stripos($lines[$key], 'could not check') !== 0 ? $lines[$key] : null;
        $slots = preg_match('/^(\d+) total, (\d+) used$/', (string) $read('memory slots'), $slotMatch) === 1 ? ['total' => (int) $slotMatch[1], 'used' => (int) $slotMatch[2]] : null;

        return [
            'firmware' => $read('firmware'),
            'secureBoot' => $read('secure boot'),
            'tpm' => $read('tpm'),
            'tpmVersion' => preg_match('/^\d\.\d$/', (string) $read('tpm version')) === 1 ? $read('tpm version') : null,
            'tpmReady' => $read('tpm ready'),
            'cpu' => $read('cpu'),
            'cpuMaker' => $read('cpu maker'),
            'ram' => $this->gigabytes($read('memory')),
            'disk' => $this->gigabytes($read('system disk')),
            'slots' => $slots !== null && $slots['total'] > 0 && $slots['total'] <= self::SANE_MAX_SLOTS && $slots['used'] <= $slots['total'] ? $slots : null,
            'socketed' => $read('memory form') === 'socketed',
            'maximum' => $this->gigabytes($read('memory maximum')),
        ];
    }

    private function gigabytes(?string $value): ?int
    {
        if (preg_match('/^(\d{1,5}) GB$/', (string) $value, $match) !== 1) {
            return null;
        }

        $amount = (int) $match[1];

        return $amount > 0 && $amount <= self::SANE_MAX_GB ? $amount : null;
    }

    /**
     * @param  Facts  $facts
     * @return array{string, 'good'|'bad'|'neutral', list<Finding>}
     */
    private function decide(array $facts): array
    {
        $cpu = CpuGeneration::judge($facts['cpu'], $facts['cpuMaker']);
        $blockers = [];
        $fixes = [];
        $unknowns = [];
        $tpmUnknown = $facts['tpm'] === null || ($facts['tpm'] === 'present' && $facts['tpmVersion'] === null);
        $secureUnknown = $facts['secureBoot'] === null;

        if ($cpu === CpuGeneration::OLD) {
            $blockers[] = $this->finding('The processor is too old', 'Windows 11 needs an Intel Core of the '.CpuGeneration::INTEL_MIN_GENERATION.'th generation or newer, or an AMD Ryzen of the '.CpuGeneration::AMD_MIN_RYZEN_SERIES.'000 series or newer. This one is older, and a processor cannot be swapped in most PCs.');
        }

        if ($cpu === CpuGeneration::UNKNOWN) {
            $unknowns[] = $this->finding('The processor could not be judged', 'Microsoft decides per processor model. PC Health Check can tell for sure.');
        }

        $this->tpm($facts, $cpu, $blockers, $fixes, $unknowns);

        if ($facts['firmware'] === 'BIOS (legacy)' || $facts['secureBoot'] === 'not supported (legacy BIOS)') {
            $fixes[] = $this->finding('The PC starts in legacy BIOS mode', 'Windows 11 needs UEFI with Secure Boot. A technician can convert the disk (MBR2GPT) and switch the BIOS to UEFI.');
        } elseif ($facts['secureBoot'] === 'off') {
            $fixes[] = $this->finding('Secure Boot is off', 'Turn it on in the BIOS or UEFI settings.');
        }

        if ($facts['ram'] !== null && $facts['ram'] < self::MIN_RAM_GB) {
            $fixes[] = $this->finding("Only {$facts['ram']} GB of memory", 'Windows 11 needs at least '.self::MIN_RAM_GB.' GB. Add memory if this model allows it.');
        }

        if ($facts['disk'] !== null && $facts['disk'] < self::MIN_DISK_GB) {
            $fixes[] = $this->finding("The system drive is only {$facts['disk']} GB", 'Windows 11 needs at least '.self::MIN_DISK_GB.' GB. Fit a bigger drive.');
        }

        if ($tpmUnknown || $secureUnknown) {
            $unknowns[] = $this->finding('Part of the security check could not be read', 'Run the scan again as administrator, or let PC Health Check look at it.');
        }

        $gaps = $facts['ram'] === null || $facts['disk'] === null || $tpmUnknown || $secureUnknown;

        return match (true) {
            $blockers !== [] => [self::NO, 'bad', [...$blockers, ...$fixes]],
            $fixes !== [] => [self::NOT_AS_IT_IS, 'neutral', [...$fixes, ...$unknowns]],
            $cpu === CpuGeneration::UNKNOWN || ($tpmUnknown && $secureUnknown) => [self::CANNOT_TELL, 'neutral', $unknowns],
            $gaps => [self::PROBABLY_YES, 'good', $unknowns],
            default => [self::YES, 'good', []],
        };
    }

    /**
     * @param  Facts  $facts
     * @param  list<Finding>  $blockers
     * @param  list<Finding>  $fixes
     * @param  list<Finding>  $unknowns
     */
    private function tpm(array $facts, string $cpu, array &$blockers, array &$fixes, array &$unknowns): void
    {
        $oldChip = $facts['tpm'] === 'present' && $facts['tpmVersion'] !== null && version_compare($facts['tpmVersion'], self::MIN_TPM_VERSION, '<');

        if ($oldChip) {
            $blockers[] = $this->finding('The security chip (TPM) is too old', 'It is version '.$facts['tpmVersion'].'. Windows 11 needs TPM '.self::MIN_TPM_VERSION.', and a chip of this age usually cannot be upgraded.');
        } elseif ($facts['tpm'] === 'present' && $facts['tpmReady'] === 'no') {
            $fixes[] = $this->finding('The security chip (TPM) is switched off', 'Turn it on in the BIOS or UEFI settings.');
        } elseif ($facts['tpm'] === 'not found' && $cpu === CpuGeneration::OK) {
            $fixes[] = $this->finding('No security chip (TPM) was found', 'A PC with a processor this new usually has one built in. Turn it on in the BIOS (called PTT on Intel, fTPM on AMD).');
        } elseif ($facts['tpm'] === 'not found') {
            $unknowns[] = $this->finding('No security chip (TPM) was found', 'Check the BIOS for a setting called TPM, PTT or fTPM.');
        }
    }

    /**
     * @return Finding
     */
    private function finding(string $label, string $value): array
    {
        return ['label' => $label, 'value' => $value];
    }

    /**
     * @param  Facts  $facts
     * @return list<Row>
     */
    private function upgradeRow(array $facts): array
    {
        $slots = $facts['slots'];

        if ($slots === null || ! $facts['socketed']) {
            return [];
        }

        $free = $slots['total'] - $slots['used'];
        $room = $facts['maximum'] !== null && ($facts['ram'] === null || $facts['maximum'] > $facts['ram']);
        $upTo = $room ? ", up to {$facts['maximum']} GB" : '';

        $value = match (true) {
            $free > 0 => "RAM can be expanded: {$free} of {$slots['total']} ".($slots['total'] === 1 ? 'slot' : 'slots').' free'.$upTo,
            $room => "RAM slots: all {$slots['total']} in use, bigger modules would fit{$upTo}",
            default => null,
        };

        return $value === null ? [] : [['label' => 'Upgrade options', 'value' => $value, 'tone' => 'neutral']];
    }
}
