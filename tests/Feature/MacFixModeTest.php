<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

/**
 * Builds a directory of fake system commands that print canned output and append every
 * state-changing call to "calls", so the fix runner never touches the real Mac.
 *
 * @param  array<string, string>  $outputs  command name => script body
 * @return array{bin: string, dir: string}
 */
function fakeMac(array $outputs = []): array
{
    $dir = sys_get_temp_dir().'/pcc-fix-'.bin2hex(random_bytes(4));
    mkdir($dir.'/bin', 0777, true);
    mkdir($dir.'/caches', 0777, true);
    touch($dir.'/calls');

    $scripts = array_merge([
        'sudo' => 'exec "$@"',
        'fw' => 'case $1 in --getglobalstate) echo "Firewall is disabled. (State = 0)";; *) echo "fw $*" >> '.$dir.'/calls;; esac',
        'spctl' => 'case $1 in --status) echo "assessments disabled";; *) echo "spctl $*" >> '.$dir.'/calls;; esac',
        'pmset' => 'case $1 in -g) echo " lowpowermode         1";; *) echo "pmset $*" >> '.$dir.'/calls; exit 1;; esac',
        'softwareupdate' => 'case $1 in -l) echo "* Label: macOS 15.1";; *) echo "softwareupdate $*" >> '.$dir.'/calls;; esac',
        'fdesetup' => 'echo "FileVault is Off."',
        'csrutil' => 'echo "System Integrity Protection status: enabled."',
        'profiles' => 'echo "MDM enrollment: No"',
        'system_profiler' => 'true',
        'diskutil' => 'true',
    ], $outputs);

    foreach ($scripts as $name => $body) {
        file_put_contents($dir.'/bin/'.$name, "#!/bin/bash\n".$body."\n");
        chmod($dir.'/bin/'.$name, 0755);
    }

    return ['bin' => $dir.'/bin', 'dir' => $dir];
}

/**
 * @param  array{bin: string, dir: string}  $mac
 * @param  array<string, string>  $env
 */
function runFix(array $mac, string $code, string $input = '', array $env = []): string
{
    $result = Process::path(base_path())
        ->env(array_merge([
            'PATH' => $mac['bin'].':/usr/bin:/bin:/usr/sbin:/sbin',
            'PCC_FW_BIN' => $mac['bin'].'/fw',
            'PCC_CACHE_DIR' => $mac['dir'].'/caches',
            'PCC_CACHE_MIN_MB' => '1000000',
        ], $env))
        ->input($input)
        ->run(['bash', '-c', 'source scripts/mac-checkup.sh; '.$code]);

    expect($result->errorOutput())->toBe('');

    return $result->output();
}

function fakeCalls(array $mac): string
{
    return (string) file_get_contents($mac['dir'].'/calls');
}

it('applies, skips and fails fixes by the answers given and logs each one per the contract', function () {
    $mac = fakeMac();
    $bin = $mac['bin'];
    file_put_contents($mac['dir'].'/caches/big.bin', str_repeat('x', 4096));
    file_put_contents($mac['dir'].'/Documents.txt', 'mine');

    $output = runFix($mac, 'pcc_fix_run; cat "$PCC_FIX_LOG"', "y\nn\ny\nn\ny\n", ['PCC_CACHE_MIN_MB' => '0']);

    $lines = array_values(array_filter(explode("\n", $output), fn (string $line) => str_starts_with($line, '- ')));

    expect($lines)->toBe([
        "- firewall-off | applied | sudo {$bin}/fw --setglobalstate on | undo: sudo {$bin}/fw --setglobalstate off",
        '- gatekeeper-off | skipped | sudo spctl --master-enable | undo: sudo spctl --master-disable',
        '- low-power-mode | failed | sudo pmset -a lowpowermode 0 | undo: sudo pmset -a lowpowermode 1',
        '- updates-pending | skipped | sudo softwareupdate --install --all | undo: none',
        "- caches-large | applied | find \"{$mac['dir']}/caches\" -mindepth 1 -maxdepth 1 -exec rm -rf {} + | undo: none",
    ]);

    foreach ($lines as $line) {
        expect(preg_match('/^- (\S+) \| (applied|skipped|failed) \| (.+) \| undo: (.*)$/u', $line))->toBe(1);
    }

    expect(fakeCalls($mac))->toContain('fw --setglobalstate on')
        ->toContain('pmset -a lowpowermode 0')
        ->not->toContain('spctl --master-enable')
        ->not->toContain('softwareupdate --install')
        ->and(glob($mac['dir'].'/caches/*'))->toBe([])
        ->and(file_exists($mac['dir'].'/Documents.txt'))->toBeTrue()
        ->and($output)->toContain('[can fix here] Application firewall is off (firewall-off)')
        ->toContain('Size preview:')
        ->toContain('big.bin')
        ->toContain('Apply this fix? [y/N]');
});

it('skips every fix and changes nothing when stdin is empty', function () {
    $mac = fakeMac();

    $output = runFix($mac, 'pcc_fix_run; cat "$PCC_FIX_LOG"', '');

    expect(substr_count($output, '| skipped |'))->toBe(4)
        ->and($output)->not->toContain('| applied |')
        ->and(fakeCalls($mac))->toBe('');
});

it('treats anything but y as no', function (string $answer) {
    $mac = fakeMac();

    $output = runFix($mac, 'pcc_fix_run; cat "$PCC_FIX_LOG"', str_repeat($answer."\n", 4));

    expect($output)->not->toContain('| applied |')
        ->and(fakeCalls($mac))->toBe('');
})->with(['', 'n', 'yep', 'sure', ' ']);

it('only advises on findings the script must not fix and never logs them', function () {
    $mac = fakeMac([
        'fw' => 'echo "Firewall is enabled. (State = 1)"',
        'spctl' => 'echo "assessments enabled"',
        'pmset' => 'echo " lowpowermode 0"',
        'softwareupdate' => 'echo "No new software available."',
        'csrutil' => 'echo "System Integrity Protection status: disabled."',
        'profiles' => 'echo "MDM enrollment: Yes"',
        'diskutil' => 'echo "   SMART Status:             Failing"',
        'system_profiler' => 'echo "Condition: Service Recommended"; echo "Activation Lock Status: Enabled"',
    ]);

    $output = runFix($mac, 'pcc_fix_run; cat "$PCC_FIX_LOG"', "y\ny\ny\ny\ny\n");

    foreach (['filevault-off', 'sip-disabled', 'mdm-enrolled', 'activation-lock', 'battery-wear', 'disk-smart'] as $id) {
        expect($output)->toMatch('/\[needs you\] .+ \('.$id.'\)/');
    }

    expect($output)->not->toContain('[can fix here]')
        ->not->toContain('Apply this fix?')
        ->not->toContain('| applied |')
        ->and(fakeCalls($mac))->toBe('');
});

it('offers cache cleanup only above the size threshold', function () {
    $mac = fakeMac();
    file_put_contents($mac['dir'].'/caches/small.bin', 'x');

    expect(runFix($mac, 'pcc_fix_present caches-large && echo yes || echo no', '', ['PCC_CACHE_MIN_MB' => '1']))->toBe("no\n")
        ->and(runFix($mac, 'pcc_fix_present caches-large && echo yes || echo no', '', ['PCC_CACHE_MIN_MB' => '0']))->toBe("yes\n");
});

it('never builds a command that turns FileVault off or touches SIP or user documents', function () {
    $mac = fakeMac();

    $output = runFix($mac, 'for id in $PCC_FIX_IDS; do pcc_fix_command "$id"; pcc_fix_undo "$id"; done');

    expect($output)->not->toContain('fdesetup')
        ->not->toContain('csrutil')
        ->not->toContain('Documents')
        ->not->toContain('Desktop');
});

it('writes the change log next to the report and removes the temporary log', function () {
    $mac = fakeMac();
    $report = $mac['dir'].'/mac-checkup-1.md';
    $changes = $mac['dir'].'/mac-checkup-1-changes.txt';

    $output = runFix($mac, 'pcc_fix_run >/dev/null; log=$PCC_FIX_LOG; pcc_fix_finish '.$report.' '.$changes.'; [ -e "$log" ] && echo leftover', "y\nn\nn\nn\n");

    expect($output)->toContain('Change log saved to:')
        ->not->toContain('leftover')
        ->and((string) file_get_contents($changes))->toContain('PC Checkup change log')
        ->toContain("Report: {$report}")
        ->toContain('- firewall-off | applied |')
        ->toContain('- gatekeeper-off | skipped |');
});

it('writes a change log that says nothing changed when no fix ran', function () {
    $mac = fakeMac([
        'fw' => 'echo "Firewall is enabled. (State = 1)"',
        'spctl' => 'echo "assessments enabled"',
        'pmset' => 'echo " lowpowermode 0"',
        'softwareupdate' => 'echo "No new software available."',
        'fdesetup' => 'echo "FileVault is On."',
    ]);
    $changes = $mac['dir'].'/changes.txt';

    $output = runFix($mac, 'pcc_fix_run; pcc_fix_finish report.md "$PCC_CHANGES"', '', ['PCC_CHANGES' => $changes]);

    expect($output)->toContain('Nothing to fix.')
        ->and((string) file_get_contents($changes))->toContain('No changes were made.');
});
