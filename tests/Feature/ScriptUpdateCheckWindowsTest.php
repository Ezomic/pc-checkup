<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

function wucScript(): string
{
    return str_replace("\r\n", "\n", (string) file_get_contents(base_path('scripts/windows-checkup.ps1')));
}

function wucPowershellMissing(): bool
{
    return trim((string) shell_exec('command -v pwsh 2>/dev/null')) === '' && trim((string) shell_exec('command -v powershell.exe 2>/dev/null')) === '';
}

/**
 * Runs Invoke-UpdateCheck with Invoke-RestMethod and Read-Host replaced by functions, which win over cmdlets.
 *
 * @return array{result: string, calls: list<string>, prompts: list<string>, output: string}
 */
function wucRun(string $restBody, string $answer = 'n', bool $interactive = true, string $own = 'aaaa1111', ?string $skip = null, ?string $envUrl = null, string $given = ''): array
{
    $script = wucScript();
    $code = '';

    foreach (['Test-UploadUrl', 'Invoke-UpdateCheck'] as $name) {
        preg_match('/^function '.preg_quote($name, '/').'.*?^\}/ms', $script, $match);
        expect($match)->not->toBeEmpty();
        $code .= $match[0]."\n";
    }

    $pwsh = trim((string) shell_exec('command -v pwsh 2>/dev/null'));
    $windows = trim((string) shell_exec('command -v powershell.exe 2>/dev/null'));
    $native = fn (string $path): string => $pwsh !== '' ? $path : trim((string) shell_exec('wslpath -w '.escapeshellarg($path)));
    $dir = sys_get_temp_dir().'/pcc-update-'.bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);

    $skipLine = $skip === null ? '' : "\$env:PCC_SKIP_UPDATE_CHECK = '{$skip}'";
    $urlLine = $envUrl === null ? '' : "\$env:PC_CHECKUP_URL = '{$envUrl}'";
    $interactiveText = $interactive ? '$true' : '$false';

    file_put_contents($dir.'/step.ps1', <<<PS
\$ErrorActionPreference = 'Stop'
\$DefaultUrl = 'https://pcc.example.test'
\$ScriptVersion = '{$own}'
\$env:PCC_SKIP_UPDATE_CHECK = \$null
\$env:PC_CHECKUP_URL = \$null
{$skipLine}
{$urlLine}
\$global:calls = New-Object System.Collections.Generic.List[string]
\$global:prompts = New-Object System.Collections.Generic.List[string]
function Invoke-RestMethod {
    param([string]\$Uri, [int]\$TimeoutSec, [Parameter(ValueFromRemainingArguments = \$true)]\$Rest)
    \$global:calls.Add("\$Uri|\$TimeoutSec|\$(\$Rest -join ' ')")
    {$restBody}
}
function Read-Host {
    param([string]\$Prompt)
    \$global:prompts.Add(\$Prompt)
    return '{$answer}'
}
{$code}
\$result = Invoke-UpdateCheck '{$given}' -Interactive {$interactiveText}
Write-Host ('RESULT=' + \$result)
foreach (\$c in \$global:calls) { Write-Host ('CALL=' + \$c) }
foreach (\$p in \$global:prompts) { Write-Host ('PROMPT=' + \$p) }
PS);

    $command = $pwsh !== '' ? [$pwsh, '-NoProfile', '-File'] : [$windows, '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File'];
    $output = str_replace("\r", '', Process::timeout(120)->run([...$command, $native($dir.'/step.ps1')])->output());
    $lines = explode("\n", $output);
    $pick = fn (string $prefix): array => array_values(array_map(fn (string $line): string => substr($line, strlen($prefix)), array_filter($lines, fn (string $line): bool => str_starts_with($line, $prefix))));

    return [
        'result' => $pick('RESULT=')[0] ?? 'none',
        'calls' => $pick('CALL='),
        'prompts' => $pick('PROMPT='),
        'output' => $output,
    ];
}

describe('the Windows update check', function () {
    beforeEach(function () {
        if (wucPowershellMissing()) {
            $this->markTestSkipped('No PowerShell on this machine.');
        }
    });

    it('asks the site once for the version, with a 5 second limit and nothing about the machine', function () {
        $run = wucRun("return 'aaaa1111'");

        expect($run['calls'])->toBe(['https://pcc.example.test/scripts/version/windows|5|-MaximumRedirection 0'])
            ->and($run['result'])->toBe('current');
    });

    it('is silent when the script is the current one', function () {
        $run = wucRun("return 'aaaa1111'");

        expect($run['prompts'])->toBe([])->and($run['output'])->not->toContain('Continue anyway');
    });

    it('says in Dutch and English that a newer script exists, shows the page and stops on N or Enter', function (string $answer) {
        $run = wucRun("return 'bbbb2222'", $answer);

        expect($run['output'])->toContain('Er is een nieuwere versie van dit script')
            ->and($run['output'])->toContain('A newer version of this script is available')
            ->and($run['output'])->toContain('https://pcc.example.test/')
            ->and($run['prompts'])->toHaveCount(1)
            ->and($run['prompts'][0])->toContain('Continue anyway? [y/N]')
            ->and($run['result'])->toBe('stopped');
    })->with(['n' => 'n', 'empty' => '', 'no' => 'no', 'nonsense' => 'maybe']);

    it('continues and records it on y', function (string $answer) {
        expect(wucRun("return 'bbbb2222'", $answer)['result'])->toBe('outdated, continued');
    })->with(['y' => 'y', 'Y' => 'Y', 'yes' => 'yes', 'ja' => 'ja', 'j' => 'j', 'padded' => '  y  ']);

    it('carries on silently when the request fails or times out', function (string $body) {
        $run = wucRun($body);

        expect($run['result'])->toBe('not checked')->and($run['prompts'])->toBe([]);
    })->with([
        'timeout' => 'throw [System.TimeoutException]::new("timed out")',
        'web error' => 'throw "The remote name could not be resolved"',
    ]);

    it('carries on silently when the answer is not a version', function (string $body) {
        $run = wucRun($body);

        expect($run['result'])->toBe('not checked')->and($run['prompts'])->toBe([]);
    })->with([
        'html' => "return '<html>502 Bad Gateway</html>'",
        'empty' => "return ''",
        'null' => 'return $null',
        'too short' => "return 'abc123'",
        'too long' => "return 'bbbb22223'",
        'not hex' => "return 'zzzzzzzz'",
        'upper case' => "return 'BBBB2222'",
        'two lines' => 'return "bbbb2222`nrm -rf /"',
        'an object' => "return [pscustomobject]@{ version = 'bbbb2222' }",
        'a list' => "return @('bbbb2222', 'bbbb2222')",
    ]);

    it('tolerates the trailing newline a plain-text answer may carry', function () {
        expect(wucRun('return "bbbb2222`r`n"', 'n')['result'])->toBe('stopped');
    });

    it('does not call the site when PCC_SKIP_UPDATE_CHECK is 1', function () {
        $run = wucRun("return 'bbbb2222'", 'n', skip: '1');

        expect($run['calls'])->toBe([])->and($run['prompts'])->toBe([])->and($run['result'])->toBe('not checked');
    });

    it('only the exact value 1 turns the check off', function () {
        expect(wucRun("return 'aaaa1111'", skip: '0')['result'])->toBe('current');
    });

    it('does not call the site or prompt when nobody can answer', function () {
        $run = wucRun("return 'bbbb2222'", 'n', interactive: false);

        expect($run['calls'])->toBe([])->and($run['prompts'])->toBe([])->and($run['result'])->toBe('not checked');
    });

    it('does not check a script that never had its version filled in', function (string $own) {
        $run = wucRun("return 'bbbb2222'", 'n', own: $own);

        expect($run['calls'])->toBe([])->and($run['result'])->toBe('not checked');
    })->with(['__PCC_SCRIPT_VERSION__', '', 'not-a-version']);

    it('asks the site the upload would use: -Url first, then PC_CHECKUP_URL', function () {
        $env = wucRun("return 'aaaa1111'", envUrl: 'http://localhost:8000/');
        $given = wucRun("return 'aaaa1111'", envUrl: 'http://localhost:8000/', given: 'https://given.example.test/');

        expect($env['calls'])->toBe(['http://localhost:8000/scripts/version/windows|5|-MaximumRedirection 0'])
            ->and($given['calls'])->toBe(['https://given.example.test/scripts/version/windows|5|-MaximumRedirection 0']);
    });

    it('does not call a site address the upload would refuse', function (string $url) {
        $run = wucRun("return 'bbbb2222'", 'n', envUrl: $url);

        expect($run['calls'])->toBe([])->and($run['result'])->toBe('not checked');
    })->with(['http://example.test', 'ftp://example.test', 'https://example.test/?x=1']);

    it('sets TLS 1.2 for Windows PowerShell 5.1 and uses the cmdlets that ship with it', function () {
        preg_match('/^function Invoke-UpdateCheck.*?^\}/ms', wucScript(), $function);

        expect($function[0])->toContain('Tls12')->toContain('-TimeoutSec 5')->toContain('Invoke-RestMethod')
            ->not->toContain('-UseBasicParsing')->not->toContain('-SkipHttpErrorCheck')->not->toContain('?.');
    });
});

describe('the Windows script around the update check', function () {
    it('runs it right after the banner and before any scanning, and stops when the answer is no', function () {
        $script = wucScript();
        $banner = (int) strpos($script, "Write-Host (Get-ScriptBanner)\n");
        $check = (int) strpos($script, "\$updateCheck = Invoke-UpdateCheck \$Url\nif (\$updateCheck -eq 'stopped') { exit 1 }\n");

        expect($banner)->toBeGreaterThan(0)
            ->and($check)->toBeGreaterThan($banner)
            ->and($check)->toBeLessThan((int) strpos($script, '# ---------- Identity ----------'));
    });

    it('records the outcome in the report header next to the script version', function () {
        expect(wucScript())->toContain("\$lines.Add((Get-ScriptVersionLine))\n\$lines.Add(\"- Update check: \$updateCheck\")\n");
    });
});
