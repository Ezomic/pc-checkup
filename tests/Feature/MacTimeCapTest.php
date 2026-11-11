<?php

declare(strict_types=1);

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Tests\Support\ScriptTemp;

const CAP_STUBBORN = 'trap "" TERM ALRM HUP INT; sleep 120 & wait';

/**
 * @param  array<string, string>  $env
 */
function capRun(string $code, array $env = [], ?string $path = null): ProcessResult
{
    return Process::path(base_path())->timeout(120)->env(array_filter(array_merge(['PATH' => $path], $env), fn (?string $value): bool => $value !== null))
        ->run(['bash', '-c', 'source scripts/mac-checkup.sh; '.$code]);
}

function capTree(): string
{
    $base = ScriptTemp::dir('pcc-script-cap');

    foreach (['home/Downloads/a.bin' => 1024, 'home/Documents/b.bin' => 2048, 'home/Library/Caches/c.dat' => 512, 'home/.Trash/t.bin' => 256, 'root/dir1/x' => 300] as $file => $kib) {
        File::ensureDirectoryExists(dirname("{$base}/{$file}"));
        file_put_contents("{$base}/{$file}", str_repeat('x', $kib * 1024));
    }

    return $base;
}

function capStorage(string $base, string $path, int $cap): ProcessResult
{
    return capRun('pcc_storage_report', [
        'PCC_STORAGE_HOME' => $base.'/home', 'PCC_STORAGE_ROOT' => $base.'/root', 'PCC_STORAGE_DATA' => $base.'/none',
        'PCC_STORAGE_FIRMLINKS' => $base.'/none', 'PCC_STORAGE_CAP' => (string) $cap,
    ], $path);
}

function capSpace(string $base, string $path, int $cap): ProcessResult
{
    return capRun('pcc_space_report', ['PCC_SPACE_HOME' => $base.'/home', 'PCC_SPACE_MIN_KB' => '100', 'PCC_SPACE_CAP' => (string) $cap], $path);
}

/**
 * A sleep whose command line no other test run on the machine shares, so pgrep finds only this one.
 */
function capOwnSleep(int $seconds): string
{
    return sprintf('sleep %d.%09d', $seconds, random_int(0, 999_999_999));
}

/**
 * A find that runs the stubborn du under it for every file, as find -exec du does, and waits for it.
 */
function stuckFindDu(): string
{
    return fakeMacBin([
        'du' => 'case " $* " in *" /nowhere "*) '.CAP_STUBBORN.' ;; esac; exec /usr/bin/du "$@"',
        'find' => 'case " $* " in *" -exec "*) du -k /nowhere ;; *) exec /usr/bin/find "$@" ;; esac',
    ]);
}

describe('the capped runner', function () {
    it('gives the output, the error output and the exit code of a command that finishes in time', function () {
        $result = capRun('pcc_capped 10 sh -c "echo out; echo err >&2; exit 3"; echo "rc=$?"', [], '/usr/bin:/bin');

        expect($result->output())->toBe("out\nrc=3\n")->and($result->errorOutput())->toBe("err\n");
    });

    it('relays what a command prints in many chunks without losing a byte', function () {
        $result = capRun('pcc_capped 20 seq 1 200000 | cksum', [], '/usr/bin:/bin');
        $expected = Process::run(['bash', '-c', 'seq 1 200000 | cksum'])->output();

        expect($result->output())->toBe($expected);
    });

    it('kills a command that ignores every signal at the cap and exits 142 on time', function () {
        $bin = fakeMacBin(['hang' => CAP_STUBBORN]);
        $start = microtime(true);
        $result = capRun('pcc_capped 2 hang; echo "rc=$?"', [], $bin.':/usr/bin:/bin');

        expect(microtime(true) - $start)->toBeLessThan(2 + 20)->and($result->output())->toBe("rc=142\n");
    });

    it('kills the grandchildren too, not only the command it started', function () {
        $bin = fakeMacBin(['outer' => 'inner & wait', 'inner' => CAP_STUBBORN]);
        $result = capRun('pcc_capped 2 outer; echo "rc=$?"', [], $bin.':/usr/bin:/bin');

        expect($result->output())->toBe("rc=142\n");
    });

    it('keeps what a slow writer printed before the cap and still returns on time', function () {
        $bin = fakeMacBin(['slow' => 'for i in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20; do echo "line $i"; sleep 1; done']);
        $start = microtime(true);
        $result = capRun('pcc_capped 3 slow; echo "rc=$?"', [], $bin.':/usr/bin:/bin');
        $lines = explode("\n", trim($result->output()));

        expect(microtime(true) - $start)->toBeLessThan(3 + 20)
            ->and($lines)->toContain('line 1')->toContain('line 2')->toContain('rc=142')->not->toContain('line 10');
    });

    it('returns at the cap even when a process that left the group still holds the pipe', function () {
        $pidFile = ScriptTemp::dir('pcc-script-cap-pid').'/pid';
        $start = microtime(true);
        $result = capRun('pcc_capped 2 bash -c "setsid sleep 118 & echo \$! > '.$pidFile.'; exec sleep 119"; echo "rc=$?"', [], '/usr/bin:/bin');
        $took = microtime(true) - $start;
        $escaped = trim((string) @file_get_contents($pidFile));

        if ($escaped !== '') {
            Process::run(['kill', '-KILL', $escaped]);
        }

        expect($took)->toBeLessThan(2 + 20)->and($result->output())->toBe("rc=142\n");
    });

    it('stops the command when it is told to stop, so Ctrl-C leaves nothing behind', function () {
        $sleep = capOwnSleep(117);
        $result = capRun('perl -e "$PCC_CAP_PL" 60 '.$sleep.' & runner=$!; sleep 1; kill -TERM $runner; wait $runner; echo "rc=$?"; sleep 0.5; pgrep -fx "'.$sleep.'" > /dev/null && echo alive || echo gone', [], '/usr/bin:/bin');

        expect($result->output())->toBe("rc=143\ngone\n");
    });

    it('returns the real exit code at once when the command ends but something it started keeps the pipes open', function () {
        $sleep = capOwnSleep(116);
        $start = microtime(true);
        $result = capRun('pcc_capped 8 sh -c "echo hi; '.$sleep.' & exit 0"; echo "rc=$?"; sleep 0.5; pgrep -fx "'.$sleep.'" > /dev/null && echo alive || echo gone', [], '/usr/bin:/bin');

        expect(microtime(true) - $start)->toBeLessThan(5)->and($result->output())->toBe("hi\nrc=0\ngone\n");
    });

    it('keeps a non zero exit code of a command that left a helper behind', function () {
        $result = capRun('pcc_capped 8 sh -c "echo oops >&2; sleep 115 & exit 4"; echo "rc=$?"', [], '/usr/bin:/bin');

        expect($result->output())->toBe("rc=4\n")->and($result->errorOutput())->toBe("oops\n");
    });

    it('ends by the signal on Ctrl-C so the scan aborts instead of going on', function () {
        $sleep = capOwnSleep(114);
        $process = Process::path(base_path())->env(['PATH' => '/usr/bin:/bin'])
            ->start(['setsid', 'bash', '-c', 'source scripts/mac-checkup.sh; echo ready; pcc_capped 60 '.$sleep.'; echo continued']);
        $deadline = microtime(true) + 10;

        while (! str_contains($process->output(), 'ready') && microtime(true) < $deadline) {
            usleep(50_000);
        }

        usleep(500_000);
        Process::run(['kill', '-INT', '--', '-'.$process->id()]);

        try {
            $process->wait();
        } catch (ProcessSignaledException) {
        }

        expect($process->output())->not->toContain('continued')
            ->and(Process::run(['bash', '-c', 'pgrep -fx "'.$sleep.'" > /dev/null && echo alive || echo gone'])->output())->toBe("gone\n");
    });

    it('relays everything to a slow reader', function () {
        $result = capRun('pcc_capped 20 seq 1 300000 | { sleep 1; cksum; }', [], '/usr/bin:/bin');
        $expected = Process::run(['bash', '-c', 'seq 1 300000 | cksum'])->output();

        expect($result->output())->toBe($expected);
    });

    it('stops the command when the reader of its output goes away', function () {
        $word = 'pcc-'.bin2hex(random_bytes(6));
        $result = capRun('pcc_capped 60 yes '.$word.' | head -n 1; sleep 0.5; pgrep -fx "yes '.$word.'" > /dev/null && echo alive || echo gone', [], '/usr/bin:/bin');

        expect($result->output())->toBe("{$word}\ngone\n");
    });

    it('is available to the sections that run in their own bash', function () {
        $result = capRun('bash -c "pcc_capped 1 sleep 118; echo rc=\$?"', [], '/usr/bin:/bin');

        expect($result->output())->toBe("rc=142\n");
    });

    it('reports a command that cannot be started', function () {
        expect(capRun('pcc_capped 5 no-such-command-here; echo "rc=$?"', [], '/usr/bin:/bin')->output())->toBe("rc=127\n");
    });
});

describe('the storage step with a du that cannot be killed politely', function () {
    it('returns within the cap plus a small constant, partial, with the data it already has', function () {
        $base = capTree();
        $bin = fakeMacBin(['du' => 'for last; do :; done; case "$last" in */home) '.CAP_STUBBORN.' ;; esac; exec /usr/bin/du "$@"']);
        $start = microtime(true);
        $result = capStorage($base, $bin.':/usr/bin:/bin', 8);

        expect(microtime(true) - $start)->toBeLessThan(8 + 20)
            ->and($result->output())->toContain('scan: partial (time limit of 8 seconds reached)')->toContain('root|dir1|')
            ->and($result->errorOutput())->not->toContain('Alarm clock');
    });

    it('does not wait for the du that find starts for each file', function () {
        $start = microtime(true);
        $result = capStorage(capTree(), stuckFindDu().':/usr/bin:/bin', 6);

        expect(microtime(true) - $start)->toBeLessThan(6 + 20)
            ->and($result->output())->toContain('scan: partial')->toContain('folder|Downloads|')->not->toContain('type|');
    });

    it('returns on time when find itself hangs and writes nothing', function () {
        $bin = fakeMacBin(['find' => CAP_STUBBORN]);
        $start = microtime(true);
        $result = capStorage(capTree(), $bin.':/usr/bin:/bin', 5);

        expect(microtime(true) - $start)->toBeLessThan(5 + 20)->and($result->output())->toContain('scan: partial');
    });
});

describe('the space step with a du that cannot be killed politely', function () {
    it('does not wait for the du under find, keeps the places it measured and says partial', function () {
        $base = capTree();
        $start = microtime(true);
        $result = capSpace($base, stuckFindDu().':/usr/bin:/bin', 6);

        expect(microtime(true) - $start)->toBeLessThan(6 + 20)
            ->and($result->output())->toContain('scan: partial (time limit of 6 seconds reached)')->toContain('reclaim|caches|')
            ->and($result->errorOutput())->not->toContain('Alarm clock');
    });

    it('keeps the other places when the du of one place hangs for good', function () {
        $bin = fakeMacBin(['du' => 'for last; do :; done; case "$last" in *Caches) '.CAP_STUBBORN.' ;; esac; exec /usr/bin/du "$@"']);
        $start = microtime(true);
        $result = capSpace(capTree(), $bin.':/usr/bin:/bin', 8);

        expect(microtime(true) - $start)->toBeLessThan(8 + 20)
            ->and($result->output())->toContain('scan: partial')->toContain('reclaim|trash|')->not->toContain('reclaim|caches|');
    });

    it('returns on time when tmutil and every listing hang', function () {
        $bin = fakeMacBin(['tmutil' => CAP_STUBBORN, 'ls' => CAP_STUBBORN]);
        $start = microtime(true);
        $result = capSpace(capTree(), $bin.':/usr/bin:/bin', 6);

        expect(microtime(true) - $start)->toBeLessThan(6 + 20)->and($result->output())->toContain('scan:');
    });
});

describe('the script', function () {
    $script = fn (): string => (string) file_get_contents(base_path('scripts/mac-checkup.sh'));

    it('has no bare perl alarm left: every time box goes through the one runner', function () use ($script) {
        expect($script())->not->toContain('alarm shift')->not->toMatch('/perl -e .alarm/')->and(substr_count($script(), 'pcc_capped '))->toBeGreaterThan(10);
    });

    it('measures with du on one disk only, never following links', function () use ($script) {
        $code = preg_replace('/^\s*#.*$/m', '', $script()) ?? '';

        expect(preg_match_all('/\bdu -x /', $code))->toBeGreaterThanOrEqual(6)
            ->and($code)->not->toMatch('/\bdu (?!-x )/')->not->toMatch('/\bdu -[a-wyz]/');
    });

    it('keeps the runner to core perl and syntax that perl 5.18 on macOS 10.14 has', function () use ($script) {
        preg_match("/^PCC_CAP_PL='(.*?)^'$/ms", $script(), $match);
        $perl = $match[1] ?? '';

        expect($perl)->not->toBe('')->and($perl)->not->toContain("'")
            ->and($perl)->not->toMatch('/\b(say|state|given|when|isa|try|catch|signatures|postderef|fc)\b|\/\/|use (?:v|5\.(?:2|19)|feature|experimental|utf8)|<<~|->[@%$]\*|\bsetsid\b|\bTimeout\b/');
        preg_match_all('/^use ([A-Za-z:]+)/m', $perl, $modules);

        expect($modules[1])->toBe(['strict', 'warnings', 'POSIX', 'Time::HiRes']);
    });

    it('leaves the forked child with _exit, retries interrupted reads and writes, and sets the group once in the child', function () use ($script) {
        preg_match("/^PCC_CAP_PL='(.*?)^'$/ms", $script(), $match);
        $perl = $match[1] ?? '';

        expect($perl)->toContain('POSIX::_exit(127)')->toContain('EINTR')->toContain('EAGAIN')->toContain('EPIPE')
            ->and($perl)->not->toContain('setpgrp($pid')->and(substr_count($perl, 'setpgrp('))->toBe(1);
    });

    it('passes the runner through perl -c', function () use ($script) {
        preg_match("/^PCC_CAP_PL='(.*?)^'$/ms", $script(), $match);
        $result = Process::input($match[1])->run(['perl', '-c']);

        expect($result->successful())->toBeTrue($result->errorOutput());
    });

    it('is valid bash 3.2 style: no timeout or setsid command and no associative arrays', function () use ($script) {
        $code = preg_replace('/^\s*#.*$/m', '', $script()) ?? '';

        expect($code)->not->toMatch('/^\s*(?:timeout|setsid)\b|declare -A|\|&|&>>|mapfile|readarray/m');
    });
});
