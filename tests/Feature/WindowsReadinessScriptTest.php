<?php

declare(strict_types=1);

use App\Services\Reports\ReportSections;
use App\Services\Reports\ReportUpgrade;
use Illuminate\Support\Facades\Process;

function readinessScript(): string
{
    return str_replace("\r\n", "\n", (string) file_get_contents(base_path('scripts/windows-checkup.ps1')));
}

function readinessPowershellMissing(): bool
{
    return trim((string) shell_exec('command -v pwsh 2>/dev/null')) === '' && trim((string) shell_exec('command -v powershell.exe 2>/dev/null')) === '';
}

/**
 * @return list<string>
 */
function runReadinessStep(): array
{
    $script = readinessScript();
    $pieces = ['One-Line', 'Explain-Error', 'Try-Run', 'Get-RegValue', 'Get-FirmwareMode', 'Get-SecureBootReadiness', 'Get-TpmReadiness', 'Get-MemorySlotFacts', 'Get-Windows11ReadinessLines'];
    $code = '';

    preg_match('/^\$CouldNot = .*?(?=^function Get-FirmwareMode)/ms', $script, $constants);
    $code .= ($constants[0] ?? '')."\n";

    foreach ($pieces as $name) {
        preg_match('/^function '.preg_quote($name, '/').'.*?^\}/ms', $script, $match);
        expect($match)->not->toBeEmpty();
        $code .= $match[0]."\n";
    }

    $dir = sys_get_temp_dir().'/pcc-readiness-'.bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);
    file_put_contents($dir.'/step.ps1', $code.<<<'PS'
$IsAdmin = $false
$cpus = @([pscustomobject]@{ Name = 'Intel(R) Core(TM) i5-8250U CPU @ 1.60GHz'; Manufacturer = 'GenuineIntel'; Description = 'Intel64 Family 6 Model 142 Stepping 10' })
$ramGb = 8
$systemDrive = 'C:'
Get-Windows11ReadinessLines
PS);

    $pwsh = trim((string) shell_exec('command -v pwsh 2>/dev/null'));
    $windows = trim((string) shell_exec('command -v powershell.exe 2>/dev/null'));
    $path = $pwsh !== '' ? $dir.'/step.ps1' : trim((string) shell_exec('wslpath -w '.escapeshellarg($dir.'/step.ps1')));
    $command = $pwsh !== '' ? [$pwsh, '-NoProfile', '-File'] : [$windows, '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File'];

    $result = Process::timeout(120)->run([...$command, $path]);

    return array_values(array_filter(array_map('trim', explode("\n", $result->output())), fn (string $line): bool => $line !== ''));
}

describe('the Windows 11 readiness step', function () {
    it('is its own section, wrapped in the Try-Run helper, and reads without admin rights', function () {
        $script = readinessScript();
        $start = (int) strpos($script, '# ---------- Windows 11 readiness');
        $block = substr($script, $start, (int) strpos($script, "\n\$firewall = ", $start) - $start);

        expect($block)->not->toBe('')
            ->and($script)->toContain("Add-Section 'Windows 11 readiness' { Get-Windows11ReadinessLines }")
            ->and($block)->toContain('Try-Run { Confirm-SecureBootUEFI')
            ->and($block)->toContain('-OperationTimeoutSec $ReadinessCimSeconds')
            ->and($block)->not->toMatch('/\b(?:Set|Remove|New|Enable|Disable|Initialize|Clear|Start|Stop)-[A-Z]/');
    });

    it('prints every line the report parser reads, and the parser accepts them', function () {
        $lines = runReadinessStep();
        $keys = array_map(fn (string $line): string => explode(':', $line, 2)[0], $lines);

        expect($keys)->toContain('Firmware', 'Secure Boot', 'TPM', 'TPM version', 'TPM ready', 'CPU', 'CPU maker', 'CPU family', 'Memory', 'System disk', 'Memory slots', 'Memory form', 'Memory maximum', 'Run as administrator');

        $markdown = "- Windows: Windows 10 Pro 22H2 (build 19045.1)\n\n## Windows 11 readiness\n\n```\n".implode("\n", $lines)."\n```\n";
        $card = (new ReportUpgrade)->card(ReportSections::parse($markdown));

        expect($card)->not->toBeNull()
            ->and($card['rows'][0]['label'])->toBe('Verdict');
    })->skip(readinessPowershellMissing(), 'PowerShell is not installed here');
});
