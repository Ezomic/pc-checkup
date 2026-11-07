<?php

declare(strict_types=1);

use App\Services\Reports\ReportNetworkSettings;
use App\Services\Reports\ReportRemoteAccess;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Tests\Support\ScriptTemp;

function hwsMissing(): bool
{
    return trim((string) shell_exec('command -v pwsh 2>/dev/null')) === '' && trim((string) shell_exec('command -v powershell.exe 2>/dev/null')) === '';
}

function hwsScript(): string
{
    return str_replace("\r\n", "\n", (string) file_get_contents(base_path('scripts/windows-checkup.ps1')));
}

function hwsPart(string $pattern): string
{
    preg_match($pattern, hwsScript(), $match);

    expect($match)->not->toBeEmpty();

    return $match[0];
}

function hwsRun(string $case): ProcessResult
{
    $functions = implode("\n", array_map(fn (string $name): string => hwsPart('/^function '.preg_quote($name, '/').'\b.*?^\}$/ms'), [
        'One-Line', 'Explain-Error', 'Add-Section', 'Read-CappedJob', 'Get-RemoteToolLines', 'Get-HostsSummaryLines', 'Get-ProxyHostText',
        'Get-ProxyLines', 'Get-DnsServerLines', 'Get-AntivirusProductLines',
    ]));
    $variables = implode("\n", [
        hwsPart('/^\$RemoteTools = @\(.*?^\)$/ms'),
        hwsPart('/^\$WatchedSites = @\(.*?^\)$/ms'),
        hwsPart('/^\$HostsLineLimit = \d+$/m'),
    ]);
    $sections = implode("\n", array_map(fn (string $title): string => hwsPart('/^Add-Section \''.preg_quote($title, '/').'\' \{.*?^\}$/ms'), ['Remote access tools', 'Network settings', 'Antivirus products']));

    $dir = ScriptTemp::dir('pcc-hws');
    file_put_contents($dir.'/step.ps1', "param([string]\$Case)\n".$variables."\n".$functions."\n".<<<'PS'
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
function Show($Lines) { @($Lines) -join "`n" }
switch ($Case) {
    'remote' {
        Show (Get-RemoteToolLines @('i|TeamViewer 15', 'i|Secret Banking App', 'i|AnyDesk', 'r|AnyDesk', 'r|explorer', 'i|LogMeIn Hamachi', 'r|LMIGuardianSvc', 'r|rustdesk', 'i|Parsec', 'i|ParsecSoda', 'r|winvnc', 'i|Microsoft Office', 'x|UltraViewer', 'i|') $null)
    }
    'remote-vpn' { Show (Get-RemoteToolLines @('i|Radmin VPN 1.4', 'r|RadminVPN', 'i|Radmin Viewer 3.5') $null) }
    'remote-running-only' { Show (Get-RemoteToolLines @('r|UltraViewer_Desktop', 'r|AA_v3') $null) }
    'remote-none' { Show (Get-RemoteToolLines @('i|Microsoft Edge', 'r|svchost') $null) }
    'remote-empty' { Show (Get-RemoteToolLines @() $null) }
    'remote-null' { Show (Get-RemoteToolLines $null $null) }
    'remote-failed' { Show (Get-RemoteToolLines @('i|AnyDesk') 'took longer than 25 seconds') }
    'remote-names' { $RemoteTools | ForEach-Object { $_.Name } }
    'sites' { $WatchedSites }
    'hosts' {
        Show (Get-HostsSummaryLines @(
            '# Copyright (c) 1993-2009 Microsoft Corp.',
            '#      102.54.94.97     rhino.acme.com          # source server',
            '127.0.0.1       localhost',
            '::1             localhost',
            '255.255.255.255 broadcasthost',
            '',
            '   ',
            '10.0.0.5 nas.home printer.home   # my devices',
            '6.6.6.6 www.google.com google.com. LOGIN.ING.NL',
            '0.0.0.0 vortex.data.microsoft.com',
            '::1 telemetry.apple.com',
            '127.0.0.2 paypal.com',
            '6.6.6.6 notgoogle.com google.com.evil.example',
            '6.6.6.6',
            'garbage'
        ))
    }
    'hosts-default' { Show (Get-HostsSummaryLines @('127.0.0.1 localhost', '::1 localhost', '255.255.255.255 broadcasthost', 'fe80::1%lo0 localhost')) }
    'hosts-empty' { Show (Get-HostsSummaryLines @()) }
    'hosts-null' { Show (Get-HostsSummaryLines $null) }
    'hosts-huge' {
        $lines = New-Object System.Collections.Generic.List[string]
        for ($i = 0; $i -lt 50010; $i++) { $lines.Add("0.0.0.0 ad$i.example") }
        $lines.Add('6.6.6.6 ing.nl')
        Show (Get-HostsSummaryLines $lines.ToArray())
    }
    'proxy-host' {
        foreach ($value in 'proxy.example.com:8080', 'http=p1.example:80;https=p2.example:443', 'http://user:secret@proxy.example:3128/path?x=1', 'http://u:pa/ss@wpad.example/x.pac','socks=10.0.0.1:1080', '[::1]:8080', 'bad host:80', '', ';;', 'p<x>:1', ('a' * 101)) {
            "[$value] => [$(Get-ProxyHostText $value)]"
        }
    }
    'proxy-off' { Show (Get-ProxyLines '0' 'proxy.example:80' '') }
    'proxy-missing' { Show (Get-ProxyLines '' '' '') }
    'proxy-on' { Show (Get-ProxyLines '1' 'proxy.example:80' '') }
    'proxy-on-no-host' { Show (Get-ProxyLines '1' '' '') }
    'proxy-script' { Show (Get-ProxyLines '0' 'proxy.example:80' 'http://wpad.example.net/wpad.dat?token=abc') }
    'proxy-both' { Show (Get-ProxyLines '1' 'user:pw@10.0.0.5:3128' 'http://wpad.example.net/proxy.pac') }
    'dns' { Show (Get-DnsServerLines @('192.168.1.1', '8.8.8.8', '192.168.1.1', 'not-an-ip', '', 'fe80::1%12', 'fec0:0:0:ffff::1', '999.1.1.1', '1.1.1.1', '9.9.9.9', '10.0.0.1', '10.0.0.2', '10.0.0.3', '10.0.0.4', '10.0.0.5')) }
    'dns-none' { Show (Get-DnsServerLines @()) }
    'dns-null' { Show (Get-DnsServerLines $null) }
    'av' { Show (Get-AntivirusProductLines @('397568|Windows Defender', '266240|Norton 360', '262144|Old AV', '393472|norton 360', "397568|Bad`tName|x`a", 'junk', '|Nameless', '397568|', '99999999999|Too Big') $null) }
    'av-server' { Show (Get-AntivirusProductLines @() 'unavailable (Invalid namespace )') }
    'av-failed' { Show (Get-AntivirusProductLines @('397568|Windows Defender') 'took longer than 20 seconds') }
    'av-none' { Show (Get-AntivirusProductLines @() $null) }
    'sections' { . (Join-Path $PSScriptRoot 'sections.ps1') }
}
PS);
    file_put_contents($dir.'/sections.ps1', "\$Body = New-Object System.Collections.Generic.List[string]\n".$sections."\n\$Body -join \"\`n\"\n");

    $pwsh = trim((string) shell_exec('command -v pwsh 2>/dev/null'));
    $windows = trim((string) shell_exec('command -v powershell.exe 2>/dev/null'));
    $convert = fn (string $path): string => $pwsh !== '' ? $path : trim((string) shell_exec('wslpath -w '.escapeshellarg($path)));
    $command = $pwsh !== '' ? [$pwsh, '-NoProfile', '-File'] : [$windows, '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File'];

    return Process::timeout(240)->run([...$command, $convert($dir.'/step.ps1'), $case]);
}

/**
 * @return list<string>
 */
function hwsLines(string $case): array
{
    $result = hwsRun($case)->throw();

    return array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", '', $result->output()))), fn (string $line): bool => $line !== ''));
}

describe('the Windows remote-access step', function () {
    it('prints only known tool names, with how each was found', function () {
        $lines = hwsLines('remote');

        expect($lines)->toBe([
            'format: 1',
            'scan: complete',
            'tool|TeamViewer|installed',
            'tool|AnyDesk|installed,running',
            'tool|RustDesk|running',
            'tool|UltraVNC|running',
            'tool|Parsec|installed',
        ])->and(implode("\n", $lines))->not->toContain('Secret')->not->toContain('Office')->not->toContain('LogMeIn');
    })->skip(hwsMissing(), 'PowerShell is not installed here');

    it('finds a tool that only runs, and says when nothing is found', function () {
        expect(hwsLines('remote-running-only'))->toBe(['format: 1', 'scan: complete', 'tool|UltraViewer|running', 'tool|Ammyy Admin|running'])
            ->and(hwsLines('remote-none'))->toBe(['format: 1', 'scan: complete'])
            ->and(hwsLines('remote-empty'))->toBe(['format: 1', 'scan: complete'])
            ->and(hwsLines('remote-null'))->toBe(['format: 1', 'scan: complete']);
    })->skip(hwsMissing(), 'PowerShell is not installed here');

    it('leaves out Radmin VPN, which is not remote control', function () {
        expect(hwsLines('remote-vpn'))->toBe(['format: 1', 'scan: complete', 'tool|Radmin|installed']);
    })->skip(hwsMissing(), 'PowerShell is not installed here');

    it('says it could not check when the read failed', function () {
        expect(hwsLines('remote-failed'))->toBe(['format: 1', 'scan: could not check']);
    })->skip(hwsMissing(), 'PowerShell is not installed here');

    it('uses exactly the names the app knows', function () {
        expect(hwsLines('remote-names'))->toBe(ReportRemoteAccess::KNOWN);
    })->skip(hwsMissing(), 'PowerShell is not installed here');
});

describe('the Windows network step', function () {
    it('counts extra host names and names only the watched sites', function () {
        $lines = hwsLines('hosts');

        expect($lines)->toBe([
            'hosts: complete',
            'hosts-entries: 10',
            'hosts-redirected: google.com, ing.nl',
            'hosts-blocked: microsoft.com, apple.com, paypal.com',
        ])->and(implode("\n", $lines))->not->toContain('nas.home')->not->toContain('6.6.6.6')->not->toContain('evil');
    })->skip(hwsMissing(), 'PowerShell is not installed here');

    it('counts nothing for the default entries or an empty file', function (string $case) {
        expect(hwsLines($case))->toBe(['hosts: complete', 'hosts-entries: 0']);
    })->with(['hosts-default', 'hosts-empty', 'hosts-null'])->skip(hwsMissing(), 'PowerShell is not installed here');

    it('stops at its line limit and says so', function () {
        expect(hwsLines('hosts-huge'))->toBe(['hosts: partial (only the first 50000 lines were read)', 'hosts-entries: 50000']);
    })->skip(hwsMissing(), 'PowerShell is not installed here');

    it('uses exactly the watched sites the app knows', function () {
        expect(hwsLines('sites'))->toBe(ReportNetworkSettings::WATCHED_SITES);
    })->skip(hwsMissing(), 'PowerShell is not installed here');

    it('keeps only the host of a proxy, never a password or a path', function () {
        expect(hwsLines('proxy-host'))->toBe([
            '[proxy.example.com:8080] => [proxy.example.com:8080]',
            '[http=p1.example:80;https=p2.example:443] => [p1.example:80]',
            '[http://user:secret@proxy.example:3128/path?x=1] => [proxy.example:3128]',
            '[http://u:pa/ss@wpad.example/x.pac] => [wpad.example]',
            '[socks=10.0.0.1:1080] => [10.0.0.1:1080]',
            '[[::1]:8080] => [[::1]:8080]',
            '[bad host:80] => []',
            '[] => []',
            '[;;] => []',
            '[p<x>:1] => []',
            '['.str_repeat('a', 101).'] => []',
        ]);
    })->skip(hwsMissing(), 'PowerShell is not installed here');

    it('says whether a proxy is on and where it comes from', function () {
        expect(hwsLines('proxy-off'))->toBe(['proxy: off'])
            ->and(hwsLines('proxy-missing'))->toBe(['proxy: off'])
            ->and(hwsLines('proxy-on'))->toBe(['proxy: on', 'proxy-host: proxy.example:80'])
            ->and(hwsLines('proxy-on-no-host'))->toBe(['proxy: on'])
            ->and(hwsLines('proxy-script'))->toBe(['proxy: on', 'proxy-script-host: wpad.example.net'])
            ->and(hwsLines('proxy-both'))->toBe(['proxy: on', 'proxy-host: 10.0.0.5:3128', 'proxy-script-host: wpad.example.net']);
    })->skip(hwsMissing(), 'PowerShell is not installed here');

    it('lists each DNS address once, at most eight, and skips what is not an address', function () {
        expect(hwsLines('dns'))->toBe([
            'dns: complete', 'dns-server: 192.168.1.1', 'dns-server: 8.8.8.8', 'dns-server: fe80::1%12', 'dns-server: fec0:0:0:ffff::1',
            'dns-server: 1.1.1.1', 'dns-server: 9.9.9.9', 'dns-server: 10.0.0.1', 'dns-server: 10.0.0.2',
        ])
            ->and(hwsLines('dns-none'))->toBe(['dns: complete'])
            ->and(hwsLines('dns-null'))->toBe(['dns: complete']);
    })->skip(hwsMissing(), 'PowerShell is not installed here');
});

describe('the Windows antivirus step', function () {
    it('reads which programs are active from the product state and merges names', function () {
        expect(hwsLines('av'))->toBe([
            'format: 1', 'status: complete',
            'product|Windows Defender|on',
            'product|Norton 360|on',
            'product|Old AV|off',
            'product|Bad Name x|on',
        ]);
    })->skip(hwsMissing(), 'PowerShell is not installed here');

    it('says the list is not available on Windows Server and could not check on other errors', function () {
        expect(hwsLines('av-server'))->toBe(['format: 1', 'status: not available'])
            ->and(hwsLines('av-failed'))->toBe(['format: 1', 'status: could not check'])
            ->and(hwsLines('av-none'))->toBe(['format: 1', 'status: complete']);
    })->skip(hwsMissing(), 'PowerShell is not installed here');
});

describe('the new Windows sections', function () {
    it('run for real on this machine without failing', function () {
        $output = implode("\n", hwsLines('sections'));

        expect($output)->toContain('## Remote access tools')->toContain('## Network settings')->toContain('## Antivirus products')
            ->toContain('scan: complete')->toMatch('/hosts: (complete|could not check)/')->toMatch('/proxy: (on|off|could not check)/')
            ->toMatch('/dns: (complete|could not check)/')->toMatch('/status: (complete|not available|could not check)/')
            ->not->toContain('(nothing to show)');
    })->skip(hwsMissing(), 'PowerShell is not installed here');

    it('come after the existing sections and keep the update check', function () {
        $script = hwsScript();

        expect(strpos($script, "Add-Section 'Remote access tools'"))->toBeGreaterThan(strpos($script, "Add-Section 'Recent app crashes'"))
            ->and($script)->toContain('Invoke-UpdateCheck')
            ->and(hwsPart('/^Add-Section \'Network settings\' \{.*?^\}$/ms'))->toContain('-TotalCount ($HostsLimit + 1)')
            ->and(preg_match('/[^\x00-\x7F]/', $script))->toBe(0);
    });
});
