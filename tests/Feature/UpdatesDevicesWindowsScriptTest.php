<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Tests\Support\ScriptTemp;

function udwMissing(): bool
{
    return trim((string) shell_exec('command -v pwsh 2>/dev/null')) === '' && trim((string) shell_exec('command -v powershell.exe 2>/dev/null')) === '';
}

function udwScript(): string
{
    return str_replace("\r\n", "\n", (string) file_get_contents(base_path('scripts/windows-checkup.ps1')));
}

function udwFunction(string $name): string
{
    preg_match('/^function '.preg_quote($name, '/').'\b.*?^\}$/ms', udwScript(), $match);

    expect($match)->not->toBeEmpty();

    return $match[0];
}

/**
 * @return list<string>
 */
function udwLines(string $case): array
{
    $dir = ScriptTemp::dir('pcc-udw');
    file_put_contents($dir.'/step.ps1', "param([string]\$Case)\n".udwFunction('Get-DeviceProblemLines')."\n".<<<'PS'
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
function Dev($Name, $Code) { [pscustomobject]@{ Name = $Name; ConfigManagerErrorCode = $Code; DeviceID = 'USB\VID_1234&PID_5678\SERIAL0042'; PNPDeviceID = 'USB\VID_1234&PID_5678\SERIAL0042' } }
switch ($Case) {
    'devices' { Get-DeviceProblemLines @((Dev 'Intel(R) Wireless Bluetooth(R)' 43), (Dev 'Camera' 28)) $null }
    'skips' { Get-DeviceProblemLines @((Dev 'Fine' 0), (Dev 'Off' 22), (Dev 'Gone' 24), (Dev 'Unplugged' 45), (Dev 'Broken' 10)) $null }
    'dirty' { Get-DeviceProblemLines @((Dev "We`tir|d`nna\me" 31), (Dev ('L' * 200) 31), (Dev $null 28), (Dev '   ' 28), (Dev "Caf$([char]0xE9)" 1)) $null }
    'many' { Get-DeviceProblemLines @(1..25 | ForEach-Object { Dev "Device $_" 28 }) $null }
    'none' { Get-DeviceProblemLines @() $null }
    'null' { Get-DeviceProblemLines $null $null }
    'failed' { Get-DeviceProblemLines @((Dev 'Camera' 28)) 'took longer than 30 seconds' }
}
PS);

    $pwsh = trim((string) shell_exec('command -v pwsh 2>/dev/null'));
    $windows = trim((string) shell_exec('command -v powershell.exe 2>/dev/null'));
    $convert = fn (string $path): string => $pwsh !== '' ? $path : trim((string) shell_exec('wslpath -w '.escapeshellarg($path)));
    $command = $pwsh !== '' ? [$pwsh, '-NoProfile', '-File'] : [$windows, '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File'];
    $result = Process::timeout(120)->run([...$command, $convert($dir.'/step.ps1'), $case])->throw();

    return array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", '', $result->output()))), fn (string $line): bool => $line !== ''));
}

describe('the devices step in the Windows script', function () {
    it('keeps the device name and problem code only', function () {
        expect(udwLines('devices'))->toBe(['format: 1', 'scan: complete', 'total: 2', 'device|Intel(R) Wireless Bluetooth(R)|43', 'device|Camera|28']);
    })->skip(udwMissing(), 'PowerShell is not installed here');

    it('never prints a hardware id or serial', function () {
        expect(implode("\n", udwLines('devices')))->not->toContain('VID_')->not->toContain('SERIAL');
    })->skip(udwMissing(), 'PowerShell is not installed here');

    it('skips working, disabled and unplugged devices', function () {
        expect(udwLines('skips'))->toBe(['format: 1', 'scan: complete', 'total: 1', 'device|Broken|10']);
    })->skip(udwMissing(), 'PowerShell is not installed here');

    it('cleans names of control characters, pipes and slashes and caps their length', function () {
        $lines = udwLines('dirty');

        expect($lines[0])->toBe('format: 1')
            ->and($lines[2])->toBe('total: 5')
            ->and($lines[3])->toBe('device|Weirdname|31')
            ->and($lines[4])->toBe('device|'.str_repeat('L', 80).'|31')
            ->and($lines[5])->toBe('device|Unknown device|28')
            ->and($lines[6])->toBe('device|Unknown device|28')
            ->and($lines[7])->toBe("device|Caf\u{e9}|1");
    })->skip(udwMissing(), 'PowerShell is not installed here');

    it('lists at most twenty devices but counts them all', function () {
        $lines = udwLines('many');

        expect($lines[2])->toBe('total: 25')
            ->and(array_filter($lines, fn (string $line): bool => str_starts_with($line, 'device|')))->toHaveCount(20);
    })->skip(udwMissing(), 'PowerShell is not installed here');

    it('says the scan was complete when no device has a problem', function (string $case) {
        expect(udwLines($case))->toBe(['format: 1', 'scan: complete', 'total: 0']);
    })->with(['none', 'null'])->skip(udwMissing(), 'PowerShell is not installed here');

    it('says it could not check when the query failed or timed out', function () {
        expect(udwLines('failed'))->toBe(['format: 1', 'scan: could not check']);
    })->skip(udwMissing(), 'PowerShell is not installed here');

    it('reads devices once, time-boxed, through the section', function () {
        $script = udwScript();

        expect($script)->toContain("Get-CimInstance Win32_PnPEntity -Filter 'ConfigManagerErrorCode <> 0 AND ConfigManagerErrorCode <> 22 AND ConfigManagerErrorCode <> 24 AND ConfigManagerErrorCode <> 45' -OperationTimeoutSec")
            ->and($script)->toContain('Get-DeviceProblemLines $badDevices.Value $badDevices.Note')
            ->and($script)->not->toContain('Select-Object Name, DeviceID, ConfigManagerErrorCode');
    });
});
