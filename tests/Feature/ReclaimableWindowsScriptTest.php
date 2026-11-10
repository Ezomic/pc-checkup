<?php

declare(strict_types=1);

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

function spwMissing(): bool
{
    return trim((string) shell_exec('command -v pwsh 2>/dev/null')) === '' && trim((string) shell_exec('command -v powershell.exe 2>/dev/null')) === '';
}

function spwFunction(string $script, string $name): string
{
    preg_match('/^function '.preg_quote($name, '/').'\b.*?^\}$/ms', $script, $match);

    expect($match)->not->toBeEmpty();

    return $match[0];
}

function spwRun(string $case): ProcessResult
{
    $script = str_replace("\r\n", "\n", (string) file_get_contents(base_path('scripts/windows-checkup.ps1')));
    $functions = implode("\n", array_map(fn (string $name): string => spwFunction($script, $name), [
        'Test-StorageFixedPath', 'Measure-StorageTree', 'Get-StorageFolderLabel', 'Get-StorageSlice',
        'Get-SpaceEntryCount', 'Test-SpaceReadable', 'Get-SpaceInstallers', 'Get-SpaceBiggestFiles', 'Get-SpaceReport',
        'Test-StorageEngine', 'Get-StorageWalk', 'Measure-StorageWalk',
    ]));

    $dir = sys_get_temp_dir().'/pcc-spw-'.bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);
    file_put_contents($dir.'/step.ps1', "param([string]\$Case)\n".$functions."\n".<<<'PS'
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
$windows = [System.IO.Path]::DirectorySeparatorChar -eq '\'
$base = Join-Path ([System.IO.Path]::GetTempPath()) ('pcc-space-' + [guid]::NewGuid().ToString('N').Substring(0, 8))
function New-Sized([string]$Path, [int]$Kib) {
    [void](New-Item -ItemType Directory -Force -Path (Split-Path -Parent $Path))
    $stream = [System.IO.File]::Create($Path)
    $stream.SetLength([long]$Kib * 1024)
    $stream.Close()
}
function Set-Env([string]$Profile) {
    $env:PCC_SPACE_PROFILE = $Profile
    $env:PCC_SPACE_RECYCLE = "$base/recycle"
    $env:PCC_SPACE_TEMP = "$base/temp"
    $env:PCC_SPACE_WINDIR = "$base/win"
    $env:PCC_SPACE_DRIVE = "$base/drive"
    $env:PCC_SPACE_MIN_KB = '100'
}
try {
    $p = "$base/profile"
    $u = [string][char]0xFC
    $i = [string][char]0xEF
    $report = "$p/Documents/Report ${u}n${i}.pdf"
    $long = "$p/Documents/" + ('n' * 120) + '.txt'
    foreach ($item in @(
        @("$base/recycle/`$Rabc", 100), @("$base/recycle/`$Iabc", 1), @("$base/recycle/`$Rdef/inner", 50),
        @("$base/temp/a.tmp", 300), @("$base/temp/b/c.tmp", 200),
        @("$base/win/Temp/x.tmp", 120), @("$base/win/SoftwareDistribution/Download/u.cab", 400),
        @("$base/drive/Windows.old/f.bin", 700),
        @("$p/Downloads/a.exe", 500), @("$p/Downloads/b.MSI", 100), @("$p/Downloads/c.iso", 50),
        @("$p/Downloads/d.zip", 999), @("$p/Downloads/sub/e.exe", 300),
        @("$p/Movies/film.mov", 3000), @($report, 2000),
        @("$p/Documents/deep/nested/big.bin", 1500), @($long, 900),
        @("$p/Documents/a   b.bin", 600), @("$p/Desktop/small.txt", 10),
        @("$p/.hidden/huge.bin", 9000), @("$p/AppData/Local/huge.bin", 5000),
        @("$base/outside/big.exe", 7000), @("$p/Documents/.flagged.bin", 800)
    )) { New-Sized $item[0] $item[1] }
    if ($windows) {
        New-Sized "$p/Documents/offline.bin" 4000
        attrib +O ("$p/Documents/offline.bin".Replace('/', '\')) | Out-Null
        (Get-Item -LiteralPath "$p/.hidden" -Force).Attributes = 'Hidden, Directory'
        (Get-Item -LiteralPath "$p/Documents/.flagged.bin" -Force).Attributes = 'Hidden'
        cmd /c mklink /J ("$p/Downloads/jlink".Replace('/', '\')) ("$base/outside".Replace('/', '\')) | Out-Null
    } else {
        [void](New-Item -ItemType SymbolicLink -Path "$p/Downloads/jlink" -Target "$base/outside")
    }
    Set-Env $p
    # The real script compiles the folder walker in the storage step first; cold, the compile eats the first place's time slice.
    [void](Test-StorageEngine)
    switch ($Case) {
        'full' { Get-SpaceReport }
        'capped' {
            $env:PCC_SPACE_CAP = '1'
            function Measure-StorageTree { Start-Sleep -Milliseconds 50; [pscustomobject]@{ Bytes = [long]5; Capped = $true } }
            Get-SpaceReport
        }
        'stopped' {
            $clock = [System.Diagnostics.Stopwatch]::StartNew()
            $result = Get-SpaceBiggestFiles $p $clock 0 102400 10
            "capped=$($result.Capped) files=$(@($result.Files).Count)"
        }
        'empty' {
            Remove-Item -Recurse -Force "$base/recycle", "$base/temp", "$base/win", "$base/drive", $p
            New-Item -ItemType Directory -Force -Path $p | Out-Null
            Get-SpaceReport
        }
        'nowhere' {
            Set-Env "$base/nowhere"
            $env:PCC_SPACE_RECYCLE = "$base/nowhere"
            $env:PCC_SPACE_TEMP = "$base/nowhere"
            $env:PCC_SPACE_WINDIR = "$base/nowhere"
            $env:PCC_SPACE_DRIVE = "$base/nowhere"
            Get-SpaceReport
        }
        'unreadable' {
            $locked = "$base/temp"
            if ($windows) {
                $acl = Get-Acl -LiteralPath $locked
                $rule = New-Object System.Security.AccessControl.FileSystemAccessRule([System.Security.Principal.WindowsIdentity]::GetCurrent().Name, 'ListDirectory', 'Deny')
                $acl.AddAccessRule($rule)
                Set-Acl -LiteralPath $locked -AclObject $acl
            } else {
                chmod 000 $locked
            }
            try {
                Get-SpaceReport
            } finally {
                if ($windows) {
                    [void]$acl.RemoveAccessRule($rule)
                    Set-Acl -LiteralPath $locked -AclObject $acl
                } else {
                    chmod 755 $locked
                }
            }
        }
        'cap-text' {
            $env:PCC_SPACE_CAP = $env:CASE_CAP
            Get-SpaceReport
        }
        'apps' {
            $a = "$base/apps"
            foreach ($item in @(
                @("$a/AppData/Local/Programs/Game/game.pak", 5000),
                @("$a/Games/SteamApps/downloading/patch.bin", 5500),
                @("$a/steamapps/common/Other/other.pak", 4500),
                @("$a/Documents/steamapps notes/list.bin", 700),
                @("$a/Videos/holiday.mp4", 1000)
            )) { New-Sized $item[0] $item[1] }
            foreach ($n in 1..12) { New-Sized "$a/SteamLibrary/steamapps/common/Game/data$n.pak" (4000 + $n) }
            Set-Env $a
            Get-SpaceReport
        }
    }
} finally {
    Remove-Item -Recurse -Force $base -ErrorAction SilentlyContinue
}
PS);

    $pwsh = trim((string) shell_exec('command -v pwsh 2>/dev/null'));
    $windows = trim((string) shell_exec('command -v powershell.exe 2>/dev/null'));
    $convert = fn (string $path): string => $pwsh !== '' ? $path : trim((string) shell_exec('wslpath -w '.escapeshellarg($path)));
    $command = $pwsh !== '' ? [$pwsh, '-NoProfile', '-File'] : [$windows, '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File'];

    return Process::timeout(180)->run([...$command, $convert($dir.'/step.ps1'), $case]);
}

/**
 * @return list<string>
 */
function spwLines(ProcessResult $result): array
{
    return array_values(array_filter(array_map('trim', explode("\n", $result->output())), fn (string $line): bool => preg_match('/^(scan:|seconds:|limit:|format:|reclaim\||unreadable\||file\||capped=)/', $line) === 1));
}

describe('the Windows space step on a tree with known files', function () {
    it('measures the Recycle Bin, Temp folders, updates, installers and Windows.old with their counts', function () {
        $lines = spwLines(spwRun('full'));

        expect(array_slice($lines, 4, 6))->toBe([
            'reclaim|recycle|151|2',
            'reclaim|temp|500|2',
            'reclaim|systemtemp|120|1',
            'reclaim|updates|400|1',
            'reclaim|installers|650|3',
            'reclaim|windowsold|700|1',
        ]);
    })->skip(spwMissing(), 'PowerShell is not installed here');

    it('states that it finished, the limit and the format', function () {
        $lines = spwLines(spwRun('full'));

        expect($lines[0])->toBe('scan: complete')
            ->and($lines[1])->toMatch('/^seconds: \d+$/')
            ->and($lines[2])->toBe('limit: 60')
            ->and($lines[3])->toBe('format: 1');
    })->skip(spwMissing(), 'PowerShell is not installed here');

    it('lists the biggest files with a cleaned name, a kind and only the folder above', function () {
        $files = array_values(array_filter(spwLines(spwRun('full')), fn (string $line): bool => str_starts_with($line, 'file|')));

        expect($files)->toBe([
            'file|film.mov|3000|Videos|Movies',
            "file|Report \u{fc}n\u{ef}.pdf|2000|Documents|Documents",
            'file|big.bin|1500|Other|nested',
            'file|d.zip|999|Archives|Downloads',
            'file|'.str_repeat('n', 80).'|900|Documents|Documents',
            'file|a b.bin|600|Other|Documents',
            'file|a.exe|500|Apps|Downloads',
            'file|e.exe|300|Apps|sub',
            'file|b.MSI|100|Apps|Downloads',
        ]);
    })->skip(spwMissing(), 'PowerShell is not installed here');

    it('leaves out hidden items, AppData, junctions and links, and prints only fixed kinds of line', function () {
        $output = implode("\n", spwLines(spwRun('full')));

        foreach (['huge', 'flagged', 'jlink', 'big.exe', 'small', 'offline'] as $left) {
            expect($output)->not->toContain($left);
        }

        foreach (explode("\n", $output) as $line) {
            expect($line)->toMatch('/^(scan: .+|seconds: \d+|limit: \d+|format: 1|reclaim\|(recycle|temp|systemtemp|updates|installers|windowsold)\|\d+\|\d+|unreadable\|[a-z]+|file\|[^|\/\\\\\x00-\x1f\x7f]{1,80}\|[1-9]\d*\|(Photos|Videos|Music|Documents|Archives|Apps|Other)\|[^|\/\\\\\x00-\x1f\x7f]{0,60})$/u');
        }
    })->skip(spwMissing(), 'PowerShell is not installed here');

    it('leaves out a place whose measuring ran out of time and says the scan is partial', function () {
        $lines = spwLines(spwRun('capped'));

        expect($lines[0])->toBe('scan: partial (time limit of 1 seconds reached)')
            ->and($lines[2])->toBe('limit: 1')
            ->and(array_values(array_filter($lines, fn (string $line): bool => str_starts_with($line, 'reclaim|'))))->toBe(['reclaim|installers|650|3']);
    })->skip(spwMissing(), 'PowerShell is not installed here');

    it('names a place it cannot list instead of reporting it as empty', function () {
        $lines = spwLines(spwRun('unreadable'));

        expect($lines)->toContain('unreadable|temp')->not->toContain('reclaim|temp|500|2')
            ->and(implode("\n", $lines))->toContain('reclaim|recycle|151|2')->toContain('reclaim|installers|650|3');
    })->skip(spwMissing(), 'PowerShell is not installed here');

    it('stops looking for big files at once when the time is up', function () {
        expect(spwLines(spwRun('stopped')))->toBe(['capped=True files=0']);
    })->skip(spwMissing(), 'PowerShell is not installed here');

    it('prints only the header for an empty profile and for places that do not exist', function (string $case) {
        $lines = spwLines(spwRun($case));

        expect($lines)->toHaveCount(4)->and($lines[0])->toBe('scan: complete');
    })->with(['empty', 'nowhere'])->skip(spwMissing(), 'PowerShell is not installed here');

    it('falls back to the default limit for a value that is not a positive whole number', function (string $cap) {
        putenv("CASE_CAP={$cap}");

        expect(spwLines(spwRun('cap-text'))[2])->toBe('limit: 60');

        putenv('CASE_CAP');
    })->with(['abc', '0', '-5', '1.5'])->skip(spwMissing(), 'PowerShell is not installed here');

    it('reads only: nothing in the new functions deletes, moves, writes or reaches the network', function () {
        $script = (string) file_get_contents(base_path('scripts/windows-checkup.ps1'));
        preg_match('/^function Get-SpaceEntryCount.*?^function Get-UsableSerial/ms', $script, $match);

        expect($match)->not->toBeEmpty()
            ->and($match[0])->not->toMatch('/\b(Remove-Item|Move-Item|Copy-Item|Set-Content|Out-File|New-Item|Invoke-WebRequest|Invoke-RestMethod|Set-ItemProperty|Start-Process)\b/i');
    });

    it('keeps the script ASCII only and defaults to sixty seconds and files of 50 MB', function () {
        $script = (string) file_get_contents(base_path('scripts/windows-checkup.ps1'));

        expect(preg_match('/[^\x00-\x7F]/', $script))->toBe(0)
            ->and($script)->toContain('$SpaceCapSeconds = 60')->toContain('$minKb = 51200');
    });
});

describe('the Windows biggest files leave apps and games out', function () {
    it('skips per-user programs and Steam game libraries in any case, so other files fill the list', function () {
        $files = array_values(array_filter(spwLines(spwRun('apps')), fn (string $line): bool => str_starts_with($line, 'file|')));

        expect($files)->toBe([
            'file|holiday.mp4|1000|Videos|Videos',
            'file|list.bin|700|Other|steamapps notes',
        ]);
    })->skip(spwMissing(), 'PowerShell is not installed here');
});
