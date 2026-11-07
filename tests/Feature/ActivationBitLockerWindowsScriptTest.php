<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Tests\Support\ScriptTemp;

function abwMissing(): bool
{
    return trim((string) shell_exec('command -v pwsh 2>/dev/null')) === '' && trim((string) shell_exec('command -v powershell.exe 2>/dev/null')) === '';
}

function abwScript(): string
{
    return str_replace("\r\n", "\n", (string) file_get_contents(base_path('scripts/windows-checkup.ps1')));
}

function abwPart(string $pattern): string
{
    preg_match($pattern, abwScript(), $match);

    expect($match)->not->toBeEmpty();

    return $match[0];
}

function abwFunction(string $name): string
{
    return abwPart('/^function '.preg_quote($name, '/').'\b.*?^\}$/ms');
}

function abwSection(): string
{
    return abwPart('/^Add-Section \'Activation and BitLocker\' \{.*?^\}$/ms');
}

/**
 * @return list<string>
 */
function abwLines(string $case): array
{
    $functions = implode("\n", array_map(abwFunction(...), ['One-Line', 'Explain-Error', 'Try-Run', 'Add-Section', 'Read-CappedJob', 'Get-ActivationBitLockerLines']));
    $licence = abwPart('/^\$licence = Try-Run \{.*$/m');

    $dir = ScriptTemp::dir('pcc-abw');
    file_put_contents($dir.'/step.ps1', "param([string]\$Case)\n".$functions."\n".<<<'PS'
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
function Show($Lines) { @($Lines) -join "`n" }
switch ($Case) {
    'fine' { Show (Get-ActivationBitLockerLines 1 $null @('C:|2', 'D:|1') $null) }
    'licence-codes' { foreach ($code in 0, 1, 2, 3, 4, 5, 6, 7, 10, -1, 'abc', '', $null, '1.5', '99999999999') { Show (Get-ActivationBitLockerLines $code $null @() 'skip') } }
    'licence-uint' { Show (Get-ActivationBitLockerLines ([uint32]5) $null @() 'skip') }
    'licence-failed' { Show (Get-ActivationBitLockerLines 1 'unavailable (needs admin)' @() 'skip') }
    'drive-codes' { Show (Get-ActivationBitLockerLines 1 $null @('A:|0', 'B:|1', 'C:|2', 'D:|3', 'E:|4', 'F:|5', 'G:|6', 'H:|7', 'I:|8', 'J:|9', 'K:|', 'L:|99', 'M:|x', 'N:|-1', 'O:|99999999999') $null) }
    'drive-junk' { Show (Get-ActivationBitLockerLines 1 $null @('c:|1', 'C:|2', 'CC:|1', '1:|1', ':|1', 'C|1', 'D:\|1', "E:|1`n", "F:`t|1", 'G:|1|2', 'junk', '', $null, "$([char]0xC7):|1", 'H:|123456-123456-123456', "I:|$([char]0x663)") $null) }
    'licence-unicode-digit' { Show (Get-ActivationBitLockerLines "$([char]0x661)" $null @() 'skip') }
    'drive-failed' { Show (Get-ActivationBitLockerLines 1 $null @('C:|1') 'took longer than 20 seconds') }
    'drive-none' { Show (Get-ActivationBitLockerLines 1 $null @() $null) }
    'drive-null' { Show (Get-ActivationBitLockerLines 1 $null $null $null) }
    'many' { Show (Get-ActivationBitLockerLines 1 $null @(65..90 | ForEach-Object { "$([char]$_):|1" }) $null) }
    'section' { . (Join-Path $PSScriptRoot 'section.ps1') }
}
PS);
    file_put_contents($dir.'/section.ps1', "\$Body = New-Object System.Collections.Generic.List[string]\n".$licence."\n".abwSection()."\n\$Body -join \"\`n\"\n");

    $pwsh = trim((string) shell_exec('command -v pwsh 2>/dev/null'));
    $windows = trim((string) shell_exec('command -v powershell.exe 2>/dev/null'));
    $convert = fn (string $path): string => $pwsh !== '' ? $path : trim((string) shell_exec('wslpath -w '.escapeshellarg($path)));
    $command = $pwsh !== '' ? [$pwsh, '-NoProfile', '-File'] : [$windows, '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File'];
    $result = Process::timeout(120)->run([...$command, $convert($dir.'/step.ps1'), $case])->throw();

    return array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", '', $result->output()))), fn (string $line): bool => $line !== ''));
}

describe('the activation and BitLocker step in the Windows script', function () {
    it('prints the licence state and one line per fixed drive', function () {
        expect(abwLines('fine'))->toBe(['format: 1', 'activation: licensed', 'bitlocker: complete', 'drive|C:|off', 'drive|D:|on']);
    })->skip(abwMissing(), 'PowerShell is not installed here');

    it('turns every licence code into a plain word and anything else into could not check', function () {
        $states = array_values(array_filter(abwLines('licence-codes'), fn (string $line): bool => str_starts_with($line, 'activation: ')));

        expect($states)->toBe([
            'activation: unlicensed', 'activation: licensed', 'activation: grace period', 'activation: grace period',
            'activation: non-genuine grace period', 'activation: notification', 'activation: grace period',
            'activation: could not check', 'activation: could not check', 'activation: could not check', 'activation: could not check',
            'activation: could not check', 'activation: could not check', 'activation: could not check', 'activation: could not check',
        ]);
    })->skip(abwMissing(), 'PowerShell is not installed here');

    it('reads the licence code as CIM gives it and says could not check when the read failed', function () {
        expect(abwLines('licence-uint'))->toContain('activation: notification')
            ->and(abwLines('licence-unicode-digit'))->toContain('activation: could not check')
            ->and(abwLines('licence-failed'))->toBe(['format: 1', 'activation: could not check', 'bitlocker: could not check']);
    })->skip(abwMissing(), 'PowerShell is not installed here');

    it('turns every BitLocker code into a plain word, an unknown or missing one into could not check, and drops what is no code', function () {
        expect(abwLines('drive-codes'))->toBe([
            'format: 1', 'activation: licensed', 'bitlocker: complete',
            'drive|A:|off', 'drive|B:|on', 'drive|C:|off', 'drive|D:|encrypting', 'drive|E:|decrypting', 'drive|F:|suspended',
            'drive|G:|locked', 'drive|H:|could not check', 'drive|I:|waiting', 'drive|J:|could not check', 'drive|K:|could not check',
            'drive|L:|could not check',
        ]);
    })->skip(abwMissing(), 'PowerShell is not installed here');

    it('keeps each drive letter once and drops rows that are not a letter and a code', function () {
        expect(abwLines('drive-junk'))->toBe(['format: 1', 'activation: licensed', 'bitlocker: complete', 'drive|C:|on']);
    })->skip(abwMissing(), 'PowerShell is not installed here');

    it('says it could not check BitLocker when the read failed or timed out, and prints no drive', function () {
        expect(abwLines('drive-failed'))->toBe(['format: 1', 'activation: licensed', 'bitlocker: could not check']);
    })->skip(abwMissing(), 'PowerShell is not installed here');

    it('says the read was complete when there is no fixed drive', function (string $case) {
        expect(abwLines($case))->toBe(['format: 1', 'activation: licensed', 'bitlocker: complete']);
    })->with(['drive-none', 'drive-null'])->skip(abwMissing(), 'PowerShell is not installed here');

    it('lists every drive letter there can be', function () {
        expect(array_filter(abwLines('many'), fn (string $line): bool => str_starts_with($line, 'drive|')))->toHaveCount(26);
    })->skip(abwMissing(), 'PowerShell is not installed here');

    it('runs for real on this machine without admin rights and without failing', function () {
        $output = implode("\n", abwLines('section'));

        expect($output)->toContain('## Activation and BitLocker')
            ->toMatch('/^activation: (licensed|unlicensed|grace period|non-genuine grace period|notification|could not check)$/m')
            ->toMatch('/^bitlocker: (complete|could not check)$/m')
            ->not->toContain('(nothing to show)')
            ->not->toMatch('/[A-Z0-9]{5}-[A-Z0-9]{5}/')
            ->not->toMatch('/\d{6}-\d{6}/');
    })->skip(abwMissing(), 'PowerShell is not installed here');
});

describe('the script itself', function () {
    it('reads BitLocker in a time-boxed job through the Explorer drive property only', function () {
        $section = abwSection();

        expect($section)->toMatch('/Read-CappedJob \{.*\} \d+\s*\n/s')
            ->toContain("ExtendedProperty('System.Volume.BitLockerProtection')")
            ->toContain('DriveType -eq \'Fixed\'')
            ->toContain('Get-ActivationBitLockerLines $licence.Value.LicenseStatus $licence.Note');
    });

    it('never reads a product key, a recovery key or a key protector in the new step', function () {
        foreach ([abwSection(), abwFunction('Get-ActivationBitLockerLines')] as $part) {
            foreach (['PartialProductKey', 'ProductKey', 'OA3xOriginalProductKey', 'RecoveryPassword', 'KeyProtector', 'Get-BitLockerVolume', 'manage-bde', 'slmgr', 'BackupToAAD'] as $forbidden) {
                expect($part)->not->toContain($forbidden);
            }
        }
    });

    it('comes after the existing sections, stays ASCII and keeps the upload last', function () {
        $script = abwScript();

        expect(strpos($script, "Add-Section 'Activation and BitLocker'"))->toBeGreaterThan(strpos($script, "Add-Section 'Antivirus products'"))
            ->and(strpos($script, "Add-Section 'Activation and BitLocker'"))->toBeLessThan(strpos($script, '# ---------- Assemble ----------'))
            ->and(preg_match('/[^\x00-\x7F]/', $script))->toBe(0);
    });

    it('says in its header what the step reads and that it never reads keys', function () {
        $header = substr(abwScript(), 0, (int) strpos(abwScript(), 'param('));

        expect($header)->toContain('whether Windows is activated')->toContain('never the product key')->toContain('recovery key');
    });
});
