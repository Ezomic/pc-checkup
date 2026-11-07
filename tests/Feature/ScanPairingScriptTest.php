<?php

declare(strict_types=1);

use App\Services\Scans\PairingQr;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Tests\Support\ScriptTemp;

const PS_ID = 'Ab3dEf6hIj9kLm2nOp5qRs8t';
const PS_SECRET = 'S3cretS3cretS3cretS3cretS3cretS3cretS3cr';
const PS_TOKEN = 'T0kenT0kenT0kenT0kenT0kenT0kenT0kenT0ken';
const PS_LINK = 'https://pc-checkup.thijssensoftware.nl/p/'.PS_ID;

function psWindowsShell(): string
{
    return trim((string) shell_exec('command -v pwsh 2>/dev/null')) ?: trim((string) shell_exec('command -v powershell.exe 2>/dev/null'));
}

/**
 * @param  list<array{status: int, body: array<string, mixed>}>  $polls
 * @param  array<string, mixed>  $start
 * @return array<string, mixed>
 */
function psScenario(array $polls, array $start = [], int $startStatus = 201): array
{
    $qr = new PairingQr;

    return [
        'start' => ['status' => $startStatus, 'body' => array_replace([
            'id' => PS_ID,
            'secret' => PS_SECRET,
            'check_code' => 'K7MX',
            'url' => PS_LINK,
            'qr' => $qr->lines(PS_LINK),
            'expires_in' => 600,
        ], $start)],
        'qr_ascii' => $qr->lines(PS_LINK, ascii: true),
        'polls' => $polls,
        'upload' => ['status' => 201, 'body' => ['machine_id' => 7, 'report_id' => 9, 'created' => true]],
    ];
}

/**
 * @return array{status: int, body: array<string, mixed>}
 */
function psPoll(string $status, int $http = 200): array
{
    return ['status' => $http, 'body' => $status === 'approved'
        ? ['status' => 'approved', 'upload_token' => PS_TOKEN, 'expires_in' => 1800]
        : ['status' => $status]];
}

/**
 * Serves the scenario with `php -S` on a free port. The server stops when the returned array is
 * dropped at the end of the test. Windows PowerShell reaches WSL only through an address bound on
 * every interface.
 *
 * @param  array<string, mixed>  $scenario
 * @return array{url: string, dir: string, process: InvokedProcess}
 */
function psServe(array $scenario): array
{
    $dir = ScriptTemp::dir('pcc-pair-server');
    file_put_contents($dir.'/scenario.json', (string) json_encode($scenario));
    $bind = str_ends_with(psWindowsShell(), '.exe') ? '0.0.0.0' : '127.0.0.1';

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $port = random_int(20000, 45000);
        $server = Process::env(['PCC_FAKE_DIR' => $dir])->start([PHP_BINARY, '-S', $bind.':'.$port, base_path('tests/Fixtures/pairing-fake-server.php')]);
        $deadline = microtime(true) + 5;

        while (microtime(true) < $deadline && $server->running()) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);

            if ($socket !== false) {
                fclose($socket);

                return ['url' => 'http://127.0.0.1:'.$port, 'dir' => $dir, 'process' => $server];
            }

            usleep(50_000);
        }

        $server->stop();
    }

    throw new RuntimeException('The fake server did not start.');
}

/**
 * @return list<array{method: string, uri: string, authorization: string|null}>
 */
function psRequests(string $dir): array
{
    $lines = array_filter(explode("\n", (string) @file_get_contents($dir.'/requests.log')));

    return array_values(array_map(fn (string $line): array => json_decode($line, true), $lines));
}

/**
 * Runs code after sourcing the Mac script, with the arguments as $1, $2.
 *
 * @param  list<string>  $args
 */
function psSourced(string $code, array $args = []): string
{
    return Process::path(base_path())->run(['bash', '-c', 'source scripts/mac-checkup.sh; '.$code, 'bash', ...$args])->output();
}

function psReport(): string
{
    $path = ScriptTemp::dir('pcc-pair-report').'/report.md';
    file_put_contents($path, fixtureReport());

    return $path;
}

/**
 * Runs the Mac upload step with the pairing chosen, against $base, with sleep made instant and
 * every curl call's arguments written down.
 *
 * @param  array<string, string>  $env
 * @return array{result: ProcessResult, argv: string, tmp: string}
 */
function psMac(string $base, array $env = [], string $sleep = 'exit 0'): array
{
    $curl = trim((string) shell_exec('command -v curl'));
    $bin = fakeMacBin([
        'sleep' => $sleep,
        'curl' => 'printf "%s\n" "$*" >> "$(dirname "$0")/argv.log"; exec '.escapeshellarg($curl).' "$@"',
    ]);
    $tmp = ScriptTemp::dir('pcc-pair-tmp');

    $result = Process::path(base_path())->timeout(90)
        ->env(['LC_ALL' => 'C.UTF-8', ...$env, 'TMPDIR' => $tmp, 'PATH' => $bin.':'.getenv('PATH')])
        ->run(['bash', '-c', 'source scripts/mac-checkup.sh; PCC_PAIR=1; PCC_CLI_URL="$1"; pcc_upload_report "$2"', 'bash', $base, psReport()]);

    return ['result' => $result, 'argv' => (string) @file_get_contents($bin.'/argv.log'), 'tmp' => $tmp];
}

/**
 * Runs the Windows upload step with the pairing chosen, from the functions in the real script.
 * $overrides is PowerShell that runs after them, to stand in for the console.
 */
function psWindows(string $base, string $overrides = ''): ProcessResult
{
    $script = str_replace("\r\n", "\n", (string) file_get_contents(base_path('scripts/windows-checkup.ps1')));
    $code = '';

    foreach (['One-Line', 'Test-UploadUrl', 'New-UploadBody', 'Read-FailedResponse', 'Send-Report', 'Get-RejectionText', 'Show-UploadResult', 'Show-UploadHelp',
        'Test-PairingStart', 'Invoke-PairingRequest', 'Enable-Utf8Output', 'Show-PairingQr', 'Test-CancelKey', 'Wait-PairingApproval', 'Invoke-PairedUpload'] as $name) {
        preg_match('/^function '.preg_quote($name, '/').'\b.*?^\}/ms', $script, $match);
        expect($match)->not->toBeEmpty();
        $code .= $match[0]."\n";
    }

    $shell = psWindowsShell();
    $native = fn (string $path): string => str_ends_with($shell, '.exe') ? trim((string) shell_exec('wslpath -w '.escapeshellarg($path))) : $path;
    $dir = ScriptTemp::dir('pcc-pair-ps');
    file_put_contents($dir.'/step.ps1', "\$ErrorActionPreference = 'Stop'\n\$DefaultUrl = 'https://pc-checkup.thijssensoftware.nl'\n".$code
        ."function Start-Sleep { }\n".$overrides."\n"
        .'Invoke-PairedUpload ([IO.File]::ReadAllText($args[0])) $args[1]'."\n");
    $command = str_ends_with($shell, '.exe') ? [$shell, '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File'] : [$shell, '-NoProfile', '-File'];

    return Process::timeout(120)->run([...$command, $native($dir.'/step.ps1'), $native(psReport()), $base]);
}

function psOutput(ProcessResult $result): string
{
    return str_replace("\r\n", "\n", $result->output());
}

describe('the Mac script', function () {
    it('asks for the code or Enter, and Enter chooses the QR code while a typed code works as before', function (string $typed, string $expected) {
        $result = Process::path(base_path())->input($typed."\n")->timeout(30)->run([
            'script', '-qec', 'bash -c \'source scripts/mac-checkup.sh; pcc_prompt_code; echo "[$PCC_CODE][$PCC_PAIR]"\'', '/dev/null',
        ]);

        expect(str_replace("\r", '', $result->output()))->toContain('QR')->toContain($expected);
    })->with(['Enter' => ['', '[][1]'], 'a code' => ['123 456', '[123456][0]']])
        ->skip(fn () => trim((string) shell_exec('command -v script 2>/dev/null')) === '', 'needs script(1) for a terminal');

    it('never pairs without a terminal', function () {
        expect(trim(psSourced('pcc_prompt_code; echo "[$PCC_CODE][$PCC_PAIR]"')))->toBe('[][0]');
    });

    it('shows the QR code, link and check code, waits for the approval and uploads with the token', function () {
        $server = psServe(psScenario([psPoll('pending'), psPoll('pending'), psPoll('approved')]));

        $run = psMac($server['url']);
        $output = $run['result']->output();
        $requests = psRequests($server['dir']);
        $qr = (new PairingQr)->lines(PS_LINK);

        expect($run['result']->exitCode())->toBe(0)
            ->and($output)->toContain(implode("\n", $qr))
            ->toContain(PS_LINK)->toContain('K7MX')
            ->toContain('NL: ')->toContain('EN: ')->toContain('Ctrl-C')
            ->toContain('Uploaded as a new report: machine 7')
            ->not->toContain(PS_SECRET)->not->toContain(PS_TOKEN)
            ->and(array_column($requests, 'uri'))->toBe(['/scripts/pair?qr=half', '/scripts/pair/'.PS_ID, '/scripts/pair/'.PS_ID, '/scripts/pair/'.PS_ID, '/api/scans'])
            ->and($requests[0]['authorization'])->toBeNull()
            ->and(array_column(array_slice($requests, 1, 3), 'authorization'))->each->toBe('Bearer '.PS_SECRET)
            ->and($requests[4]['authorization'])->toBeNull()
            ->and(json_decode((string) file_get_contents($server['dir'].'/upload.json'), true))->toBe(['upload_token' => PS_TOKEN, 'markdown' => fixtureReport()])
            ->and($run['argv'])->not->toContain(PS_SECRET)->not->toContain(PS_TOKEN)
            ->and(glob($run['tmp'].'/*'))->toBe([]);
    });

    it('asks for the ASCII QR code when the terminal is not UTF-8', function () {
        $server = psServe(psScenario([psPoll('rejected')]));

        $output = psMac($server['url'], ['LC_ALL' => 'C'])['result']->output();

        expect(psRequests($server['dir'])[0]['uri'])->toBe('/scripts/pair?qr=ascii')
            ->and($output)->toContain(implode("\n", (new PairingQr)->lines(PS_LINK, ascii: true)));
    });

    it('says so and keeps the report when the owner rejects, the request expires or nobody answers', function (array $polls, int $expires, string $says) {
        $server = psServe(psScenario($polls, ['expires_in' => $expires]));

        $output = psMac($server['url'])['result']->output();

        expect($output)->toContain($says)->toContain('To upload this report')
            ->and(is_file($server['dir'].'/upload.json'))->toBeFalse();
    })->with([
        'rejected' => [[psPoll('pending'), psPoll('rejected')], 600, 'rejected'],
        'expired' => [[psPoll('expired')], 600, 'expired'],
        'gone' => [[psPoll('unknown', 404)], 600, 'expired'],
        'used' => [[psPoll('used')], 600, 'expired'],
        'nobody answers' => [[psPoll('pending')], 9, 'Nobody approved'],
    ]);

    it('counts the wait on the clock too, so a slow network cannot stretch it', function () {
        $server = psServe([...psScenario([psPoll('pending')], ['expires_in' => 9]), 'poll_delay' => 5]);

        $output = psMac($server['url'])['result']->output();

        expect($output)->toContain('Nobody approved')
            ->and(array_column(psRequests($server['dir']), 'uri'))->toBe(['/scripts/pair?qr=half', '/scripts/pair/'.PS_ID, '/scripts/pair/'.PS_ID]);
    });

    it('keeps polling through a failed or throttled poll', function () {
        $server = psServe(psScenario([psPoll('pending', 500), psPoll('pending', 429), psPoll('approved')]));

        expect(psMac($server['url'])['result']->output())->toContain('Uploaded as a new report: machine 7');
    });

    it('stops cleanly on Ctrl-C, uploads nothing and leaves no temp files', function () {
        $server = psServe(psScenario([psPoll('pending')]));
        $curl = trim((string) shell_exec('command -v curl'));
        $bin = fakeMacBin(['sleep' => 'exec /bin/sleep 0.3', 'curl' => 'exec '.escapeshellarg($curl).' "$@"']);
        $tmp = ScriptTemp::dir('pcc-pair-tmp');

        $process = Process::path(base_path())->timeout(60)
            ->env(['LC_ALL' => 'C.UTF-8', 'TMPDIR' => $tmp, 'PATH' => $bin.':'.getenv('PATH')])
            ->start(['bash', '-c', 'source scripts/mac-checkup.sh; PCC_PAIR=1; PCC_CLI_URL="$1"; pcc_upload_report "$2"; echo "after: $?"', 'bash', $server['url'], psReport()]);

        $deadline = microtime(true) + 20;
        while (microtime(true) < $deadline && count(psRequests($server['dir'])) < 3) {
            usleep(100_000);
        }
        $process->signal(SIGINT);
        $output = $process->wait()->output();

        expect($output)->toContain('Stopped')->toContain('To upload this report')->toContain('after: 0')
            ->and(is_file($server['dir'].'/upload.json'))->toBeFalse()
            ->and(glob($tmp.'/*'))->toBe([]);
    });

    it('removes the file holding the secret when it is killed while waiting', function (int $signal) {
        $server = psServe(psScenario([psPoll('pending')]));
        $curl = trim((string) shell_exec('command -v curl'));
        $bin = fakeMacBin(['sleep' => 'exec /bin/sleep 0.3', 'curl' => 'exec '.escapeshellarg($curl).' "$@"']);
        $tmp = ScriptTemp::dir('pcc-pair-tmp');

        $process = Process::path(base_path())->timeout(60)
            ->env(['LC_ALL' => 'C.UTF-8', 'TMPDIR' => $tmp, 'PATH' => $bin.':'.getenv('PATH')])
            ->start(['bash', '-c', 'source scripts/mac-checkup.sh; PCC_PAIR=1; PCC_CLI_URL="$1"; pcc_upload_report "$2"', 'bash', $server['url'], psReport()]);

        $deadline = microtime(true) + 20;
        while (microtime(true) < $deadline && count(psRequests($server['dir'])) < 3) {
            usleep(100_000);
        }
        $process->signal($signal);
        $process->wait();

        expect(glob($tmp.'/*'))->toBe([])
            ->and(is_file($server['dir'].'/upload.json'))->toBeFalse();
    })->with(['TERM' => [SIGTERM], 'HUP' => [SIGHUP]]);

    it('says so when the pairing cannot start, without sending anything else', function (int $status) {
        $server = psServe(psScenario([psPoll('approved')], startStatus: $status));

        $output = psMac($server['url'])['result']->output();

        expect($output)->toContain('could not start')->toContain('To upload this report')
            ->and(array_column(psRequests($server['dir']), 'uri'))->toBe(['/scripts/pair?qr=half']);
    })->with([429, 500]);

    it('says so when the site cannot be reached', function () {
        expect(psMac('http://127.0.0.1:9')['result']->output())->toContain('could not start')->toContain('To upload this report');
    });

    it('refuses an answer it does not expect and prints none of it', function (array $start) {
        $server = psServe(psScenario([psPoll('approved')], $start));

        $output = psMac($server['url'])['result']->output();

        expect($output)->toContain('could not start')->not->toContain("\e")->not->toContain('evil')
            ->and(array_column(psRequests($server['dir']), 'uri'))->toBe(['/scripts/pair?qr=half']);
    })->with([
        'escape codes in the QR code' => [['qr' => ["\e[2J\e]0;evil\x07"]]],
        'no QR code' => [['qr' => []]],
        'a short id' => [['id' => 'abc']],
        'an id with a slash' => [['id' => '../../../../evil/abcdefgh']],
        'a secret with a quote' => [['secret' => str_repeat('a', 39).'"']],
        'a long check code' => [['check_code' => 'K7MXevil']],
        'a link with escape codes' => [['url' => "https://x.test/\e[31mevil"]],
        'a link that is not https' => [['url' => 'javascript:evil']],
    ]);

    it('puts only a well formed upload token into the body', function (string $token, string $expected) {
        $path = tempnam(sys_get_temp_dir(), 'pcc');
        file_put_contents($path, 'x');

        $body = psSourced('pcc_json_body "$1" "" "$2"', [$path, $token]);
        unlink($path);

        expect($body)->toBe($expected);
    })->with([
        'token' => [PS_TOKEN, '{"upload_token":"'.PS_TOKEN.'","markdown":"x"}'],
        'short' => ['abc', '{"markdown":"x"}'],
        'quote' => [str_repeat('a', 39).'"', '{"markdown":"x"}'],
        'empty' => ['', '{"markdown":"x"}'],
    ]);
});

describe('the Windows script', function () {
    it('shows the QR code, link and check code, waits for the approval and uploads with the token', function () {
        $server = psServe(psScenario([psPoll('pending'), psPoll('approved')]));

        $result = psWindows($server['url']);
        $output = psOutput($result);
        $requests = psRequests($server['dir']);

        expect($result->errorOutput())->toBe('')
            ->and($output)->toContain(implode("\n", (new PairingQr)->lines(PS_LINK)))
            ->toContain(PS_LINK)->toContain('K7MX')
            ->toContain('NL: ')->toContain('EN: ')->toContain('Ctrl-C')
            ->toContain('Uploaded as a new report: machine 7')
            ->not->toContain(PS_SECRET)->not->toContain(PS_TOKEN)
            ->and(array_column($requests, 'uri'))->toBe(['/scripts/pair?qr=half', '/scripts/pair/'.PS_ID, '/scripts/pair/'.PS_ID, '/api/scans'])
            ->and(array_column(array_slice($requests, 1, 2), 'authorization'))->each->toBe('Bearer '.PS_SECRET)
            ->and($requests[3]['authorization'])->toBeNull()
            ->and(json_decode((string) file_get_contents($server['dir'].'/upload.json'), true))->toBe(['upload_token' => PS_TOKEN, 'markdown' => fixtureReport()]);
    })->skip(fn () => psWindowsShell() === '', 'needs PowerShell');

    it('falls back to the ASCII QR code when the console cannot switch to UTF-8', function () {
        $server = psServe(psScenario([psPoll('rejected')]));

        $output = psOutput(psWindows($server['url'], 'function Enable-Utf8Output { return $null }'));

        expect(psRequests($server['dir'])[0]['uri'])->toBe('/scripts/pair?qr=ascii')
            ->and($output)->toContain(implode("\n", (new PairingQr)->lines(PS_LINK, ascii: true)));
    })->skip(fn () => psWindowsShell() === '', 'needs PowerShell');

    it('says so and keeps the report when the owner rejects, the request expires or nobody answers', function (array $polls, int $expires, string $says) {
        $server = psServe(psScenario($polls, ['expires_in' => $expires]));

        $output = psOutput(psWindows($server['url']));

        expect($output)->toContain($says)->toContain('To upload this report')
            ->and(is_file($server['dir'].'/upload.json'))->toBeFalse();
    })->with([
        'rejected' => [[psPoll('pending'), psPoll('rejected')], 600, 'rejected'],
        'gone' => [[psPoll('unknown', 404)], 600, 'expired'],
        'nobody answers' => [[psPoll('pending', 500), psPoll('pending')], 9, 'Nobody approved'],
    ])->skip(fn () => psWindowsShell() === '', 'needs PowerShell');

    it('counts the wait on the clock too, so a slow network cannot stretch it', function () {
        $server = psServe([...psScenario([psPoll('pending')], ['expires_in' => 9]), 'poll_delay' => 5]);

        $output = psOutput(psWindows($server['url']));

        expect($output)->toContain('Nobody approved')
            ->and(array_column(psRequests($server['dir']), 'uri'))->toBe(['/scripts/pair?qr=half', '/scripts/pair/'.PS_ID, '/scripts/pair/'.PS_ID]);
    })->skip(fn () => psWindowsShell() === '', 'needs PowerShell');

    it('stops on Ctrl-C and uploads nothing', function () {
        $server = psServe(psScenario([psPoll('pending')]));

        $output = psOutput(psWindows($server['url'], 'function Test-CancelKey { return $true }'));

        expect($output)->toContain('Stopped')->toContain('To upload this report')
            ->and(count(psRequests($server['dir'])))->toBe(2)
            ->and(is_file($server['dir'].'/upload.json'))->toBeFalse();
    })->skip(fn () => psWindowsShell() === '', 'needs PowerShell');

    it('refuses an answer it does not expect and prints none of it', function (array $start, int $status) {
        $server = psServe(psScenario([psPoll('approved')], $start, $status));

        $output = psOutput(psWindows($server['url']));

        expect($output)->toContain('could not start')->not->toContain("\e")->not->toContain('evil')
            ->and(array_column(psRequests($server['dir']), 'uri'))->toBe(['/scripts/pair?qr=half']);
    })->with([
        'escape codes in the QR code' => [['qr' => ["\e[2J\e]0;evil\x07"]], 201],
        'a short id' => [['id' => 'abc'], 201],
        'a link with escape codes' => [['url' => "https://x.test/\e[31mevil"], 201],
        'rate limited' => [[], 429],
    ])->skip(fn () => psWindowsShell() === '', 'needs PowerShell');

    it('stays ASCII only, so Windows PowerShell 5.1 reads it right', function () {
        expect((string) file_get_contents(base_path('scripts/windows-checkup.ps1')))->toMatch('/\A[\x09\x0a\x0d\x20-\x7e]*\z/');
    });
});
