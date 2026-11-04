<?php

declare(strict_types=1);

use App\Enums\FlagSeverity;
use App\Services\Flags\FlagAdvisor;
use Illuminate\Support\Facades\Process;

function windowsScript(): string
{
    return str_replace("\r\n", "\n", (string) file_get_contents(base_path('scripts/windows-checkup.ps1')));
}

/**
 * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $cases  build, caption, EditionID, scan date
 * @return list<string>
 */
function runWindows11Support(array $cases): array
{
    $script = windowsScript();
    preg_match('/^\$Windows11Support = .*?^\)$/ms', $script, $table);
    preg_match('/^function Get-Windows11SupportFlag.*?^\}$/ms', $script, $function);

    expect($table)->not->toBeEmpty()->and($function)->not->toBeEmpty();

    $dir = sys_get_temp_dir().'/pcc-win11-'.bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);
    file_put_contents($dir.'/cases.tsv', implode(chr(10), array_map(fn (array $case): string => implode(chr(9), $case), $cases)));
    file_put_contents($dir.'/step.ps1', $table[0]."\n".$function[0]."\n".<<<'PS'
foreach ($line in @(Get-Content -Path $args[0])) {
    $case = $line -split "`t"
    $day = [datetime]::ParseExact($case[3], 'yyyy-MM-dd', [Globalization.CultureInfo]::InvariantCulture)
    $flag = Get-Windows11SupportFlag -Build $case[0] -Caption $case[1] -EditionId $case[2] -Today $day
    if ($null -eq $flag) { 'NONE' } else { $flag }
}
PS);

    $pwsh = trim((string) shell_exec('command -v pwsh 2>/dev/null'));
    $windows = trim((string) shell_exec('command -v powershell.exe 2>/dev/null'));
    $convert = fn (string $path): string => $pwsh !== '' ? $path : trim((string) shell_exec('wslpath -w '.escapeshellarg($path)));
    $command = $pwsh !== '' ? [$pwsh, '-NoProfile', '-File'] : [$windows, '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File'];

    $result = Process::timeout(120)->run([...$command, $convert($dir.'/step.ps1'), $convert($dir.'/cases.tsv')]);

    expect($result->errorOutput())->toBe('');

    return array_values(array_filter(array_map('trim', explode("\n", $result->output())), fn (string $line): bool => $line !== ''));
}

function windows11Flag(string $version, string $date): string
{
    return "Windows 11 {$version} reached end of support on {$date}, so it no longer gets security updates. Update to the newest version of Windows 11.";
}

function powershellMissing(): bool
{
    return trim((string) shell_exec('command -v pwsh 2>/dev/null')) === '' && trim((string) shell_exec('command -v powershell.exe 2>/dev/null')) === '';
}

describe('Windows 11 support flags', function () {
    it('flags a version the day after its end date', function (array $case, string $expected) {
        expect(runWindows11Support([$case]))->toBe([$expected]);
    })->with([
        '21H2 Home' => [['22000', 'Windows 11 Home', 'Core', '2023-10-11'], windows11Flag('21H2', '10 October 2023')],
        '22H2 Pro' => [['22621', 'Windows 11 Pro', 'Professional', '2024-10-09'], windows11Flag('22H2', '8 October 2024')],
        '23H2 Home' => [['22631', 'Windows 11 Home', 'Core', '2025-11-12'], windows11Flag('23H2', '11 November 2025')],
        '24H2 Pro' => [['26100', 'Windows 11 Pro', 'Professional', '2026-10-14'], windows11Flag('24H2', '13 October 2026')],
        '24H2 Pro, scan date of the ticket' => [['26100', 'Windows 11 Pro', 'Professional', '2026-11-04'], windows11Flag('24H2', '13 October 2026')],
        '21H2 Enterprise' => [['22000', 'Windows 11 Enterprise', 'Enterprise', '2024-10-09'], windows11Flag('21H2', '8 October 2024')],
        '22H2 Education' => [['22621', 'Windows 11 Education', 'Education', '2025-10-15'], windows11Flag('22H2', '14 October 2025')],
        '23H2 Enterprise' => [['22631', 'Windows 11 Enterprise', 'Enterprise', '2026-11-11'], windows11Flag('23H2', '10 November 2026')],
        '23H2 IoT Enterprise' => [['22631', 'Windows 11 IoT Enterprise', 'IoTEnterprise', '2026-11-11'], windows11Flag('23H2', '10 November 2026')],
        '24H2 Enterprise' => [['26100', 'Windows 11 Enterprise', 'Enterprise', '2027-10-13'], windows11Flag('24H2', '12 October 2027')],
        '25H2 Home' => [['26200', 'Windows 11 Home', 'Core', '2027-10-13'], windows11Flag('25H2', '12 October 2027')],
        '25H2 Enterprise' => [['26200', 'Windows 11 Enterprise', 'Enterprise', '2028-10-11'], windows11Flag('25H2', '10 October 2028')],
        '26H1 Home' => [['28000', 'Windows 11 Home', 'Core', '2028-03-15'], windows11Flag('26H1', '14 March 2028')],
        '26H2 Home' => [['26300', 'Windows 11 Home', 'Core', '2028-10-11'], windows11Flag('26H2', '10 October 2028')],
        'build with an update revision suffix' => [['22631.7654', 'Windows 11 Home', 'Core', '2025-11-12'], windows11Flag('23H2', '11 November 2025')],
        'Windows 11 build captioned as Windows 10' => [['22621', 'Windows 10 Pro', 'Professional', '2024-10-09'], windows11Flag('22H2', '8 October 2024')],
        'unknown edition' => [['22631', '', '', '2025-11-12'], windows11Flag('23H2', '11 November 2025')],
    ])->skip(powershellMissing(), 'PowerShell is not installed here');

    it('stays quiet on the end date itself and before it', function (array $case) {
        expect(runWindows11Support([$case]))->toBe(['NONE']);
    })->with([
        '23H2 Home, day before' => [['22631', 'Windows 11 Home', 'Core', '2025-11-10']],
        '23H2 Home, day of' => [['22631', 'Windows 11 Home', 'Core', '2025-11-11']],
        '24H2 Home, day of' => [['26100', 'Windows 11 Home', 'Core', '2026-10-13']],
        '24H2 Home, day before' => [['26100', 'Windows 11 Home', 'Core', '2026-10-12']],
        '23H2 Enterprise, scan date of the ticket' => [['22631', 'Windows 11 Enterprise', 'Enterprise', '2026-11-04']],
        '23H2 Enterprise, day before' => [['22631', 'Windows 11 Enterprise', 'Enterprise', '2026-11-09']],
        '23H2 Enterprise, day of' => [['22631', 'Windows 11 Enterprise', 'Enterprise', '2026-11-10']],
        '22H2 Enterprise, day of' => [['22621', 'Windows 11 Enterprise', 'Enterprise', '2025-10-14']],
    ])->skip(powershellMissing(), 'PowerShell is not installed here');

    it('never flags what is not a Windows 11 feature version', function (array $case) {
        expect(runWindows11Support([$case]))->toBe(['NONE']);
    })->with([
        'Windows Server 2025' => [['26100', 'Windows Server 2025 Standard', 'ServerStandard', '2040-01-01']],
        'Windows Server 2025 on a localized caption' => [['26100', 'Windows Server 2025 Datacenter', 'ServerDatacenter', '2040-01-01']],
        'Enterprise LTSC' => [['26100', 'Windows 11 Enterprise LTSC', 'EnterpriseS', '2040-01-01']],
        'Enterprise LTSC N by EditionID alone' => [['26100', 'Windows 11 Entreprise', 'EnterpriseSN', '2040-01-01']],
        'IoT Enterprise LTSC by EditionID alone' => [['26100', 'Windows 11 IoT', 'IoTEnterpriseS', '2040-01-01']],
        'newer unknown build' => [['26400', 'Windows 11 Home', 'Core', '2040-01-01']],
        'far future build' => [['99999', 'Windows 11 Home', 'Core', '2040-01-01']],
        'insider build between releases' => [['22500', 'Windows 11 Home', 'Core', '2040-01-01']],
        'Windows 10 22H2' => [['19045', 'Windows 10 Pro', 'Professional', '2040-01-01']],
        'Windows Server 2022' => [['20348', 'Windows Server 2022 Standard', 'ServerStandard', '2040-01-01']],
        'empty build' => [['', 'Windows 11 Home', 'Core', '2040-01-01']],
        'non-numeric build' => [['abc', 'Windows 11 Home', 'Core', '2040-01-01']],
        'build with trailing text' => [['22631abc', 'Windows 11 Home', 'Core', '2040-01-01']],
        'negative build' => [['-22631', 'Windows 11 Home', 'Core', '2040-01-01']],
        'huge build' => [['99999999999999999999', 'Windows 11 Home', 'Core', '2040-01-01']],
        'two builds in one' => [['22631 26100', 'Windows 11 Home', 'Core', '2040-01-01']],
    ])->skip(powershellMissing(), 'PowerShell is not installed here');

    it('reads the edition column from the EditionID, whatever language the caption is in', function () {
        $between = '2026-01-01';
        $flag = windows11Flag('23H2', '11 November 2025');

        $flags = runWindows11Support([
            ['22631', 'Windows 11 Pro', 'Professional', $between],
            ['22631', 'Windows 11 Pro Education', 'ProfessionalEducation', $between],
            ['22631', 'Windows 11 Pro for Workstations', 'ProfessionalWorkstation', $between],
            ['22631', 'Windows 11 Home N', 'CoreN', $between],
            ['22631', 'Windows 11 Enterprise', 'Enterprise', $between],
            ['22631', 'Windows 11 Enterprise N', 'EnterpriseN', $between],
            ['22631', 'Windows 11 Education', 'Education', $between],
            ['22631', 'Windows 11 Education N', 'EducationN', $between],
            ['22631', 'Windows 11 IoT Enterprise', 'IoTEnterprise', $between],
            ['22631', 'Windows 11 Enterprise multi-session', 'ServerRdsh', $between],
            ['22631', 'Windows 11 Entreprise', 'Enterprise', $between],
            ['22631', 'Windows 11 Bildung', 'Education', $between],
        ]);

        expect($flags)->toBe([$flag, $flag, $flag, $flag, 'NONE', 'NONE', 'NONE', 'NONE', 'NONE', 'NONE', 'NONE', 'NONE']);
    })->skip(powershellMissing(), 'PowerShell is not installed here');

    it('writes a flag the advisor reads as an unsupported operating system', function () {
        $flag = windows11Flag('23H2', '11 November 2025');
        $advice = (new FlagAdvisor)->advise($flag);

        expect($flag)->toMatch(FlagAdvisor::UNSUPPORTED_OS)
            ->and($advice->severity)->toBe(FlagSeverity::High)
            ->and($advice->headline)->toBe('The operating system is no longer supported');
    });
});

describe('Windows 11 support flag in the script', function () {
    it('keeps the table in one place with the date it was checked, and calls it once', function () {
        $script = windowsScript();

        expect(substr_count($script, '$Windows11Support = '))->toBe(1)
            ->and($script)->toContain('checked 2026-11-04')
            ->and($script)->toContain('Get-Windows11SupportFlag -Build "$($os.BuildNumber)" -Caption $osCaption -EditionId $editionId -Today (Get-Date)')
            ->and(substr_count($script, 'Get-Windows11SupportFlag'))->toBe(2);
    });

    it('leaves the Windows 10 rule as it was', function () {
        expect(windowsScript())->toContain('if ($buildNumber -gt 0 -and $buildNumber -lt 22000) { Add-Flag "$osCaption reached end of support on 14 October 2025, so it only gets updates with a paid extension." }');
    });

    it('stays plain ASCII and Windows PowerShell 5.1 syntax', function () {
        $script = windowsScript();

        preg_match('/^\$Windows11Support = .*?^\)$/ms', $script, $table);
        preg_match('/^function Get-Windows11SupportFlag.*?^\}$/ms', $script, $function);

        expect($script)->toMatch('/\A[\x00-\x7F]*\z/')
            ->and($table[0].$function[0])->not->toMatch('/\?\?|\?\.|&&|\|\||-Parallel|-AsHashtable|\$IsWindows/');
    });
});
