<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

/**
 * Runs the whole Mac script against fake system commands and a fake curl in a throwaway HOME,
 * so nothing touches the real system or the network.
 *
 * @return array{dir: string, home: string}
 */
function afterFixMac(): array
{
    $dir = sys_get_temp_dir().'/pcc-afterfix-'.bin2hex(random_bytes(4));
    mkdir($dir.'/bin', 0777, true);
    mkdir($dir.'/home/Desktop', 0777, true);
    mkdir($dir.'/caches', 0777, true);
    mkdir($dir.'/uploads', 0777, true);
    touch($dir.'/calls');

    $quiet = ['top', 'log', 'sfltool', 'softwareupdate', 'diskutil', 'tmutil', 'osascript', 'purge', 'lsof', 'profiles', 'pmset', 'spctl', 'csrutil'];
    $scripts = array_fill_keys($quiet, 'true') + [
        'sudo' => 'exec "$@"',
        'fdesetup' => 'echo "FileVault is On."',
        'system_profiler' => 'echo "Serial Number (system): C02AFTERFIX1"',
        'fw' => 'case $1 in --getglobalstate) echo "Firewall is disabled. (State = 0)";; *) echo "fw $*" >> '.$dir.'/calls;; esac',
        'curl' => <<<SH
body=""; out=""; url=""
while [ \$# -gt 0 ]; do
  case \$1 in
    --data-binary) body=\${2#@}; shift ;;
    -o) out=\$2; shift ;;
    http*) url=\$1 ;;
  esac
  shift
done
cat > /dev/null
case \$url in
  */api/reports|*/api/scans)
    n=\$(ls {$dir}/uploads | wc -l | tr -d ' ')
    cp "\$body" "{$dir}/uploads/\$n.json"
    echo "\$url" > "{$dir}/uploads/\$n.url"
    echo '{"machine_id":7}' > "\$out"
    printf 201 ;;
  *) printf 500 ;;
esac
SH,
    ];

    foreach ($scripts as $name => $body) {
        file_put_contents($dir.'/bin/'.$name, "#!/bin/bash\n".$body."\n");
        chmod($dir.'/bin/'.$name, 0755);
    }

    return ['dir' => $dir, 'home' => $dir.'/home'];
}

/**
 * @param  list<string>  $args
 * @param  array<string, string>  $env
 */
function runAfterFix(array $mac, array $args, string $input = '', array $env = []): string
{
    $result = Process::path(base_path())
        ->timeout(180)
        ->env(array_merge([
            'HOME' => $mac['home'],
            'PATH' => $mac['dir'].'/bin:/usr/bin:/bin:/usr/sbin:/sbin',
            'PCC_FW_BIN' => $mac['dir'].'/bin/fw',
            'PCC_CACHE_DIR' => $mac['dir'].'/caches',
            'PCC_CACHE_MIN_MB' => '1000000',
            'PCC_BENCH_CPU_ITERATIONS' => '20000',
            'PCC_BENCH_MEM_MB' => '1',
            'PCC_BENCH_MEM_COPIES' => '2',
            'PCC_BENCH_DISK_MB' => '2',
            'PCC_BENCH_NET_BYTES' => '1048576',
            'PCC_BENCH_DIR' => $mac['home'],
            'PC_CHECKUP_TOKEN' => '',
            'PC_CHECKUP_CODE' => '',
        ], $env))
        ->input($input)
        ->run(['bash', 'scripts/mac-checkup.sh', ...$args]);

    return $result->output();
}

/**
 * @return array{audit: ?string, after: ?string}
 */
function desktopReports(array $mac): array
{
    $files = glob($mac['home'].'/Desktop/mac-checkup-*.md') ?: [];
    $after = array_values(array_filter($files, fn (string $f) => str_ends_with($f, '-after-fix.md')));
    $audit = array_values(array_diff($files, $after));

    return [
        'audit' => $audit === [] ? null : (string) file_get_contents($audit[0]),
        'after' => $after === [] ? null : (string) file_get_contents($after[0]),
    ];
}

/**
 * @return list<array{markdown: string, code?: string}>
 */
function uploadedBodies(array $mac): array
{
    $files = glob($mac['dir'].'/uploads/*.json') ?: [];
    sort($files);

    return array_map(fn (string $f) => json_decode((string) file_get_contents($f), true), $files);
}

it('writes an after-fix report linked to the audit by checksum and uploads both with a token', function () {
    $mac = afterFixMac();

    $output = runAfterFix($mac, ['--fix', '--token', 'tok_abc.123'], "y\n");
    $reports = desktopReports($mac);
    $uploads = uploadedBodies($mac);

    expect($reports['audit'])->toContain("- Format: 2\n")->not->toContain('- Kind:')->toContain('## Fix log')
        ->and($reports['after'])->toContain("- Format: 2\n- Kind: after-fix\n- Before checksum: ".hash('sha256', $reports['audit'])."\n")
        ->not->toContain('## Fix log')
        ->and($uploads)->toHaveCount(2)
        ->and($uploads[0]['markdown'])->toBe($reports['audit'])
        ->and($uploads[1]['markdown'])->toBe($reports['after'])
        ->and($output)->toContain('Uploading the after-fix report')
        ->and(substr_count($output, 'Token safety:'))->toBe(1);

    expect(file_get_contents($mac['dir'].'/calls'))->toContain('fw --setglobalstate on');
});

it('uploads only the audit report with a scan code and says why the after-fix report stayed local', function () {
    $mac = afterFixMac();

    $output = runAfterFix($mac, ['--fix', '--code', '123456'], "y\n");
    $reports = desktopReports($mac);
    $uploads = uploadedBodies($mac);

    expect($reports['after'])->toContain('- Kind: after-fix')
        ->and($uploads)->toHaveCount(1)
        ->and($uploads[0]['code'])->toBe('123456')
        ->and($uploads[0]['markdown'])->toBe($reports['audit'])
        ->and($output)->toContain('The after-fix report was not uploaded: a scan code works once, so it was kept on the Desktop.');
});

it('writes and uploads nothing extra when no fix was applied', function (array $args, string $input) {
    $mac = afterFixMac();

    $output = runAfterFix($mac, $args, $input);
    $reports = desktopReports($mac);

    expect($reports['after'])->toBeNull()
        ->and($reports['audit'])->not->toBeNull()
        ->and(uploadedBodies($mac))->toHaveCount(1)
        ->and($output)->not->toContain('after-fix')
        ->and(file_get_contents($mac['dir'].'/calls'))->toBe('');
})->with([
    'fix mode, every answer no' => [['--fix', '--token', 'tok_abc.123'], "n\n"],
    'fix mode, closed stdin' => [['--fix', '--token', 'tok_abc.123'], ''],
    'no fix mode' => [['--token', 'tok_abc.123'], "y\n"],
]);

it('runs the benchmark before and after the fixes, each report with its own section', function () {
    $mac = afterFixMac();

    runAfterFix($mac, ['--fix', '--benchmark', '--token', 'tok_abc.123'], "y\n");
    $reports = desktopReports($mac);

    foreach (['audit', 'after'] as $kind) {
        expect($reports[$kind])->toContain("\n## Benchmark\n\n- cpu_single_ms: ")->toContain('- disk_read_mb_s: ');
    }

    expect(substr_count((string) $reports['after'], '## Benchmark'))->toBe(1);
});

it('keeps the audit report without a Benchmark section when --benchmark is not given', function () {
    $mac = afterFixMac();

    runAfterFix($mac, ['--fix', '--token', 'tok_abc.123'], "y\n");
    $reports = desktopReports($mac);

    expect($reports['audit'])->not->toContain('## Benchmark')
        ->and($reports['after'])->not->toContain('## Benchmark');
});
