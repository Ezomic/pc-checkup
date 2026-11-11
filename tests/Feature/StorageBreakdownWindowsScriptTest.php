<?php

declare(strict_types=1);

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

function sbwMissing(): bool
{
    return trim((string) shell_exec('command -v pwsh 2>/dev/null')) === '' && trim((string) shell_exec('command -v powershell.exe 2>/dev/null')) === '';
}

/**
 * Runs the storage step of the Windows script on a tree it builds itself in the temp folder of the
 * PowerShell that runs it: pwsh where it is installed, else Windows PowerShell 5.1 from WSL. $Engine
 * "script" forces the walker written in PowerShell.
 */
function sbwRun(string $case, string $engine = 'default'): ProcessResult
{
    $script = str_replace("\r\n", "\n", (string) file_get_contents(base_path('scripts/windows-checkup.ps1')));
    preg_match_all('/^function (?:Get|Measure|Test)-Storage\w+.*?^\}/ms', $script, $found);

    $dir = sys_get_temp_dir().'/pcc-sbw-'.bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);
    file_put_contents($dir.'/step.ps1', "param([string]\$Case, [string]\$Engine)\n".implode("\n", $found[0])."\n".<<<'PS'
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
$windows = [System.IO.Path]::DirectorySeparatorChar -eq '\'
$base = Join-Path ([System.IO.Path]::GetTempPath()) ('pcc-sbw-' + [guid]::NewGuid().ToString('N').Substring(0, 8))
function New-Sized([string]$Path, [int]$Kib) {
    [void](New-Item -ItemType Directory -Force -Path (Split-Path -Parent $Path))
    $stream = [System.IO.File]::Create($Path)
    $stream.SetLength([long]$Kib * 1024)
    $stream.Close()
}
function Get-Relative([string]$Path) {
    $full = [System.IO.Path]::GetFullPath($Path).Replace('\', '/').TrimEnd('/')
    $top = [System.IO.Path]::GetFullPath("$base/root").Replace('\', '/').TrimEnd('/')
    if ($full.Length -le $top.Length) { return '' }
    return $full.Substring($top.Length + 1)
}
function Get-Sorted($Table) {
    @($Table.GetEnumerator() | ForEach-Object { if ($_.Value -is [array]) { "$($_.Key)=$($_.Value[0])/$($_.Value[1])" } else { "$($_.Key)=$($_.Value)" } } | Sort-Object) -join ';'
}
if ($Engine -eq 'script') { $env:PCC_STORAGE_ENGINE = 'script' }
$me = "$base/root/Users/me"
$locked = "$me/Projects/locked"
try {
    foreach ($item in @(
        @("$me/Downloads/a.zip", 300), @("$me/Downloads/photo.JPG", 200), @("$me/Documents/report.pdf", 100),
        @("$me/Documents/deep/x/y/z.bin", 50), @("$me/Desktop/note.txt", 10), @("$me/Pictures/p.png", 40),
        @("$me/AppData/Local/Temp/t.tmp", 20), @("$me/AppData/Local/Pkg/l.dat", 70), @("$me/AppData/Roaming/r.dat", 30),
        @("$me/AppData/LocalLow/w.dat", 15), @("$me/Projects/app/src.c", 400), @("$me/Projects/site/i.html", 60),
        @("$locked/secret.dat", 500), @("$me/README", 5), @("$base/root/Users/Public/pub.dat", 80),
        @("$base/root/Windows/System32/k.dll", 500), @("$base/root/Windows/WinSxS/m/n.dll", 250),
        @("$base/root/Program Files/App/app.exe", 120), @("$base/root/Program Files (x86)/Old/old.exe", 90),
        @("$base/root/ProgramData/pd.dat", 60), @("$base/root/Games/g.pak", 700), @("$base/root/aaa/a.dat", 10),
        @("$base/outside/big.bin", 9000)
    )) { New-Sized $item[0] $item[1] }
    if ($windows) {
        New-Sized "$me/Documents/offline.bin" 4000
        attrib +O ("$me/Documents/offline.bin".Replace('/', '\')) | Out-Null
        cmd /c mklink /J ("$me/Downloads/jlink".Replace('/', '\')) ("$base/outside".Replace('/', '\')) | Out-Null
        $acl = Get-Acl -LiteralPath $locked
        $rule = New-Object System.Security.AccessControl.FileSystemAccessRule([System.Security.Principal.WindowsIdentity]::GetCurrent().Name, 'ListDirectory', 'Deny')
        $acl.AddAccessRule($rule)
        Set-Acl -LiteralPath $locked -AclObject $acl
    } else {
        [void](New-Item -ItemType SymbolicLink -Path "$me/Downloads/jlink" -Target "$base/outside")
        chmod 000 $locked
    }
    $env:PCC_STORAGE_PROFILE = $me
    $env:PCC_STORAGE_ROOT = "$base/root"
    switch ($Case) {
        'full' {
            Get-StorageBreakdown
            "engine|$([bool]('Pcc.StorageWalk' -as [type]))"
        }
        'order' {
            $real = ${function:Measure-StorageWalk}
            $script:seen = New-Object System.Collections.Generic.List[string]
            function Measure-StorageWalk($Walk, $Clock, [long]$StopAtMs, $Stats) {
                $name = Get-Relative $Walk.Path
                if (-not $script:seen.Contains($name)) { $script:seen.Add($name) }
                & $real $Walk $Clock $StopAtMs $Stats
            }
            [void](Get-StorageBreakdown)
            foreach ($name in $script:seen) { "order|$name" }
        }
        'slow' {
            $env:PCC_STORAGE_CAP = '15'
            $real = ${function:Measure-StorageWalk}
            $script:need = @{ 'Users/me/AppData/Local' = 4000; 'Windows' = 2500 }
            $script:spent = @{}
            $script:calls = @{}
            function Measure-StorageWalk($Walk, $Clock, [long]$StopAtMs, $Stats) {
                $name = Get-Relative $Walk.Path
                $script:calls[$name] = [int]$script:calls[$name] + 1
                $left = [long]$script:need[$name] - [long]$script:spent[$name]
                if ($left -gt 0) {
                    $wait = [math]::Min($left, $StopAtMs - $Clock.ElapsedMilliseconds)
                    if ($wait -gt 0) {
                        Start-Sleep -Milliseconds $wait
                        $script:spent[$name] = [long]$script:spent[$name] + $wait
                    }
                    if ($wait -lt $left) { return $false }
                }
                & $real $Walk $Clock $StopAtMs $Stats
            }
            Get-StorageBreakdown
            foreach ($name in $script:need.Keys) { "calls|$name|$($script:calls[$name])" }
        }
        'capped' {
            $env:PCC_STORAGE_CAP = '8'
            $real = ${function:Measure-StorageWalk}
            function Measure-StorageWalk($Walk, $Clock, [long]$StopAtMs, $Stats) {
                if ((Get-Relative $Walk.Path) -ne 'Windows') { return (& $real $Walk $Clock $StopAtMs $Stats) }
                $wait = $StopAtMs - $Clock.ElapsedMilliseconds
                if ($wait -gt 0) { Start-Sleep -Milliseconds $wait }
                return $false
            }
            $clock = [System.Diagnostics.Stopwatch]::StartNew()
            Get-StorageBreakdown
            "took|$([long]$clock.Elapsed.TotalSeconds)"
        }
        'resume' {
            for ($i = 0; $i -lt 300; $i++) { New-Sized "$base/many/d$i/e/f$i.bin" 1 }
            $clock = [System.Diagnostics.Stopwatch]::StartNew()
            $walk = Get-StorageWalk "$base/many" $null $null
            $none = Measure-StorageWalk $walk $clock $clock.ElapsedMilliseconds $null
            "none|$none|$($walk.Bytes)"
            while (-not (Measure-StorageWalk $walk $clock ($clock.ElapsedMilliseconds + 1) $null)) { }
            "many|$($walk.Done)|$([long]($walk.Bytes / 1024))"
            $tables = foreach ($step in 1, 100000) {
                $stats = @{ Group = @{ zip = 'Archives'; jpg = 'Photos'; png = 'Photos' }; Kind = @{}; Ext = @{}; Bare = @([long]0, [long]0) }
                foreach ($kind in 'Photos', 'Archives', 'Other') { $stats.Kind[$kind] = @([long]0, [long]0) }
                $tally = @{ Profile = [System.IO.Path]::GetFullPath($me).TrimEnd('\', '/'); One = @{}; Two = @{} }
                $walk = Get-StorageWalk $me $null $tally
                while (-not (Measure-StorageWalk $walk $clock ($clock.ElapsedMilliseconds + $step) $stats)) { }
                "$($walk.Bytes)|$(Get-Sorted $stats.Kind)|$(Get-Sorted $stats.Ext)|$($stats.Bare[0])/$($stats.Bare[1])|$(Get-Sorted $tally.One)|$(Get-Sorted $tally.Two)"
            }
            "same|$($tables[0] -eq $tables[1])"
            "profile|$($tables[1])"
        }
        'tree' {
            $big = "$base/big"
            foreach ($item in @(
                @("$big/Media/A/B/C/D/d.bin", 1126400), @("$big/Media/A/B/C/c.bin", 153600), @("$big/Media/A/B/small/s.bin", 51200),
                @("$big/Media/A/B2/b.bin", 204800), @("$big/Media/Dup  Name/x.bin", 307200), @("$big/Media/Dup Name/x.bin", 204800),
                @("$big/Media/s1/x.bin", 153600), @("$big/Media/tieb/x.bin", 122880), @("$big/Media/tiea/x.bin", 122880),
                @("$big/Media/s4/x.bin", 112640), @("$big/Media/s5/x.bin", 107520),
                @("$big/Games/save/s.bin", 102400), @("$big/Games/cache/c.bin", 102399), @("$big/Games/g.pak", 843777),
                @("$big/Almost/inner/i.bin", 204800), @("$big/Almost/a.bin", 843775),
                @("$big/AppData/Local/Temp/T1/t.bin", 512000), @("$big/AppData/Local/Temp/t2.bin", 614400), @("$big/AppData/Local/Pkg/p.bin", 153600),
                @("$big/t1/x.bin", 1), @("$big/t2/x.bin", 2), @("$big/t3/x.bin", 3), @("$big/t4/x.bin", 4), @("$big/t5/x.bin", 5), @("$big/t6/x.bin", 6)
            )) { New-Sized $item[0] $item[1] }
            $env:PCC_STORAGE_PROFILE = $big
            $env:PCC_STORAGE_ROOT = "$base/nothing"
            Get-StorageBreakdown
            $tally = @{ Profile = [System.IO.Path]::GetFullPath($big).TrimEnd('\', '/'); One = @{}; Two = @{}; Three = @{}; Four = @{} }
            $clock = [System.Diagnostics.Stopwatch]::StartNew()
            $walk = Get-StorageWalk $big $null $tally
            while (-not (Measure-StorageWalk $walk $clock ($clock.ElapsedMilliseconds + 100000) $null)) { }
            $depths = foreach ($level in 1, 2, 3, 4) {
                $table = @($tally.One, $tally.Two, $tally.Three, $tally.Four)[$level - 1]
                @($table.Keys | Where-Object { $_.Split('\').Count -ne $level }).Count
            }
            "depths|$($depths -join ',')"
            "four|$(Get-Sorted $tally.Four)"
        }
        'nowhere' {
            $env:PCC_STORAGE_PROFILE = "$base/nowhere"
            $env:PCC_STORAGE_ROOT = "$base/nowhere"
            Get-StorageBreakdown
        }
    }
} finally {
    if ($windows) {
        [void]$acl.RemoveAccessRule($rule)
        Set-Acl -LiteralPath $locked -AclObject $acl
    } else {
        chmod 755 $locked
    }
    Remove-Item -Recurse -Force $base -ErrorAction SilentlyContinue
}
PS);

    $pwsh = trim((string) shell_exec('command -v pwsh 2>/dev/null'));
    $windows = trim((string) shell_exec('command -v powershell.exe 2>/dev/null'));
    $convert = fn (string $path): string => $pwsh !== '' ? $path : trim((string) shell_exec('wslpath -w '.escapeshellarg($path)));
    $command = $pwsh !== '' ? [$pwsh, '-NoProfile', '-File'] : [$windows, '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File'];

    return Process::timeout(180)->run([...$command, $convert($dir.'/step.ps1'), $case, $engine]);
}

/**
 * @return list<string>
 */
function sbwLines(ProcessResult $result): array
{
    return array_values(array_filter(array_map('trim', explode("\n", $result->output())), fn (string $line): bool => str_contains($line, '|') || preg_match('/^(scan|seconds|limit|format):/', $line) === 1));
}

/**
 * @param  list<string>  $lines
 * @return list<string>
 */
function sbwKind(array $lines, string $kind): array
{
    return array_values(array_filter($lines, fn (string $line): bool => str_starts_with($line, $kind.'|')));
}

const SBW_FULL = [
    'scan: complete',
    'limit: 150',
    'format: 2',
    'root|Users|1380',
    'root|Windows|750',
    'root|Games|700',
    'root|Program Files|120',
    'root|Program Files (x86)|90',
    'root|ProgramData|60',
    'roots_total|3110',
    'home|1300',
    'home|Downloads|500',
    'home|Projects|460',
    'home|Documents|150',
    'home|AppData|135',
    'home|Pictures|40',
    'home|Desktop|10',
    'sub|Projects|app|400',
    'sub|Projects|site|60',
    'tree|Downloads|500',
    'tree|Projects|460',
    'tree|Documents|150',
    'tree|AppData|135',
    'tree|Pictures|40',
    'tree|Desktop|10',
    'folder|Downloads|500',
    'folder|Documents|150',
    'folder|Desktop|10',
    'folder|Pictures|40',
    'folder|AppData|135',
    'library|Local|90',
    'library|Roaming|30',
    'library|Temp|20',
    'type|Photos|240|2',
    'type|Documents|110|2',
    'type|Archives|300|1',
    'type|Other|650|8',
    'noext|5|1',
    'ext|c|400|1',
    'ext|zip|300|1',
    'ext|jpg|200|1',
    'ext|dat|115|3',
    'ext|pdf|100|1',
    'ext|html|60|1',
    'ext|bin|50|1',
    'ext|png|40|1',
    'ext|tmp|20|1',
    'ext|txt|10|1',
];

describe('the Windows storage step measuring a tree with known files', function () {
    it('measures every folder exactly, skipping links, cloud-only files and folders it may not read', function (string $engine) {
        $lines = sbwLines(sbwRun('full', $engine));

        expect(array_values(array_filter($lines, fn (string $line): bool => ! str_starts_with($line, 'seconds:') && ! str_starts_with($line, 'engine|'))))->toBe(SBW_FULL);
    })->with(['engine', 'script'])->skip(sbwMissing(), 'PowerShell is not installed here');

    it('uses the compiled walker unless told to use the one written in PowerShell', function (string $engine, string $expected) {
        expect(sbwKind(sbwLines(sbwRun('full', $engine)), 'engine'))->toBe([$expected]);
    })->with([['engine', 'engine|True'], ['script', 'engine|False']])->skip(sbwMissing(), 'PowerShell is not installed here');

    it('never names a folder from outside your own user folder', function () {
        $output = sbwRun('full')->output();

        expect($output)->not->toContain('Public')->not->toContain('System32')->not->toContain('WinSxS')->not->toContain('locked')->not->toContain('jlink');
    })->skip(sbwMissing(), 'PowerShell is not installed here');

    it('prints a valid section for a user folder and drive that do not exist', function (string $engine) {
        $result = sbwRun('nowhere', $engine);

        expect($result->successful())->toBeTrue()
            ->and(array_values(array_filter(sbwLines($result), fn (string $line): bool => ! str_starts_with($line, 'seconds:'))))->toBe(['scan: complete', 'limit: 150', 'format: 2']);
    })->with(['engine', 'script'])->skip(sbwMissing(), 'PowerShell is not installed here');
});

const SBW_TREE = [
    'tree|Media|2667520',
    'tree|Media/A|1536000',
    'tree|Media/A/B|1331200',
    'tree|Media/A/B/C|1280000',
    'tree|Media/A/B2|204800',
    'tree|Media/Dup Name|307200',
    'tree|Media/s1|153600',
    'tree|Media/tiea|122880',
    'tree|Media/tieb|122880',
    'tree|Media/s4|112640',
    'tree|AppData|1280000',
    'tree|AppData/Local|1280000',
    'tree|AppData/Local/Temp|1126400',
    'tree|AppData/Local/Temp/T1|512000',
    'tree|AppData/Local/Pkg|153600',
    'tree|Games|1048576',
    'tree|Games/save|102400',
    'tree|Almost|1048575',
    'tree|t6|6',
    'tree|t5|5',
    'tree|t4|4',
    'tree|t3|3',
];

describe('the folder tree of the Windows storage step', function () {
    it('lists folders 4 levels deep inside the big ones, biggest first, one per label', function (string $engine) {
        $lines = sbwLines(sbwRun('tree', $engine));

        expect(sbwKind($lines, 'tree'))->toBe(SBW_TREE)
            ->and(sbwKind($lines, 'home'))->toBe(['home|6044692', 'home|Media|2667520', 'home|AppData|1280000', 'home|Games|1048576', 'home|Almost|1048575', 'home|t6|6', 'home|t5|5', 'home|t4|4', 'home|t3|3']);
    })->with(['engine', 'script'])->skip(sbwMissing(), 'PowerShell is not installed here');

    it('puts the tree lines right after the sub lines', function () {
        $lines = sbwLines(sbwRun('tree'));
        $kinds = array_values(array_unique(array_map(fn (string $line): string => explode('|', $line)[0], array_filter($lines, fn (string $line): bool => str_contains($line, '|')))));

        expect(array_slice($kinds, 0, 4))->toBe(['home', 'sub', 'tree', 'folder']);
    })->skip(sbwMissing(), 'PowerShell is not installed here');

    it('keeps tallies only for folders up to 4 levels inside the user folder, the same with both walkers', function () {
        $engine = sbwLines(sbwRun('tree', 'engine'));
        $script = sbwLines(sbwRun('tree', 'script'));

        expect(sbwKind($engine, 'depths'))->toBe(['depths|0,0,0,0'])
            ->and(sbwKind($engine, 'four'))->toBe(['four|AppData\Local\Temp\T1=524288000;Media\A\B\C=1310720000;Media\A\B\small=52428800'])
            ->and(array_values(array_filter($script, fn (string $line): bool => ! str_starts_with($line, 'seconds:'))))
            ->toBe(array_values(array_filter($engine, fn (string $line): bool => ! str_starts_with($line, 'seconds:'))));
    })->skip(sbwMissing(), 'PowerShell is not installed here');
});

describe('the order and the time limit of the Windows storage step', function () {
    it('measures your user folder first, then Windows, Program Files and ProgramData, then the other folders', function () {
        expect(sbwKind(sbwLines(sbwRun('order')), 'order'))->toBe([
            'order|Users/me/Desktop',
            'order|Users/me/Pictures',
            'order|Users/me/Downloads',
            'order|Users/me/AppData/Roaming',
            'order|Users/me/AppData/Local/Temp',
            'order|Users/me/AppData',
            'order|Users/me',
            'order|Users/me/Documents',
            'order|Users/me/AppData/Local',
            'order|Users',
            'order|Windows',
            'order|Program Files',
            'order|Program Files (x86)',
            'order|ProgramData',
            'order|aaa',
            'order|Games',
        ]);
    })->skip(sbwMissing(), 'PowerShell is not installed here');

    it('goes back to a folder that needed more than its share and finishes it while time is left', function (string $engine) {
        $lines = sbwLines(sbwRun('slow', $engine));
        $calls = array_column(array_map(fn (string $line): array => explode('|', $line), sbwKind($lines, 'calls')), 2, 1);

        expect($lines)->toContain('scan: complete')->toContain('library|Local|90')->toContain('root|Windows|750')->toContain('root|Users|1380')
            ->and((int) $calls['Users/me/AppData/Local'])->toBeGreaterThanOrEqual(2)
            ->and(sbwKind($lines, 'missing'))->toBe([]);
    })->with(['engine', 'script'])->skip(sbwMissing(), 'PowerShell is not installed here');

    it('stops at the limit, keeps what it measured and lists what it could not finish', function (string $engine) {
        $lines = sbwLines(sbwRun('capped', $engine));
        $took = (int) explode('|', sbwKind($lines, 'took')[0])[1];

        expect($lines)->toContain('scan: partial (time limit of 8 seconds reached)')->toContain('limit: 8')
            ->toContain('missing|root/Windows')->toContain('home|1300')->toContain('folder|Downloads|500')->toContain('root|Users|1380')
            ->toContain('root|Program Files|120')->toContain('roots_total|2360')
            ->and($took)->toBeLessThanOrEqual(8 + 2);
    })->with(['engine', 'script'])->skip(sbwMissing(), 'PowerShell is not installed here');

    it('carries on where it stopped, with the same totals as one uninterrupted pass', function (string $engine) {
        $lines = sbwLines(sbwRun('resume', $engine));

        expect($lines)->toContain('none|False|0')->toContain('many|True|300')->toContain('same|True')
            ->and(sbwKind($lines, 'profile'))->toBe(['profile|1331200'
                .'|Archives=307200/1;Other=778240/10;Photos=245760/2'
                .'|bin=51200/1;c=409600/1;dat=117760/3;html=61440/1;jpg=204800/1;pdf=102400/1;png=40960/1;tmp=20480/1;txt=10240/1;zip=307200/1'
                .'|5120/1'
                .'|AppData=138240;Desktop=10240;Documents=153600;Downloads=512000;Pictures=40960;Projects=471040'
                .'|AppData\Local=92160;AppData\LocalLow=15360;AppData\Roaming=30720;Documents\deep=51200;Projects\app=409600;Projects\site=61440']);
    })->with(['engine', 'script'])->skip(sbwMissing(), 'PowerShell is not installed here');
});
