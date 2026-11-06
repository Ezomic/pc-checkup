<?php

declare(strict_types=1);

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Tests\Support\ScriptTemp;

function wcwMissing(): bool
{
    return trim((string) shell_exec('command -v pwsh 2>/dev/null')) === '' && trim((string) shell_exec('command -v powershell.exe 2>/dev/null')) === '';
}

function wcwFunction(string $script, string $name): string
{
    preg_match('/^function '.preg_quote($name, '/').'\b.*?^\}$/ms', $script, $match);

    expect($match)->not->toBeEmpty();

    return $match[0];
}

function wcwSection(string $script, string $title): string
{
    preg_match('/^Add-Section \''.preg_quote($title, '/').'\' \{.*?^\}$/ms', $script, $match);

    expect($match)->not->toBeEmpty();

    return $match[0];
}

function wcwRun(string $case): ProcessResult
{
    $script = str_replace("\r\n", "\n", (string) file_get_contents(base_path('scripts/windows-checkup.ps1')));
    $functions = implode("\n", array_map(fn (string $name): string => wcwFunction($script, $name), [
        'One-Line', 'Explain-Error', 'Add-Section', 'Read-CappedJob', 'Get-WifiQualityLines', 'Get-BootRowsFromXml', 'Get-BootTimeLines', 'Get-CrashAppLines',
    ]));
    $sections = implode("\n", array_map(fn (string $title): string => wcwSection($script, $title), ['Wi-Fi signal', 'Boot time', 'Recent app crashes']));

    $dir = ScriptTemp::dir('pcc-wcw');
    file_put_contents($dir.'/step.ps1', "param([string]\$Case)\n".$functions."\n".<<<'PS'
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
$today = [datetime]'2026-11-06'
function Show($Lines) { @($Lines) -join "`n" }
$netshBase = @(
    '',
    'There is 1 interface on the system:',
    '',
    '    Name                   : Wi-Fi',
    '    Description            : Intel(R) Wi-Fi 6 AX201 160MHz',
    '    GUID                   : 11111111-2222-3333-4444-555555555555',
    '    Physical address       : aa:bb:cc:dd:ee:ff',
    '    State                  : connected',
    '    SSID                   : Signal : 99%',
    '    BSSID                  : 12:34:56:78:9a:bc',
    '    Network type           : Infrastructure',
    '    Radio type             : 802.11ac',
    '    Authentication         : WPA2-Personal',
    '    Cipher                 : CCMP',
    '    Connection mode        : Profile',
    '    Channel                : 36',
    '    Receive rate (Mbps)    : 585',
    '    Transmit rate (Mbps)   : 585',
    '    Signal                 : 82%',
    '    Profile                : Channel : 6'
)
switch ($Case) {
    'wifi-english' { Show (Get-WifiQualityLines $netshBase) }
    'wifi-band-field' { Show (Get-WifiQualityLines ($netshBase + '    Band                   : 2.4 GHz')) }
    'wifi-band-comma' { Show (Get-WifiQualityLines ($netshBase + '    Band                   : 2,4 GHz')) }
    'wifi-dutch' {
        Show (Get-WifiQualityLines @('    Naam                   : Wi-Fi', '    SSID                   : 100%', '    Kanaal                 : 6', '    Signaal                : 64%', '    Profiel                : 100%'))
    }
    'wifi-german' { Show (Get-WifiQualityLines @('    SSID                   : x', '    Kanal                  : 149', '    Signal                 : 40%')) }
    'wifi-ssid-only' { Show (Get-WifiQualityLines @('    State                  : connected', '    SSID                   : 77%', '    Profile                : 66%', '    Channel                : 11')) }
    'wifi-disconnected' { Show (Get-WifiQualityLines @('    Name                   : Wi-Fi', '    State                  : disconnected')) }
    'wifi-service-off' { Show (Get-WifiQualityLines @('The Wireless AutoConfig Service (wlansvc) is not running.')) }
    'wifi-empty' { Show (Get-WifiQualityLines @()) }
    'wifi-null' { Show (Get-WifiQualityLines $null) }
    'wifi-bad-signal' {
        foreach ($value in '101%', 'abc', '-5%', '8 %', '50', '12.5%') { Show (Get-WifiQualityLines @("    Signal                 : $value")) }
    }
    'wifi-first-wins' { Show (Get-WifiQualityLines @('    Signal                 : 30%', '    Channel                : 1', '    Signal                 : 90%', '    Channel                : 36')) }
    'wifi-channel-edges' {
        foreach ($channel in 0, 1, 14, 15, 31, 32, 177, 178, 999, 1000) { "$channel => " + ((Get-WifiQualityLines @("    Signal : 50%", "    Channel : $channel")) -join ',') }
    }
    'boot' {
        Show (Get-BootTimeLines @('2026-11-05|52000', '2026-11-04|40000', '2026-11-03|90500', '2026-11-02|45000', '2026-11-01|41000', '2026-10-01|999000') $today)
    }
    'xml' {
        function New-BootXml($At, $Ms) {
            $time = if ($At) { "<TimeCreated SystemTime=`"$At`" />" } else { '' }
            $boot = if ($null -ne $Ms) { "<Data Name=`"BootTime`">$Ms</Data>" } else { '' }
            "<Event xmlns=`"http://schemas.microsoft.com/win/2004/08/events/event`"><System><Provider Name=`"Microsoft-Windows-Diagnostics-Performance`" /><EventID>100</EventID>$time<Computer>PC</Computer></System><EventData><Data Name=`"BootTsVersion`">2</Data><Data Name=`"BootStartTime`">2026-11-05T07:59:00Z</Data>$boot<Data Name=`"MainPathBootTime`">30000</Data></EventData></Event>"
        }
        $xml = @(
            (New-BootXml '2026-11-05T12:00:00.1234567Z' 52000),
            (New-BootXml '2026-11-04T12:00:00.0000000Z' 40000),
            (New-BootXml '2026-11-03T12:00:00Z' $null),
            (New-BootXml $null 45000),
            '<not xml',
            ''
        )
        $rows = @(Get-BootRowsFromXml $xml)
        $rows
        '--'
        Show (Get-BootTimeLines $rows $today)
    }
    'boot-even' { Show (Get-BootTimeLines @('2026-11-05|50000', '2026-11-04|70000') $today) }
    'boot-one' { Show (Get-BootTimeLines @('2026-11-05|47400') $today) }
    'boot-bad' { Show (Get-BootTimeLines @('2026-11-05|0', '2026-11-04|abc', '2026-11-04|', 'junk', '2026-11-03|-4000', '2026-11-02|9999999999', '2026-11-01|8000000', '2026-10-31|400', '|5000') $today) }
    'boot-skips-bad-uses-good' { Show (Get-BootTimeLines @('2026-11-05|junk', '2026-11-04|61000') $today) }
    'boot-none' { Show (Get-BootTimeLines @() $today) }
    'boot-null' { Show (Get-BootTimeLines $null $today) }
    'crashes' {
        $names = @('chrome.exe', 'Chrome.exe', 'notepad.exe', "we`tir|d\na/me.exe", '', '   ', '..', 'zoom.exe', 'chrome.exe') + (1..12 | ForEach-Object { 'app{0:00}.exe' -f $_ })
        Show (Get-CrashAppLines $names $today 30 $false $null)
    }
    'crashes-long' { Show (Get-CrashAppLines @(('L' * 200)) $today 30 $false $null) }
    'crashes-unicode' { Show (Get-CrashAppLines @("Caf$([char]0xE9).exe", "Caf$([char]0xE9).exe") $today 30 $false $null) }
    'crashes-none' { Show (Get-CrashAppLines @() $today 30 $false $null) }
    'crashes-null' { Show (Get-CrashAppLines $null $today 30 $false $null) }
    'crashes-capped' { Show (Get-CrashAppLines @('a.exe') $today 30 $true $null) }
    'crashes-failed' { Show (Get-CrashAppLines @('a.exe') $today 30 $false 'unavailable (needs admin)') }
    'job-ok' { $r = Read-CappedJob { 'one'; 'two' } 20; "$($r.Value -join ',')|$($r.Note)" }
    'job-args' { $r = Read-CappedJob { param($A, $B) "$A-$B" } 20 @('x', 'y'); "$($r.Value -join ',')|$($r.Note)" }
    'job-slow' {
        $watch = [Diagnostics.Stopwatch]::StartNew()
        $r = Read-CappedJob { Start-Sleep -Seconds 60 } 2
        "$($null -eq $r.Value)|$($r.Note)|$($watch.Elapsed.TotalSeconds -lt 20)"
    }
    'job-error' { $r = Read-CappedJob { throw 'boom' } 20; "$($null -eq $r.Value)|$($r.Note)" }
    'sections' { . (Join-Path $PSScriptRoot 'sections.ps1') }
}
PS);
    file_put_contents($dir.'/sections.ps1', "\$Body = New-Object System.Collections.Generic.List[string]\n".$sections."\n\$Body -join \"\`n\"\n");

    $pwsh = trim((string) shell_exec('command -v pwsh 2>/dev/null'));
    $windows = trim((string) shell_exec('command -v powershell.exe 2>/dev/null'));
    $convert = fn (string $path): string => $pwsh !== '' ? $path : trim((string) shell_exec('wslpath -w '.escapeshellarg($path)));
    $command = $pwsh !== '' ? [$pwsh, '-NoProfile', '-File'] : [$windows, '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File'];

    return Process::timeout(180)->run([...$command, $convert($dir.'/step.ps1'), $case]);
}

/**
 * @return list<string>
 */
function wcwLines(string $case): array
{
    $result = wcwRun($case)->throw();

    return array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", '', $result->output()))), fn (string $line): bool => $line !== ''));
}

describe('the Windows Wi-Fi step', function () {
    it('keeps only the signal and band, and never the network or profile name', function () {
        $lines = wcwLines('wifi-english');

        expect($lines)->toBe(['format: 1', 'signal-percent: 82', 'band: 5 GHz']);
    })->skip(wcwMissing(), 'PowerShell is not installed here');

    it('prefers the band the system reports, with a decimal comma read as a point', function () {
        expect(wcwLines('wifi-band-field'))->toBe(['format: 1', 'signal-percent: 82', 'band: 2.4 GHz'])
            ->and(wcwLines('wifi-band-comma'))->toBe(['format: 1', 'signal-percent: 82', 'band: 2.4 GHz']);
    })->skip(wcwMissing(), 'PowerShell is not installed here');

    it('reads the Dutch and German labels', function () {
        expect(wcwLines('wifi-dutch'))->toBe(['format: 1', 'signal-percent: 64', 'band: 2.4 GHz'])
            ->and(wcwLines('wifi-german'))->toBe(['format: 1', 'signal-percent: 40', 'band: 5 GHz']);
    })->skip(wcwMissing(), 'PowerShell is not installed here');

    it('is not fooled by a network or profile name shaped like a value', function () {
        expect(wcwLines('wifi-ssid-only'))->toBe(['format: 1', 'status: not connected or could not check']);
    })->skip(wcwMissing(), 'PowerShell is not installed here');

    it('says it could not tell when there is nothing to read', function (string $case) {
        expect(wcwLines($case))->toBe(['format: 1', 'status: not connected or could not check']);
    })->with(['wifi-disconnected', 'wifi-service-off', 'wifi-empty', 'wifi-null'])->skip(wcwMissing(), 'PowerShell is not installed here');

    it('refuses a signal that is not a percentage from 0 to 100', function () {
        $lines = wcwLines('wifi-bad-signal');

        expect(array_values(array_filter($lines, fn (string $line): bool => $line === 'format: 1')))->toHaveCount(6)
            ->and(array_values(array_filter($lines, fn (string $line): bool => str_starts_with($line, 'signal-percent'))))->toBe([]);
    })->skip(wcwMissing(), 'PowerShell is not installed here');

    it('uses the first interface that has a signal', function () {
        expect(wcwLines('wifi-first-wins'))->toBe(['format: 1', 'signal-percent: 30', 'band: 2.4 GHz']);
    })->skip(wcwMissing(), 'PowerShell is not installed here');

    it('derives the band from the channel number only inside real channel ranges', function () {
        expect(wcwLines('wifi-channel-edges'))->toBe([
            '0 => format: 1,signal-percent: 50',
            '1 => format: 1,signal-percent: 50,band: 2.4 GHz',
            '14 => format: 1,signal-percent: 50,band: 2.4 GHz',
            '15 => format: 1,signal-percent: 50',
            '31 => format: 1,signal-percent: 50',
            '32 => format: 1,signal-percent: 50,band: 5 GHz',
            '177 => format: 1,signal-percent: 50,band: 5 GHz',
            '178 => format: 1,signal-percent: 50',
            '999 => format: 1,signal-percent: 50',
            '1000 => format: 1,signal-percent: 50',
        ]);
    })->skip(wcwMissing(), 'PowerShell is not installed here');
});

describe('the Windows boot time step', function () {
    it('takes the median of the five newest boots and leaves older ones out', function () {
        expect(wcwLines('boot'))->toBe(['format: 1', 'scanned: 2026-11-06', 'boot-seconds: 45', 'boot-latest-seconds: 52', 'boot-latest: 2026-11-05', 'boot-samples: 5']);
    })->skip(wcwMissing(), 'PowerShell is not installed here');

    it('averages the two middle boots of an even number', function () {
        expect(wcwLines('boot-even'))->toContain('boot-seconds: 60')->toContain('boot-samples: 2');
        expect(wcwLines('boot-one'))->toBe(['format: 1', 'scanned: 2026-11-06', 'boot-seconds: 47', 'boot-latest-seconds: 47', 'boot-latest: 2026-11-05', 'boot-samples: 1']);
    })->skip(wcwMissing(), 'PowerShell is not installed here');

    it('skips unreadable and impossible rows', function () {
        expect(wcwLines('boot-bad'))->toBe(['format: 1', 'scanned: 2026-11-06', 'boot-samples: 0'])
            ->and(wcwLines('boot-skips-bad-uses-good'))->toContain('boot-seconds: 61')->toContain('boot-samples: 1');
    })->skip(wcwMissing(), 'PowerShell is not installed here');

    it('reads the boot time from the XML of the event, by name', function () {
        expect(wcwLines('xml'))->toBe([
            '2026-11-05|52000', '2026-11-04|40000', '2026-11-03|', '--',
            'format: 1', 'scanned: 2026-11-06', 'boot-seconds: 46', 'boot-latest-seconds: 52', 'boot-latest: 2026-11-05', 'boot-samples: 2',
        ]);
    })->skip(wcwMissing(), 'PowerShell is not installed here');

    it('reports no boots as zero samples', function () {
        expect(wcwLines('boot-none'))->toBe(['format: 1', 'scanned: 2026-11-06', 'boot-samples: 0'])
            ->and(wcwLines('boot-null'))->toBe(['format: 1', 'scanned: 2026-11-06', 'boot-samples: 0']);
    })->skip(wcwMissing(), 'PowerShell is not installed here');
});

describe('the Windows crash step', function () {
    it('counts program names, merges case, cleans them and lists at most ten', function () {
        $lines = wcwLines('crashes');

        expect(array_slice($lines, 0, 5))->toBe(['format: 1', 'scanned: 2026-11-06', 'window-days: 30', 'scan: complete', 'total: 18'])
            ->and(array_slice($lines, 5, 3))->toBe(['app|chrome.exe|3', 'app|app01.exe|1', 'app|app02.exe|1'])
            ->and(count($lines) - 5)->toBe(10)
            ->and(implode("\n", $lines))->not->toContain('\\')->not->toContain('..');
    })->skip(wcwMissing(), 'PowerShell is not installed here');

    it('keeps unicode, cuts long names and handles nothing at all', function () {
        expect(wcwLines('crashes-unicode')[5])->toBe("app|Caf\u{e9}.exe|2")
            ->and(wcwLines('crashes-long')[5])->toBe('app|'.str_repeat('L', 80).'|1')
            ->and(wcwLines('crashes-none'))->toBe(['format: 1', 'scanned: 2026-11-06', 'window-days: 30', 'scan: complete', 'total: 0'])
            ->and(wcwLines('crashes-null'))->toBe(['format: 1', 'scanned: 2026-11-06', 'window-days: 30', 'scan: complete', 'total: 0']);
    })->skip(wcwMissing(), 'PowerShell is not installed here');

    it('flags a read that stopped at its limit and one that failed', function () {
        expect(wcwLines('crashes-capped'))->toContain('scan: partial (event limit reached)')
            ->and(wcwLines('crashes-failed'))->toBe(['format: 1', 'scanned: 2026-11-06', 'window-days: 30', 'scan: could not check']);
    })->skip(wcwMissing(), 'PowerShell is not installed here');
});

describe('the time cap on the Windows reads', function () {
    it('returns what the job printed, with arguments', function () {
        expect(wcwLines('job-ok'))->toBe(['one,two|'])->and(wcwLines('job-args'))->toBe(['x-y|']);
    })->skip(wcwMissing(), 'PowerShell is not installed here');

    it('gives up on a job that takes too long', function () {
        expect(wcwLines('job-slow'))->toBe(['True|took longer than 2 seconds|True']);
    })->skip(wcwMissing(), 'PowerShell is not installed here');

    it('turns a failing job into a note instead of an error', function () {
        $line = wcwLines('job-error')[0];

        expect($line)->toStartWith('True|unavailable (')->toContain('boom');
    })->skip(wcwMissing(), 'PowerShell is not installed here');

    it('runs the real sections of the script on this machine without failing', function () {
        $output = implode("\n", wcwLines('sections'));

        expect($output)->toContain('## Wi-Fi signal')->toContain('## Boot time')->toContain('## Recent app crashes')
            ->and(substr_count($output, 'format: 1'))->toBe(3)
            ->and($output)->not->toContain('(nothing to show)');
    })->skip(wcwMissing(), 'PowerShell is not installed here');
});

describe('the Windows script as a whole', function () {
    it('reads no saved network, password or profile', function () {
        $script = (string) file_get_contents(base_path('scripts/windows-checkup.ps1'));

        expect($script)->toContain('netsh wlan show interfaces')
            ->not->toContain('wlan show profile')->not->toContain('key=clear')->not->toContain('wlan export');
    });

    it('adds the three sections after the crashes section and keeps the update check', function () {
        $script = (string) file_get_contents(base_path('scripts/windows-checkup.ps1'));

        expect(strpos($script, "Add-Section 'Wi-Fi signal'"))->toBeGreaterThan(strpos($script, "Add-Section 'Crashes and unexpected shutdowns"))
            ->and($script)->toContain("Add-Section 'Boot time'")->toContain("Add-Section 'Recent app crashes'")
            ->toContain('Invoke-UpdateCheck');
    });
});
