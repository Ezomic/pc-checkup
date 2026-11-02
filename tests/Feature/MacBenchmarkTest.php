<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

/**
 * Runs code after sourcing the Mac script, with the arguments as $1, $2.
 *
 * @param  list<string>  $args
 * @param  array<string, string>  $env
 */
function benchScript(string $code, array $args = [], array $env = []): string
{
    $env += [
        'PCC_BENCH_CPU_ITERATIONS' => '20000',
        'PCC_BENCH_MEM_MB' => '1',
        'PCC_BENCH_MEM_COPIES' => '2',
        'PCC_BENCH_DISK_MB' => '2',
        'PCC_BENCH_NET_BYTES' => '1048576',
    ];

    $result = Process::path(base_path())->env($env)->run(['bash', '-c', 'source scripts/mac-checkup.sh; '.$code, 'bash', ...$args]);

    expect($result->errorOutput())->toBe('');

    return $result->output();
}

it('is off by default and turned on by the flag', function () {
    expect(benchScript('echo "$PCC_BENCHMARK"'))->toBe("0\n")
        ->and((string) file_get_contents(base_path('scripts/mac-checkup.sh')))->toContain('--benchmark) PCC_BENCHMARK=1');
});

it('formats a result as a report line and drops anything that is not a whole number', function () {
    expect(benchScript('pcc_bench_line cpu_single_ms 1234'))->toBe("- cpu_single_ms: 1234\n")
        ->and(benchScript('pcc_bench_line cpu_single_ms ""; pcc_bench_line memory_mb_s 12.5; pcc_bench_line memory_mb_s abc'))->toBe('');
});

it('writes the Benchmark section only when a test produced a line', function () {
    $file = tempnam(sys_get_temp_dir(), 'bench');
    file_put_contents($file, "- cpu_single_ms: 10\n- disk_read_mb_s: 99\n");

    expect(benchScript('pcc_benchmark_section "$1"', [$file]))->toBe("\n## Benchmark\n\n- cpu_single_ms: 10\n- disk_read_mb_s: 99\n");

    file_put_contents($file, '');

    expect(benchScript('pcc_benchmark_section "$1"', [$file]))->toBe('')
        ->and(benchScript('pcc_benchmark_section /nonexistent'))->toBe('');

    unlink($file);
});

it('measures each local test as a whole number', function (string $function) {
    expect(trim(benchScript($function)))->toMatch('/^[0-9]+$/');
})->with(['pcc_bench_cpu_single', 'pcc_bench_cpu_multi', 'pcc_bench_memory']);

it('measures disk write and read and leaves the file for the caller to clean up', function () {
    $file = tempnam(sys_get_temp_dir(), 'bench');

    $output = benchScript('pcc_bench_disk_write "$1"; pcc_bench_disk_read "$1"', [$file]);

    expect(trim($output))->toMatch('/^[0-9]+\n[0-9]+$/')
        ->and(filesize($file))->toBe(2 * 1048576);

    unlink($file);
});

it('turns bytes and seconds into megabits per second', function () {
    expect(trim(benchScript('pcc_bench_mbps 25000000 2')))->toBe('100')
        ->and(trim(benchScript('pcc_bench_mbps 1000000 0')))->toMatch('/^[0-9]+$/');
});

it('authenticates with a token or a scan code and never builds a header without one', function () {
    expect(benchScript('PCC_TOKEN=abc123; pcc_bench_auth'))->toBe("header = \"Authorization: Bearer abc123\"\n")
        ->and(benchScript('PCC_TOKEN=""; PCC_CODE=012345; pcc_bench_auth'))->toBe("header = \"X-Scan-Code: 012345\"\n")
        ->and(benchScript('PCC_TOKEN=""; PCC_CODE=""; pcc_bench_auth || echo none'))->toBe("none\n")
        ->and(benchScript('PCC_TOKEN=""; PCC_CODE=12; pcc_bench_auth || echo none'))->toBe("none\n")
        ->and(benchScript('PCC_TOKEN="bad token"; pcc_bench_auth || echo none'))->toBe("none\n");
});

it('uses the configured site address and rejects an unsafe one', function () {
    expect(benchScript('PCC_TOKEN=""; pcc_bench_base', env: ['PC_CHECKUP_URL' => 'https://example.test/']))->toBe('https://example.test')
        ->and(benchScript('pcc_bench_base || echo none', env: ['PC_CHECKUP_URL' => 'http://example.test']))->toBe("none\n");
});

it('says why the network tests cannot run when the server is unreachable', function () {
    $output = benchScript('pcc_bench_net_check http://127.0.0.1:1 "header = \"X-Scan-Code: 123456\""; echo "rc=$?"');

    expect($output)->toBe("http://127.0.0.1:1 could not be reached\nrc=1\n");
});

it('skips the network tests with a note when there is no token or scan code', function () {
    $out = tempnam(sys_get_temp_dir(), 'bench');

    $output = benchScript('PCC_TOKEN=""; PCC_CODE=""; pcc_run_benchmark "$1"', [$out]);

    expect($output)->toContain('CPU, one core')
        ->and($output)->toContain('Network tests skipped: they need a token or a scan code')
        ->and((string) file_get_contents($out))->toContain('- cpu_single_ms: ')
        ->toContain('- disk_read_mb_s: ')
        ->not->toContain('net_');

    unlink($out);
});

it('skips the network tests with a note when the server is unreachable', function () {
    $out = tempnam(sys_get_temp_dir(), 'bench');

    $output = benchScript('PCC_TOKEN=""; PCC_CODE=123456; PCC_CLI_URL=http://127.0.0.1:1; pcc_run_benchmark "$1"', [$out]);

    expect($output)->toContain('Network tests skipped: http://127.0.0.1:1 could not be reached.')
        ->and((string) file_get_contents($out))->not->toContain('net_');

    unlink($out);
});

it('removes its temp files after a run', function () {
    $dir = sys_get_temp_dir().'/bench-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $out = tempnam(sys_get_temp_dir(), 'bench');

    benchScript('PCC_TOKEN=""; PCC_CODE=""; pcc_run_benchmark "$1"', [$out], ['PCC_BENCH_DIR' => $dir]);

    expect(glob($dir.'/.pcc-bench.*'))->toBe([]);

    unlink($out);
    rmdir($dir);
});

it('removes the disk test file when interrupted', function () {
    $dir = sys_get_temp_dir().'/bench-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $out = tempnam(sys_get_temp_dir(), 'bench');

    $result = Process::path(base_path())->env([
        'PCC_BENCH_DIR' => $dir,
        'PCC_BENCH_CPU_ITERATIONS' => '20000',
        'PCC_BENCH_DISK_MB' => '2',
    ])->run(['bash', '-c', 'source scripts/mac-checkup.sh; pcc_bench_disk_write() { kill -INT $$; sleep 1; }; PCC_TOKEN=""; PCC_CODE=""; pcc_run_benchmark "$1"', 'bash', $out]);

    expect(glob($dir.'/.pcc-bench.*'))->toBe([])
        ->and($result->exitCode())->toBe(130);

    unlink($out);
    rmdir($dir);
});
