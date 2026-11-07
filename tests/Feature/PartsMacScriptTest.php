<?php

declare(strict_types=1);

use App\Services\Reports\ReportParts;
use App\Services\Reports\ReportSections;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Tests\Support\ScriptTemp;

function pmsMissing(): bool
{
    return PHP_OS_FAMILY === 'Windows' || trim((string) shell_exec('command -v perl 2>/dev/null')) === '';
}

function pmsIMacMemory(): string
{
    return <<<'TXT'
Memory:

    Memory Slots:

      ECC: Disabled
      Upgradeable Memory: Yes

        DIMM1/BANK 0:

          Size: 8 GB
          Type: DDR4
          Speed: 2667 MHz
          Status: OK
          Manufacturer: 0x802C
          Part Number: 0x3136415446314736344853422D3236363645312020
          Serial Number: 0x1A2B3C4D

        DIMM2/BANK 0:

          Size: Empty
          Type: Empty
          Speed: Empty
          Status: Empty
          Manufacturer: Empty
          Part Number: Empty
          Serial Number: Empty

TXT;
}

function pmsSolderedMemory(): string
{
    return <<<'TXT'
Memory:

    Memory Slots:

      ECC: Disabled
      Upgradeable Memory: No

        BANK 0/DIMM0:

          Size: 4 GB
          Type: LPDDR3
          Speed: 2133 MHz
          Status: OK
          Manufacturer: 0x80CE
          Part Number: -
          Serial Number: -

TXT;
}

function pmsAppleSiliconMemory(): string
{
    return <<<'TXT'
Memory:

      Memory: 16 GB
      Type: LPDDR5
      Manufacturer: Hynix

TXT;
}

function pmsDrives(): string
{
    return <<<'TXT'
NVMExpress:

    Apple SSD Controller:

        APPLE SSD AP0256M:

          Capacity: 251 GB (251.000.193.024 bytes)
          TRIM Support: Yes
          Model: APPLE SSD AP0256M
          Revision: 1161.80.
          Serial Number: C02SECRETSSD
          Link Width: x4
          Link Speed: 8.0 GT/s
          Detachable Drive: No
          BSD Name: disk0
          Partition Map Type: GPT (GUID Partition Table)
          Removable Media: No
          Volumes:
            EFI:
              Capacity: 314,6 MB (314.572.800 bytes)
              File System: MS-DOS FAT32
              BSD Name: disk0s1
              Volume UUID: 0E239BC6-F960-3107-89CF-1C97F78BB46B
            Macintosh HD:
              Capacity: 250,69 GB (250.685.575.168 bytes)
              BSD Name: disk0s2

SATA/SATA Express:

    Intel 7 Series Chipset:

      Vendor: Intel
      Product: 7 Series Chipset
      Link Speed: 6 Gigabit
      Negotiated Link Speed: 6 Gigabit
      Physical Interconnect: SATA
      Description: AHCI Version 1.30 Supported

        APPLE HDD ST1000DM003:

          Capacity: 1 TB (1.000.204.886.016 bytes)
          Model: APPLE HDD ST1000DM003
          Revision: AQ04
          Serial Number: Z1DSECRETHDD
          Native Command Queuing: Yes
          Removable Media: No
          Detachable Drive: No
          BSD Name: disk1
          Rotational Rate: 7200
          Medium Type: Rotational
          S.M.A.R.T. status: Verified
          Volumes:
            EFI:
              Capacity: 209,7 MB (209.715.200 bytes)

        HL-DT-ST DVDRW  GA32N:

          Capacity: 4,7 GB (4.700.000.000 bytes)
          Model: HL-DT-ST DVDRW  GA32N
          Serial Number: SECRETDVD
          Removable Media: Yes
          Detachable Drive: No

    AHCI Controller:

      Physical Interconnect: PCI

        APPLE SSD SM0128G:

          Capacity: 121,33 GB (121.332.826.112 bytes)
          Model: APPLE SSD SM0128G
          Medium Type: Solid State
          Removable Media: No
          Detachable Drive: No

        Thunder Box:

          Capacity: 2 TB (2.000.000.000.000 bytes)
          Model: External Box
          Medium Type: Rotational
          Detachable Drive: Yes

TXT;
}

/**
 * @param  array<string, string>  $env
 */
function pmsReport(string $memory, string $drives, int $memoryExit = 0, int $drivesExit = 0, array $env = []): ProcessResult
{
    $dir = ScriptTemp::dir('pcc-pms-out');
    file_put_contents($dir.'/memory.txt', $memory);
    file_put_contents($dir.'/drives.txt', $drives);
    $body = "case \"\$1\" in\n"
        .'SPMemoryDataType) cat '.escapeshellarg($dir.'/memory.txt')."; exit {$memoryExit} ;;\n"
        .'SPNVMeDataType) [ "$2" = SPSerialATADataType ] || exit 3; cat '.escapeshellarg($dir.'/drives.txt')."; exit {$drivesExit} ;;\n"
        ."*) exit 4 ;;\nesac";
    $bin = ScriptTemp::bin('pcc-pms-bin', ['system_profiler' => $body]);

    return Process::path(base_path())->timeout(60)->env(array_merge(['PATH' => $bin.':'.getenv('PATH')], $env))
        ->run(['bash', '-c', 'source scripts/mac-checkup.sh; pcc_parts_report']);
}

/**
 * @return list<string>
 */
function pmsLines(ProcessResult $result): array
{
    return array_values(array_filter(explode("\n", trim($result->throw()->output())), fn (string $line): bool => $line !== ''));
}

describe('the Mac memory and drives step', function () {
    it('prints whether memory can be upgraded, each slot, and each internal drive', function () {
        expect(pmsLines(pmsReport(pmsIMacMemory(), pmsDrives())))->toBe([
            'format: 1',
            'memory: complete',
            'memory-upgradeable: yes',
            'module|DIMM1/BANK 0|8 GB|DDR4|2667 MHz|',
            'module|DIMM2/BANK 0|empty|||',
            'drives: complete',
            'drive|SSD|NVMe|251 GB',
            'drive|HDD|SATA|1 TB',
            'drive|SSD|PCIe|121,33 GB',
        ]);
    })->skip(pmsMissing(), 'perl is not installed here');

    it('never prints a serial, a part number, a maker or a drive name', function () {
        $output = pmsReport(pmsIMacMemory(), pmsDrives())->throw()->output().pmsReport(pmsAppleSiliconMemory(), '')->throw()->output();

        foreach (['0x802C', '0x3136', '0x1A2B', 'SECRET', 'APPLE', 'ST1000', 'Hynix', 'Intel', 'DVD', 'External', 'disk0', 'UUID'] as $leak) {
            expect($output)->not->toContain($leak);
        }
    })->skip(pmsMissing(), 'perl is not installed here');

    it('says the memory of an older MacBook is not upgradeable', function () {
        expect(pmsLines(pmsReport(pmsSolderedMemory(), '')))->toBe([
            'format: 1', 'memory: complete', 'memory-upgradeable: no', 'module|BANK 0/DIMM0|4 GB|LPDDR3|2133 MHz|', 'drives: complete',
        ]);
    })->skip(pmsMissing(), 'perl is not installed here');

    it('says Apple silicon has unified memory and names its type', function () {
        expect(pmsLines(pmsReport(pmsAppleSiliconMemory(), '')))->toBe(['format: 1', 'memory: complete', 'memory-unified: yes', 'memory-type: LPDDR5', 'drives: complete']);
    })->skip(pmsMissing(), 'perl is not installed here');

    it('cleans slot names and drops values that do not have the expected shape', function () {
        $memory = "Memory:\n\n    Memory Slots:\n\n      Upgradeable Memory: Maybe\n\n        Sl|ot\t".str_repeat('X', 60).":\n\n          Size: 8 GB; rm -rf\n          Type: <b>DDR4</b>\n          Speed: fast\n\n        Caf\u{e9}:\n\n          Size: 4,5 GB\n          Type: DDR3 ECC\n          Speed: 1066 MHz\n";

        expect(pmsLines(pmsReport($memory, '')))->toBe([
            'format: 1', 'memory: complete', 'module|Slot '.str_repeat('X', 35).'||||', "module|Caf\u{e9}|4,5 GB|DDR3 ECC|1066 MHz|", 'drives: complete',
        ]);
    })->skip(pmsMissing(), 'perl is not installed here');

    it('lists at most sixteen slots and eight drives', function () {
        $memory = "Memory:\n\n    Memory Slots:\n\n      Upgradeable Memory: Yes\n\n";
        $drives = "SATA/SATA Express:\n\n    Controller:\n\n";

        foreach (range(1, 20) as $slot) {
            $memory .= "        DIMM{$slot}:\n\n          Size: 4 GB\n          Type: DDR4\n          Speed: 2400 MHz\n\n";
            $drives .= "        Disk {$slot}:\n\n          Capacity: {$slot} GB ({$slot}.000.000.000 bytes)\n          Model: Disk {$slot}\n          Medium Type: Solid State\n\n";
        }

        $lines = pmsLines(pmsReport($memory, $drives));

        expect(array_filter($lines, fn (string $line): bool => str_starts_with($line, 'module|')))->toHaveCount(ReportParts::MAX_MODULES)
            ->and(array_filter($lines, fn (string $line): bool => str_starts_with($line, 'drive|')))->toHaveCount(ReportParts::MAX_DRIVES);
    })->skip(pmsMissing(), 'perl is not installed here');

    it('takes the connection a drive states itself before the one of its controller', function () {
        $drives = "SATA/SATA Express:\n\n    Apple SSD Controller:\n\n      Vendor: Apple\n\n        APPLE SSD SM0256G:\n\n          Capacity: 251 GB (251.000.193.024 bytes)\n          Model: APPLE SSD SM0256G\n          Physical Interconnect: PCI\n          Medium Type: Solid State\n\n    Plain Controller:\n\n        WDC WD10:\n\n          Capacity: 1 TB (1.000.204.886.016 bytes)\n          Model: WDC WD10\n          Medium Type: Rotational\n";

        expect(array_values(array_filter(pmsLines(pmsReport('', $drives)), fn (string $line): bool => str_starts_with($line, 'drive|'))))
            ->toBe(['drive|SSD|PCIe|251 GB', 'drive|HDD|SATA|1 TB']);
    })->skip(pmsMissing(), 'perl is not installed here');

    it('says it could not check when system_profiler fails', function () {
        expect(pmsLines(pmsReport(pmsIMacMemory(), pmsDrives(), 1, 1)))->toBe(['format: 1', 'memory: could not check', 'drives: could not check']);
    })->skip(pmsMissing(), 'perl is not installed here');

    it('says nothing about a part it found no data for', function () {
        expect(pmsLines(pmsReport('', '')))->toBe(['format: 1', 'memory: complete', 'drives: complete']);
    })->skip(pmsMissing(), 'perl is not installed here');

    it('flags a partial result at the time cap and leaves no process behind', function () {
        $bin = ScriptTemp::bin('pcc-pms-slow', ['system_profiler' => 'sleep 30']);
        $started = microtime(true);
        $result = Process::path(base_path())->timeout(60)->env(['PATH' => $bin.':'.getenv('PATH'), 'PCC_PARTS_CAP' => '1'])
            ->run(['bash', '-c', 'source scripts/mac-checkup.sh; pcc_parts_report']);

        expect(pmsLines($result))->toBe(['format: 1', 'memory: partial (time limit of 1 seconds reached)', 'drives: partial (time limit of 1 seconds reached)'])
            ->and(microtime(true) - $started)->toBeLessThan(15.0);
    })->skip(pmsMissing(), 'perl is not installed here');

    it('prints lines the app reads into the parts card', function () {
        $markdown = "# Mac checkup: X\n\n- OS: macOS\n- Memory: 8 GB\n\n## Memory and drives\n\n```\n".implode("\n", pmsLines(pmsReport(pmsIMacMemory(), pmsDrives())))."\n```\n";
        $rows = array_column((new ReportParts)->card(ReportSections::parse($markdown))['rows'] ?? [], 'value', 'label');

        expect($rows)->toMatchArray([
            'Upgradeable' => 'Yes, 1 free slot',
            'DIMM1/BANK 0' => '8 GB',
            'DIMM2/BANK 0' => 'Empty',
            'Drive 1' => 'NVMe SSD, 251 GB',
            'Drive 2' => 'SATA hard drive (HDD), 1 TB',
            'Drive 3' => 'PCIe SSD, 121 GB',
        ]);
    })->skip(pmsMissing(), 'perl is not installed here');

    it('reads only the two system reports, time-boxed, and is a section of the report', function () {
        $script = (string) file_get_contents(base_path('scripts/mac-checkup.sh'));
        preg_match('/^pcc_parts_report\(\) \{.*?^\}$/ms', $script, $match);

        expect($match[0] ?? '')->toContain('pcc_capped "$PCC_PARTS_CAP" system_profiler SPMemoryDataType')
            ->toContain('pcc_capped "$PCC_PARTS_CAP" system_profiler SPNVMeDataType SPSerialATADataType')
            ->not->toContain('ioreg')->not->toContain('diskutil')
            ->and($script)->toContain('section "Memory and drives" pcc_parts_report');
    });
});
