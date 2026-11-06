<?php

declare(strict_types=1);

use App\Services\Reports\ReportDetails;
use App\Services\Reports\ReportFacts;
use App\Services\Reports\ReportSections;
use Illuminate\Support\Facades\Process;

function whsScript(): string
{
    return str_replace("\r\n", "\n", (string) file_get_contents(base_path('scripts/windows-checkup.ps1')));
}

function whsBlock(): string
{
    $script = whsScript();
    $start = (int) strpos($script, '# ---------- Windows health and security');

    return substr($script, $start, (int) strpos($script, '# ---------- Windows 11 readiness', $start) - $start);
}

function whsPowershellMissing(): bool
{
    return trim((string) shell_exec('command -v pwsh 2>/dev/null')) === '' && trim((string) shell_exec('command -v powershell.exe 2>/dev/null')) === '';
}

/**
 * @return list<string>
 */
function whsRun(string $scenario, int $callSeconds = 20): array
{
    $script = whsScript();
    $code = '';

    foreach (['One-Line', 'Explain-Error', 'Try-Run'] as $name) {
        preg_match('/^function '.preg_quote($name, '/').'.*?^\}/ms', $script, $match);
        expect($match)->not->toBeEmpty();
        $code .= $match[0]."\n";
    }

    $pwsh = trim((string) shell_exec('command -v pwsh 2>/dev/null'));
    $windows = trim((string) shell_exec('command -v powershell.exe 2>/dev/null'));
    $native = fn (string $path): string => $pwsh !== '' ? $path : trim((string) shell_exec('wslpath -w '.escapeshellarg($path)));
    $dir = sys_get_temp_dir().'/pcc-health-'.bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);
    $fake = $native(base_path('tests/Fixtures/windows-health-fake-cmdlets.psm1'));

    file_put_contents($dir.'/step.ps1', <<<PS
\$modules = Join-Path ([IO.Path]::GetTempPath()) ('pccfake-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Force (Join-Path \$modules 'PccFake') | Out-Null
Copy-Item -LiteralPath '{$fake}' -Destination (Join-Path \$modules 'PccFake/PccFake.psm1')
\$env:PSModulePath = \$modules + [IO.Path]::PathSeparator + \$env:PSModulePath
\$env:PCC_FAKE_SCENARIO = '{$scenario}'
{$code}
\$CouldNot = 'could not check'

PS.whsBlock().<<<PS

\$HealthCallSeconds = {$callSeconds}
Get-WindowsHealthSecurityLines
Remove-Item -LiteralPath \$modules -Recurse -Force -ErrorAction SilentlyContinue
PS);

    $command = $pwsh !== '' ? [$pwsh, '-NoProfile', '-File'] : [$windows, '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File'];
    $result = Process::timeout(150)->run([...$command, $native($dir.'/step.ps1')]);

    return array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", '', $result->output()))), fn (string $line): bool => $line !== ''));
}

describe('the health and security step in the script', function () {
    it('is its own section after the readiness section and before the older security sections', function () {
        $script = whsScript();

        expect($script)->toContain("Add-Section 'Windows health and security' { Get-WindowsHealthSecurityLines }")
            ->and(strpos($script, "Add-Section 'Windows health and security'"))->toBeGreaterThan(strpos($script, "Add-Section 'Windows 11 readiness'"))
            ->and(strpos($script, "Add-Section 'Windows health and security'"))->toBeLessThan(strpos($script, "Add-Section 'Security: Defender'"));
    });

    it('only reads: it changes nothing, sends nothing and keeps no serial numbers or account names', function () {
        $block = whsBlock();

        expect($block)->not->toBe('')
            ->and($block)->not->toMatch('/\b(?:Set|Remove|Enable|Disable|Install|Uninstall|Clear|Restart|Register|Out|Add|Invoke|Send|Copy|Move|Rename|Suspend|Resume)-[A-Z]|New-(?!Object\b)|Start-(?!Sleep)|Stop-/')
            ->and($block)->not->toMatch('/Invoke-WebRequest|WebClient|schtasks|manage-bde|SerialNumber|UniqueId|\$Token|\$ScanCode/i')
            ->and(preg_match('/^function Get-HealthAccountLines.*?^\}/ms', $block, $accounts))->toBe(1)
            ->and($accounts[0])->not->toMatch('/\.Name\b|\.FullName\b|\.PrincipalSource\b/')
            ->and($block)->toContain('New-Object -ComObject Shell.Application');
    });

    it('keeps to Windows PowerShell 5.1 and plain ASCII', function () {
        expect(whsScript())->toMatch('/\A[\x00-\x7F]*\z/')
            ->and(whsBlock())->not->toMatch('/\?\?|\?\.|&&|\|\||-Parallel|-AsHashtable|\$IsWindows|Get-ChildItem/');
    });

    it('runs every read in a capped runspace of at most the time budget', function () {
        $block = whsBlock();

        expect($block)->toContain('$HealthBudgetSeconds = 90')
            ->and($block)->toContain('WaitOne($limit * 1000)')
            ->and($block)->toContain('Time limit reached')
            ->and(preg_match_all('/Try-Capped \{/', $block))->toBeGreaterThanOrEqual(8)
            ->and($block)->not->toMatch('/(?<!Try-Capped )\{\s*Get-(?:PhysicalDisk|MpComputerStatus|NetFirewallProfile|SmbServerConfiguration|LocalUser|LocalGroupMember|BitLockerVolume)/');
    });
});

describe('the health and security step under PowerShell', function () {
    it('prints a line for every fact and keeps the facts it can read', function () {
        $lines = whsRun('healthy');

        expect($lines)->toContain(
            'Drives: 3',
            'Drive 1: Samsung SSD 970 EVO | type SSD | bus NVMe | size 238 GB | health Healthy | status OK | wear 4 | temperature 41 | read errors 0 | write errors 0 | power-on hours 1234',
            'Defender real-time protection: off',
            'Defender signature age days: 3',
            'Defender last quick scan age days: never',
            'Defender last full scan age days: 12',
            'Defender tamper protection: on',
            'Other antivirus: Norton 360 evil',
            'BitLocker system drive: off',
            'Firewall Domain: on',
            'Firewall Private: off',
            'Firewall Public: could not check',
            'SMBv1: on',
            'Local administrator accounts: 2',
            'Built-in Administrator account: enabled',
        );

        $keys = array_map(fn (string $line): string => explode(':', $line, 2)[0], $lines);

        expect($keys)->toContain('Remote Desktop', 'Remote Desktop network level authentication', 'Windows Update paused', 'UAC', 'UAC administrator prompt');
    })->skip(whsPowershellMissing(), 'PowerShell is not installed here');

    it('writes values the report understands, and none it should not keep', function () {
        $lines = whsRun('healthy');
        $markdown = "- Device type: laptop\n\n## Windows health and security\n\n```\n".implode("\n", $lines)."\n```\n";
        $sections = ReportSections::parse($markdown);
        $cards = array_column((new ReportDetails(new ReportFacts))->cards($markdown, [], 'laptop'), null, 'id');
        $storage = array_column($cards['storage']['rows'], 'value', 'label');
        $security = array_column($cards['security']['rows'], 'tone', 'label');

        expect($sections->text('Windows health and security'))->toContain('Drives: 3')
            ->and($storage['Odd disk'])->toBe('Warning. Back up your files and replace this drive soon.')
            ->and($storage['Backup stick (USB)'])->toBe('Unhealthy')
            ->and($security['Antivirus'])->toBe('neutral')
            ->and($security['Disk encryption'])->toBe('bad')
            ->and($security['SMBv1'])->toBe('bad')
            ->and($security['Windows Firewall'])->toBe('neutral')
            ->and(implode("\n", $lines))->not->toContain('SECRET')->not->toContain('Secret')->not->toContain("\x07");
    })->skip(whsPowershellMissing(), 'PowerShell is not installed here');

    it('says could not check for everything it is not allowed to read and never fails', function () {
        $lines = whsRun('denied');

        expect($lines)->toContain(
            'Drives: could not check',
            'Defender real-time protection: could not check',
            'Defender signature age days: could not check',
            'Other antivirus: could not check',
            'BitLocker system drive: could not check',
            'Firewall Domain: could not check',
            'Firewall Public: could not check',
            'Local administrator accounts: could not check',
            'Built-in Administrator account: could not check',
        );
    })->skip(whsPowershellMissing(), 'PowerShell is not installed here');

    it('says could not check when a PC has no drives to report', function () {
        expect(whsRun('empty'))->toContain('Drives: could not check');
    })->skip(whsPowershellMissing(), 'PowerShell is not installed here');

    it('stops waiting for a read that hangs and says so', function () {
        $started = microtime(true);
        $lines = whsRun('hang', 2);

        expect(microtime(true) - $started)->toBeLessThan(55)
            ->and($lines)->toContain('Firewall Domain: could not check', 'Firewall Private: could not check', 'Time limit reached: some values were not read', 'Drives: 3');
    })->skip(whsPowershellMissing(), 'PowerShell is not installed here');
});
