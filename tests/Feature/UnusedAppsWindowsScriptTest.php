<?php

declare(strict_types=1);

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Tests\Support\ScriptTemp;

function uawMissing(): bool
{
    return trim((string) shell_exec('command -v pwsh 2>/dev/null')) === '' && trim((string) shell_exec('command -v powershell.exe 2>/dev/null')) === '';
}

function uawOnWindows(): bool
{
    return trim((string) shell_exec('command -v pwsh 2>/dev/null')) === '' && trim((string) shell_exec('command -v powershell.exe 2>/dev/null')) !== '';
}

function uawFunction(string $script, string $name): string
{
    preg_match('/^function '.preg_quote($name, '/').'\b.*?^\}$/ms', $script, $match);

    expect($match)->not->toBeEmpty();

    return $match[0];
}

function uawRun(string $case, string $option = 'none'): ProcessResult
{
    $script = str_replace("\r\n", "\n", (string) file_get_contents(base_path('scripts/windows-checkup.ps1')));
    $functions = implode("\n", array_map(fn (string $name): string => uawFunction($script, $name), [
        'Get-StorageFolderLabel', 'Get-UnusedAppsRunMap', 'Get-UnusedAppsRows', 'Get-UnusedAppsReport', 'Get-LastRestorePoint', 'Get-BackupFreshnessLines',
    ]));

    $dir = ScriptTemp::dir('pcc-uaw');
    file_put_contents($dir.'/step.ps1', "param([string]\$Case, [string]\$Option)\n".$functions."\n".<<<'PS'
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
$today = [datetime]'2026-11-06'
function New-Record($Name, $Kb, $Installed = '20200101', $Publisher = 'Acme Ltd', $Extra = @{}) {
    $record = [pscustomobject]@{ DisplayName = $Name; Publisher = $Publisher; EstimatedSize = $Kb; InstallDate = $Installed; SystemComponent = $null; ParentKeyName = $null; ReleaseType = $null }
    foreach ($key in $Extra.Keys) { $record.$key = $Extra[$key] }
    return $record
}
function New-Pf($Name, $Stamp) {
    return [pscustomobject]@{ Name = $Name; LastWriteTime = [datetime]$Stamp }
}
$u = [string][char]0xFC
switch ($Case) {
    'rows' {
        $records = @(
            (New-Record 'Old Big App' 3145728),
            (New-Record 'Recent Install' 3145728 '20260101'),
            (New-Record 'Exactly A Year' 2097152 '20251106'),
            (New-Record 'Day After' 2097152 '20251107'),
            (New-Record 'Small' 1024),
            (New-Record 'Exactly 100 MB' 102400),
            (New-Record 'Just Under 100 MB' 102399),
            (New-Record 'Office Suite' 5000000 '20200101' 'Microsoft Corporation'),
            (New-Record 'Intel Graphics Tool' 5000000 '20200101' 'Intel Corporation'),
            (New-Record 'Foo Driver Pack' 5000000),
            (New-Record 'Windows SDK' 5000000),
            (New-Record 'Update for Something' 5000000),
            (New-Record 'Patch KB5001234' 5000000),
            (New-Record 'Thing .NET Runtime 6' 5000000),
            (New-Record 'Visual C++ 2015' 5000000),
            (New-Record 'Hidden Component' 5000000 '20200101' 'Acme' @{ SystemComponent = 1 }),
            (New-Record 'Child Entry' 5000000 '20200101' 'Acme' @{ ParentKeyName = 'Parent' }),
            (New-Record 'Hotfix Entry' 5000000 '20200101' 'Acme' @{ ReleaseType = 'Security Update' }),
            (New-Record 'No Install Date' 5000000 ''),
            (New-Record 'Dashed Date' 5000000 '2020-01-01'),
            (New-Record 'Nonsense Date' 5000000 '99999999'),
            (New-Record 'Impossible Month' 5000000 '20201341'),
            (New-Record 'Year 1980' 5000000 '19800101'),
            (New-Record 'No Size' $null),
            (New-Record 'Text Size' 'abc'),
            (New-Record 'Negative Size' -5),
            (New-Record 'Absurd Size' 3000000000),
            (New-Record "Weird|Na/me`t$u" 700000),
            (New-Record ('L' * 120) 650000),
            (New-Record '' 5000000),
            (New-Record 'Dup' 200000),
            (New-Record 'dup' 400000)
        )
        Get-UnusedAppsRows $records $null $today 102400 15
    }
    'limit' {
        $records = 1..20 | ForEach-Object { New-Record ('Bulk {0:00}' -f $_) (200000 + $_ * 1000) }
        Get-UnusedAppsRows $records $null $today 102400 15
    }
    'runmap' {
        $files = @(
            (New-Pf 'SPOTIFY.EXE-AAAAAAAA.pf' '2024-01-01'),
            (New-Pf 'SETUP.EXE-BBBBBBBB.pf' '2026-10-30'),
            (New-Pf 'CODE.EXE-CCCCCCCC.pf' '2026-10-01'),
            (New-Pf 'OLDTOOL.EXE-DDDDDDDD.pf' '2023-03-03'),
            (New-Pf 'OLDTOOL.EXE-EEEEEEEE.pf' '2023-08-08'),
            (New-Pf 'AB.EXE-FFFFFFFF.pf' '2026-10-30'),
            (New-Pf 'readme.txt' '2026-10-30'),
            (New-Pf 'BAD.EXE-XYZ.pf' '2026-10-30')
        )
        $map = Get-UnusedAppsRunMap $files
        ($map.Keys | Sort-Object | ForEach-Object { "$_=$($map[$_].ToString('yyyy-MM-dd'))" }) -join ','
        $records = @(
            (New-Record 'Spotify' 3145728),
            (New-Record 'Old Tool Pro 3' 2097152),
            (New-Record 'Code Editor' 2097152),
            (New-Record 'Setup Wizard Helper' 1048576),
            (New-Record 'Unmatched Thing' 524288),
            (New-Record 'Unmatched Recent' 524288 '20260301')
        )
        Get-UnusedAppsRows $records $map $today 102400 15
    }
    'registry' {
        $root = 'HKCU:\Software\PccCheckupTest' + [guid]::NewGuid().ToString('N').Substring(0, 8)
        try {
            foreach ($app in @(
                @('A', 'Old Registry App', 3145728, '20200101', 'Acme'),
                @('B', 'Prefetched Old App', 2097152, '20230101', 'Acme'),
                @('C', 'Prefetched Fresh App', 2097152, '20200101', 'Acme'),
                @('D', 'Microsoft Thing', 2097152, '20200101', 'Microsoft Corporation')
            )) {
                $key = New-Item -Path "$root\$($app[0])" -Force
                New-ItemProperty -Path $key.PSPath -Name DisplayName -Value $app[1] | Out-Null
                New-ItemProperty -Path $key.PSPath -Name EstimatedSize -Value $app[2] -PropertyType DWord | Out-Null
                New-ItemProperty -Path $key.PSPath -Name InstallDate -Value $app[3] | Out-Null
                New-ItemProperty -Path $key.PSPath -Name Publisher -Value $app[4] | Out-Null
            }
            $env:PCC_APPS_REGISTRY = "$root\*"
            $env:PCC_APPS_MIN_KB = '100'
            if ($Option -eq 'prefetch') {
                $pf = Join-Path ([System.IO.Path]::GetTempPath()) ('pcc-pf-' + [guid]::NewGuid().ToString('N').Substring(0, 8))
                New-Item -ItemType Directory -Force -Path $pf | Out-Null
                foreach ($item in @(@('PREFETCHEDOLDAPP.EXE-11111111.pf', '2023-01-01'), @('PREFETCHEDFRESHAPP.EXE-22222222.pf', (Get-Date).AddDays(-3).ToString('yyyy-MM-dd')))) {
                    $path = Join-Path $pf $item[0]
                    New-Item -ItemType File -Path $path | Out-Null
                    (Get-Item -LiteralPath $path).LastWriteTime = [datetime]$item[1]
                }
                $env:PCC_APPS_PREFETCH = $pf
            } else {
                $env:PCC_APPS_PREFETCH = Join-Path ([System.IO.Path]::GetTempPath()) 'pcc-no-such-prefetch'
            }
            Get-UnusedAppsReport
        } finally {
            Remove-Item -Path $root -Recurse -Force -ErrorAction SilentlyContinue
            if ($env:PCC_APPS_PREFETCH -and $Option -eq 'prefetch') { Remove-Item -Recurse -Force $env:PCC_APPS_PREFETCH -ErrorAction SilentlyContinue }
        }
    }
    'real' { Get-UnusedAppsReport }
    'capped' {
        $env:PCC_APPS_CAP = '1'
        function Get-ItemProperty { Start-Sleep -Seconds 2; @() }
        Get-UnusedAppsReport
    }
    'cap-text' {
        $env:PCC_APPS_CAP = $Option
        function Get-ItemProperty { @() }
        Get-UnusedAppsReport
    }
    'restore' { Get-LastRestorePoint }
    'restore-timeout' {
        function Wait-Job { $null }
        Get-LastRestorePoint 1
    }
    'backup-date' { Get-BackupFreshnessLines $today $null $true ([datetime]'2026-11-03 14:15:16') @('Desktop', 'Documents') '2026-09-30' }
    'backup-nodate' { Get-BackupFreshnessLines $today $null $true $null @() 'none' }
    'backup-off' { Get-BackupFreshnessLines $today $null $false $null @('Pictures') 'could not check' }
    'backup-note' { Get-BackupFreshnessLines $today 'unavailable (needs admin)' $false $null @() 'could not check' }
}
PS);

    $pwsh = trim((string) shell_exec('command -v pwsh 2>/dev/null'));
    $windows = trim((string) shell_exec('command -v powershell.exe 2>/dev/null'));
    $convert = fn (string $path): string => $pwsh !== '' ? $path : trim((string) shell_exec('wslpath -w '.escapeshellarg($path)));
    $command = $pwsh !== '' ? [$pwsh, '-NoProfile', '-File'] : [$windows, '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File'];

    return Process::timeout(180)->run([...$command, $convert($dir.'/step.ps1'), $case, $option]);
}

/**
 * @return list<string>
 */
function uawLines(ProcessResult $result): array
{
    return array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", '', $result->output()))), fn (string $line): bool => $line !== '' && ! str_starts_with($line, 'Looking for') && ! str_starts_with($line, 'The time limit') && ! str_starts_with($line, '  The time limit')));
}

describe('the Windows apps step on known registry data', function () {
    it('lists old apps by size, with an unknown last use when only the install date is known', function () {
        expect(uawLines(uawRun('rows')))->toBe([
            'app|Old Big App|3145728|unknown',
            'app|Exactly A Year|2097152|unknown',
            "app|WeirdName \u{fc}|700000|unknown",
            'app|'.str_repeat('L', 60).'|650000|unknown',
            'app|dup|400000|unknown',
            'app|Exactly 100 MB|102400|unknown',
        ]);
    })->skip(uawMissing(), 'PowerShell is not installed here');

    it('leaves out recent installs, small apps, Microsoft and driver entries, updates and malformed records', function () {
        $output = implode("\n", uawLines(uawRun('rows')));

        foreach (['Recent', 'Day After', 'Small', 'Just Under', 'Office', 'Intel', 'Driver', 'SDK', 'Update for', 'KB5001234', 'NET', 'Visual', 'Hidden', 'Child', 'Hotfix', 'No Install', 'Dashed', 'Nonsense', 'Impossible', '1980', 'No Size', 'Text Size', 'Negative', 'Absurd'] as $left) {
            expect($output)->not->toContain($left);
        }
    })->skip(uawMissing(), 'PowerShell is not installed here');

    it('keeps 15 rows and counts the rest', function () {
        $lines = uawLines(uawRun('limit'));

        expect($lines)->toHaveCount(16)->and($lines[0])->toBe('app|Bulk 20|220000|unknown')->and($lines[15])->toBe('more|5');
    })->skip(uawMissing(), 'PowerShell is not installed here');

    it('uses Prefetch run dates when they are known and ignores generic or short exe names', function () {
        expect(uawLines(uawRun('runmap')))->toBe([
            'code=2026-10-01,oldtool=2023-08-08,spotify=2024-01-01',
            'app|Spotify|3145728|2024-01-01',
            'app|Old Tool Pro 3|2097152|2023-08-08',
            'app|Setup Wizard Helper|1048576|unknown',
            'app|Unmatched Thing|524288|unknown',
        ]);
    })->skip(uawMissing(), 'PowerShell is not installed here');

    it('reads a registry key with real values, with and without readable Prefetch files', function () {
        $without = uawLines(uawRun('registry'));
        $with = uawLines(uawRun('registry', 'prefetch'));

        expect(array_slice($without, 5))->toBe(['app|Old Registry App|3145728|unknown', 'app|Prefetched Fresh App|2097152|unknown', 'app|Prefetched Old App|2097152|unknown'])
            ->and(array_slice($with, 5))->toBe(['app|Old Registry App|3145728|unknown', 'app|Prefetched Old App|2097152|2023-01-01'])
            ->and($with[0])->toBe('scan: complete')
            ->and($with[2])->toBe('limit: 45')
            ->and($with[3])->toBe('format: 1')
            ->and($with[4])->toMatch('/^scanned: \d{4}-\d{2}-\d{2}$/');
    })->skip(! uawOnWindows(), 'needs the Windows registry');

    it('runs on this machine and prints only fixed kinds of line, with no publisher, path or key name', function () {
        $output = implode("\n", uawLines(uawRun('real')));

        foreach (explode("\n", $output) as $line) {
            expect($line)->toMatch('/^(scan: .+|seconds: \d+|limit: 45|format: 1|scanned: \d{4}-\d{2}-\d{2}|app\|[^|\/\\\\\x00-\x1f\x7f]{1,60}\|[1-9]\d*\|(\d{4}-\d{2}-\d{2}|unknown)|more\|\d+)$/u');
        }

        expect($output)->not->toContain('HKLM')->not->toContain('C:');
    })->skip(! uawOnWindows(), 'needs the Windows registry');

    it('says partial when the time limit passes', function () {
        $lines = uawLines(uawRun('capped'));

        expect($lines[0])->toBe('scan: partial (time limit of 1 seconds reached)')->and($lines[2])->toBe('limit: 1');
    })->skip(uawMissing(), 'PowerShell is not installed here');

    it('falls back to the default limit for a value that is not a positive whole number', function (string $cap) {
        expect(uawLines(uawRun('cap-text', $cap))[2])->toBe('limit: 45');
    })->with(['abc', '0', '-5', '1.5'])->skip(uawMissing(), 'PowerShell is not installed here');

    it('reads only: nothing in the new functions deletes, moves, writes or reaches the network', function () {
        $script = (string) file_get_contents(base_path('scripts/windows-checkup.ps1'));
        preg_match('/^function Get-UnusedAppsRunMap.*?^function Get-UsableSerial/ms', $script, $match);

        expect($match)->not->toBeEmpty()
            ->and($match[0])->not->toMatch('/\b(Remove-Item|Move-Item|Copy-Item|Set-Content|Out-File|New-Item|Invoke-WebRequest|Invoke-RestMethod|Set-ItemProperty|Start-Process|Checkpoint-Computer|Enable-ComputerRestore)\b/i')
            ->and($match[0])->toContain('Remove-Job');
    });

    it('keeps the script ASCII only and defaults to 45 seconds and apps of 100 MB', function () {
        $script = (string) file_get_contents(base_path('scripts/windows-checkup.ps1'));

        expect(preg_match('/[^\x00-\x7F]/', $script))->toBe(0)
            ->and($script)->toContain('$cap = 45')->toContain('$minKb = 102400')
            ->and($script)->toContain("Add-Section 'Apps you may not need'")->toContain("Add-Section 'Backup freshness'");
    });
});

describe('the Windows backup step', function () {
    it('writes the File History date, the OneDrive folders and the restore point', function () {
        expect(uawLines(uawRun('backup-date')))->toBe(['format: 1', 'scanned: 2026-11-06', 'filehistory: set up', 'filehistory-last: 2026-11-03', 'onedrive-folders: Desktop, Documents', 'restorepoint-last: 2026-09-30']);
    })->skip(uawMissing(), 'PowerShell is not installed here');

    it('says could not check when File History is on but has no readable date', function () {
        expect(uawLines(uawRun('backup-nodate')))->toContain('filehistory-last: could not check')->toContain('onedrive-folders: none')->toContain('restorepoint-last: none');
    })->skip(uawMissing(), 'PowerShell is not installed here');

    it('says not set up when File History is off', function () {
        expect(uawLines(uawRun('backup-off')))->toContain('filehistory: not set up')->toContain('filehistory-last: none')->toContain('onedrive-folders: Pictures')->toContain('restorepoint-last: could not check');
    })->skip(uawMissing(), 'PowerShell is not installed here');

    it('never turns an unreadable File History into not set up', function () {
        expect(uawLines(uawRun('backup-note')))->toContain('filehistory: could not check')->toContain('filehistory-last: could not check')->not->toContain('filehistory: not set up');
    })->skip(uawMissing(), 'PowerShell is not installed here');

    it('reads the restore point date, none, or could not check, without admin rights', function () {
        expect(uawLines(uawRun('restore'))[0])->toMatch('/^(\d{4}-\d{2}-\d{2}|none|could not check)$/');
    })->skip(uawMissing(), 'PowerShell is not installed here');

    it('gives up on a restore point query that does not answer in time', function () {
        expect(uawLines(uawRun('restore-timeout')))->toBe(['could not check']);
    })->skip(uawMissing(), 'PowerShell is not installed here');
});
