<?php

declare(strict_types=1);

use App\Services\Reports\ReportParts;
use App\Services\Reports\ReportSections;
use Illuminate\Support\Facades\Process;
use Tests\Support\ScriptTemp;

function pwsMissing(): bool
{
    return trim((string) shell_exec('command -v pwsh 2>/dev/null')) === '' && trim((string) shell_exec('command -v powershell.exe 2>/dev/null')) === '';
}

function pwsScript(): string
{
    return str_replace("\r\n", "\n", (string) file_get_contents(base_path('scripts/windows-checkup.ps1')));
}

function pwsFunction(string $name): string
{
    preg_match('/^function '.preg_quote($name, '/').'\b.*?^\}$/ms', pwsScript(), $match);

    expect($match)->not->toBeEmpty();

    return $match[0];
}

/**
 * @return list<string>
 */
function pwsLines(string $case): array
{
    $dir = ScriptTemp::dir('pcc-pws');
    file_put_contents($dir.'/step.ps1', "param([string]\$Case)\n".pwsFunction('Get-MemoryModuleLines')."\n".<<<'PS'
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
function Module($Locator, $Bank, $Capacity, $Type, $Speed, $Form, $Old = $null, $Configured = $null) {
    [pscustomobject]@{ DeviceLocator = $Locator; BankLabel = $Bank; Capacity = $Capacity; SMBIOSMemoryType = $Type; MemoryType = $Old; Speed = $Speed; ConfiguredClockSpeed = $Configured; FormFactor = $Form; SerialNumber = 'SERIAL-0042-SECRET'; PartNumber = 'M471A1K43CB1-CTD'; Manufacturer = 'Samsung' }
}
switch ($Case) {
    'modules' { Get-MemoryModuleLines @((Module 'DDR5-A2' 'BANK 0' 17179869184 34 4800 8), (Module 'ChannelB-DIMM0' 'BANK 2' 8589934592 26 2667 12)) $null }
    'older' { Get-MemoryModuleLines @((Module 'DIMM0' 'BANK 0' 2147483648 0 0 0 24 1333), (Module 'DIMM1' 'BANK 1' 536870912 $null $null $null 21 $null)) $null }
    'lpddr' { Get-MemoryModuleLines @((Module 'Controller0-ChannelA' 'BANK 0' 8589934592 30 4267 12), (Module 'X' 'B' 8589934592 35 6400 0)) $null }
    'dirty' { Get-MemoryModuleLines @((Module "We`tir|d`nslot" 'BANK 0' 4294967296 26 2400 12), (Module ('L' * 60) 'BANK 1' 4294967296 26 2400 12), (Module $null 'BANK 2' 4294967296 26 2400 12), (Module '   ' $null 4294967296 26 2400 12), (Module "Caf$([char]0xE9)" 'B' 4294967296 26 2400 12)) $null }
    'skips' { Get-MemoryModuleLines @((Module 'Zero' 'B' 0 26 2400 12), (Module 'Null' 'B' $null 26 2400 12), (Module 'Text' 'B' 'lots' 26 2400 12), (Module 'Minus' 'B' -4294967296 26 2400 12), (Module 'Fine' 'B' 4294967296 26 2400 12), $null) $null }
    'odd' { Get-MemoryModuleLines @((Module 'A' 'B' 4294967296 99 99999 3), (Module 'C' 'D' 4294967296 'DDR4' 'fast' 'socket')) $null }
    'many' { Get-MemoryModuleLines @(1..24 | ForEach-Object { Module "DIMM$_" 'B' 4294967296 26 2400 8 }) $null }
    'none' { Get-MemoryModuleLines @() $null }
    'null' { Get-MemoryModuleLines $null $null }
    'failed' { Get-MemoryModuleLines @((Module 'A' 'B' 4294967296 26 2400 8)) 'took longer than 25 seconds' }
}
PS);

    $pwsh = trim((string) shell_exec('command -v pwsh 2>/dev/null'));
    $windows = trim((string) shell_exec('command -v powershell.exe 2>/dev/null'));
    $convert = fn (string $path): string => $pwsh !== '' ? $path : trim((string) shell_exec('wslpath -w '.escapeshellarg($path)));
    $command = $pwsh !== '' ? [$pwsh, '-NoProfile', '-File'] : [$windows, '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File'];
    $result = Process::timeout(120)->run([...$command, $convert($dir.'/step.ps1'), $case])->throw();

    return array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", '', $result->output()))), fn (string $line): bool => $line !== ''));
}

describe('the memory modules step in the Windows script', function () {
    it('prints slot, size, type, speed and form factor of each module', function () {
        expect(pwsLines('modules'))->toBe([
            'format: 1',
            'scan: complete',
            'module|DDR5-A2|16384 MB|DDR5|4800 MHz|DIMM',
            'module|ChannelB-DIMM0|8192 MB|DDR4|2667 MHz|SODIMM',
        ]);
    })->skip(pwsMissing(), 'PowerShell is not installed here');

    it('never prints a serial number, a part number or the maker', function () {
        expect(implode("\n", pwsLines('modules')))->not->toContain('SERIAL')->not->toContain('M471')->not->toContain('Samsung');
    })->skip(pwsMissing(), 'PowerShell is not installed here');

    it('falls back to the older memory type and the configured speed, and to the bank label', function () {
        expect(pwsLines('older'))->toBe([
            'format: 1',
            'scan: complete',
            'module|DIMM0|2048 MB|DDR3|1333 MHz|',
            'module|DIMM1|512 MB|DDR2||',
        ]);
    })->skip(pwsMissing(), 'PowerShell is not installed here');

    it('names LPDDR memory so the app knows it is soldered', function () {
        expect(pwsLines('lpddr'))->toBe([
            'format: 1',
            'scan: complete',
            'module|Controller0-ChannelA|8192 MB|LPDDR4|4267 MHz|SODIMM',
            'module|X|8192 MB|LPDDR5|6400 MHz|',
        ]);
    })->skip(pwsMissing(), 'PowerShell is not installed here');

    it('cleans slot names of control characters and pipes, caps their length and keeps unicode', function () {
        $lines = pwsLines('dirty');

        expect($lines[2])->toBe('module|We ir d slot|4096 MB|DDR4|2400 MHz|SODIMM')
            ->and($lines[3])->toBe('module|'.str_repeat('L', 40).'|4096 MB|DDR4|2400 MHz|SODIMM')
            ->and($lines[4])->toBe('module|BANK 2|4096 MB|DDR4|2400 MHz|SODIMM')
            ->and($lines[5])->toBe('module||4096 MB|DDR4|2400 MHz|SODIMM')
            ->and($lines[6])->toBe("module|Caf\u{e9}|4096 MB|DDR4|2400 MHz|SODIMM");
    })->skip(pwsMissing(), 'PowerShell is not installed here');

    it('skips empty, unreadable and negative capacities', function () {
        expect(pwsLines('skips'))->toBe(['format: 1', 'scan: complete', 'module|Fine|4096 MB|DDR4|2400 MHz|SODIMM']);
    })->skip(pwsMissing(), 'PowerShell is not installed here');

    it('leaves out a type, speed or form factor it does not know', function () {
        expect(pwsLines('odd'))->toBe(['format: 1', 'scan: complete', 'module|A|4096 MB|||', 'module|C|4096 MB|||']);
    })->skip(pwsMissing(), 'PowerShell is not installed here');

    it('lists at most sixteen modules', function () {
        $modules = array_filter(pwsLines('many'), fn (string $line): bool => str_starts_with($line, 'module|'));

        expect($modules)->toHaveCount(ReportParts::MAX_MODULES);
    })->skip(pwsMissing(), 'PowerShell is not installed here');

    it('says the scan was complete when it found no module', function (string $case) {
        expect(pwsLines($case))->toBe(['format: 1', 'scan: complete']);
    })->with(['none', 'null'])->skip(pwsMissing(), 'PowerShell is not installed here');

    it('says it could not check when the query failed or timed out', function () {
        expect(pwsLines('failed'))->toBe(['format: 1', 'scan: could not check']);
    })->skip(pwsMissing(), 'PowerShell is not installed here');

    it('prints lines the app reads into the parts card', function () {
        $markdown = "# Windows checkup: X\n\n- OS: Windows\n\n## Memory modules\n\n```\n".implode("\n", pwsLines('modules'))."\n```\n";
        $card = (new ReportParts)->card(ReportSections::parse($markdown));

        expect(array_column($card['rows'] ?? [], 'value', 'label'))->toMatchArray(['DDR5-A2' => '16 GB, 4800 MHz', 'ChannelB-DIMM0' => '8 GB, 2667 MHz']);
    })->skip(pwsMissing(), 'PowerShell is not installed here');

    it('reads the modules once, time-boxed, and hands only the needed fields out of the job', function () {
        preg_match("/^Add-Section 'Memory modules' \\{.*?^\\}$/ms", pwsScript(), $section);
        $block = $section[0] ?? '';

        expect($block)->toContain('Read-CappedJob {')
            ->toContain('Get-CimInstance Win32_PhysicalMemory -OperationTimeoutSec')
            ->toContain('Get-MemoryModuleLines $read.Value $read.Note')
            ->not->toContain('SerialNumber')->not->toContain('PartNumber')->not->toContain('Manufacturer')
            ->not->toMatch('/\b(?:Set|Remove|New|Enable|Disable|Initialize|Clear|Stop)-[A-Z]/')
            ->and(pwsFunction('Get-MemoryModuleLines'))->not->toContain('SerialNumber')->not->toContain('PartNumber');
    });
});
