<?php

declare(strict_types=1);

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\ScriptTemp;

function wcmMissing(): bool
{
    return PHP_OS_FAMILY === 'Windows' || trim((string) shell_exec('command -v perl 2>/dev/null')) === '';
}

function wcmProfiler(string $output, int $exit = 0): string
{
    $dir = ScriptTemp::dir('pcc-wcm-out');
    file_put_contents($dir.'/airport.txt', $output);

    return ScriptTemp::bin('pcc-wcm-bin', ['system_profiler' => 'cat '.escapeshellarg($dir.'/airport.txt')."\nexit {$exit}"]);
}

function wcmModern(): string
{
    return <<<'TXT'
Wi-Fi:

      Software Versions:
          CoreWLAN: 16.0 (1657)
      Interfaces:
        en0:
          Card Type: Wi-Fi  (0x14E4, 0x4387)
          Status: Connected
          Current Network Information:
            HomeNet-Secret:
              PHY Mode: 802.11ac
              BSSID: 12:34:56:78:9a:bc
              Channel: 149 (5GHz, 80MHz)
              Country Code: NL
              Network Type: Infrastructure
              Security: WPA2 Personal
              Signal / Noise: -55 dBm / -92 dBm
              Transmit Rate: 585
          Other Local Wi-Fi Networks:
            Neighbour-Net:
              PHY Mode: 802.11n
              Channel: 6 (2GHz, 20MHz)
              Network Type: Infrastructure
              Security: WPA2 Personal
              Signal / Noise: -20 dBm / -90 dBm
            Café Wifi:
              PHY Mode: 802.11ac
              Channel: 36 (5GHz, 80MHz)
              Signal / Noise: -30 dBm / -90 dBm
TXT;
}

function wcmWifi(string $output, int $exit = 0, array $env = []): ProcessResult
{
    return Process::path(base_path())->timeout(60)->env(array_merge([
        'PATH' => wcmProfiler($output, $exit).':'.getenv('PATH'),
    ], $env))->run(['bash', '-c', 'source scripts/mac-checkup.sh; pcc_wifi_report']);
}

/**
 * @return list<string>
 */
function wcmLines(ProcessResult $result): array
{
    return array_values(array_filter(explode("\n", trim($result->throw()->output())), fn (string $line): bool => $line !== ''));
}

describe('the Mac Wi-Fi step', function () {
    it('prints only the signal and band of the current network', function () {
        $result = wcmWifi(wcmModern());

        expect(wcmLines($result))->toBe(['format: 1', 'signal-dbm: -55', 'band: 5 GHz']);

        foreach (['HomeNet-Secret', 'Neighbour', 'Caf', '12:34', 'WPA2', 'Country', '-20', '-30'] as $leak) {
            expect($result->output())->not->toContain($leak);
        }
    })->skip(wcmMissing(), 'perl is not installed here');

    it('reads the older layout, where the band follows from the channel number', function (string $channel, string $band) {
        $output = "Wi-Fi:\n      Interfaces:\n        en0:\n          Status: Connected\n          Current Network Information:\n            Some SSID:\n              PHY Mode: 802.11n\n              Channel: {$channel}\n              Signal / Noise: -60 dBm / -90 dBm\n";

        expect(wcmLines(wcmWifi($output)))->toBe(['format: 1', 'signal-dbm: -60', "band: {$band} GHz"]);
    })->with([['1', '2.4'], ['11', '2.4'], ['14', '2.4'], ['36', '5'], ['149', '5'], ['6 (2GHz, 20MHz)', '2.4'], ['1 (6GHz, 160MHz)', '6'], ['5 (2,4GHz, 20MHz)', '2.4']])->skip(wcmMissing(), 'perl is not installed here');

    it('leaves the band out when the channel says nothing', function (string $channel) {
        $output = "      Current Network Information:\n        Net:\n          Channel: {$channel}\n          Signal / Noise: -70 dBm / -90 dBm\n";

        expect(wcmLines(wcmWifi($output)))->toBe(['format: 1', 'signal-dbm: -70']);
    })->with(['20', '300', '999 (4GHz, 20MHz)'])->skip(wcmMissing(), 'perl is not installed here');

    it('ignores a network name that looks like a property', function () {
        $output = "      Current Network Information:\n        Signal / Noise: -10 dBm / -20 dBm:\n          Channel: 36 (5GHz, 80MHz)\n          Signal / Noise: -71 dBm / -92 dBm\n";

        expect(wcmLines(wcmWifi($output)))->toBe(['format: 1', 'signal-dbm: -71', 'band: 5 GHz']);
    })->skip(wcmMissing(), 'perl is not installed here');

    it('says it could not tell when there is no current network', function (string $output) {
        expect(wcmLines(wcmWifi($output)))->toBe(['format: 1', 'status: not connected or could not check']);
    })->with([
        'not connected' => ["Wi-Fi:\n      Interfaces:\n        en0:\n          Status: Inactive\n"],
        'empty' => [''],
        'only other networks' => ["          Other Local Wi-Fi Networks:\n            X:\n              Channel: 6\n              Signal / Noise: -40 dBm / -90 dBm\n"],
        'dutch headings' => ["      Huidig netwerk:\n        Net:\n          Kanaal: 36\n          Signaal / ruis: -50 dBm / -90 dBm\n"],
        'no signal line' => ["      Current Network Information:\n        Net:\n          Channel: 36 (5GHz, 80MHz)\n"],
    ])->skip(wcmMissing(), 'perl is not installed here');

    it('does not read a signal that is not a negative dBm pair', function (string $signal) {
        $output = "      Current Network Information:\n        Net:\n          Signal / Noise: {$signal}\n";

        expect(wcmLines(wcmWifi($output)))->toBe(['format: 1', 'status: not connected or could not check']);
    })->with(['55 dBm / 90 dBm', '-55 dBm', 'loud', '-55 dBm / quiet', '-55 dBm / -90 dBm and more'])->skip(wcmMissing(), 'perl is not installed here');

    it('says it could not check when the command fails', function () {
        expect(wcmLines(wcmWifi(wcmModern(), 1)))->toBe(['format: 1', 'status: could not check']);
    })->skip(wcmMissing(), 'perl is not installed here');

    it('stops at the time cap and leaves no process behind', function () {
        $bin = ScriptTemp::bin('pcc-wcm-slow', ['system_profiler' => 'sleep 30']);
        $started = microtime(true);
        $result = Process::path(base_path())->timeout(60)->env(['PATH' => $bin.':'.getenv('PATH'), 'PCC_WIFI_CAP' => '1'])
            ->run(['bash', '-c', 'source scripts/mac-checkup.sh; pcc_wifi_report']);

        expect(wcmLines($result))->toBe(['format: 1', 'status: could not check'])->and(microtime(true) - $started)->toBeLessThan(10.0);
    })->skip(wcmMissing(), 'perl is not installed here');

    it('reads nothing but the one system report and changes nothing', function () {
        $script = (string) file_get_contents(base_path('scripts/mac-checkup.sh'));
        preg_match('/^pcc_wifi_report\(\) \{.*?^\}$/ms', $script, $match);

        expect($match[0])->toContain('pcc_capped "$PCC_WIFI_CAP" system_profiler SPAirPortDataType')
            ->not->toContain('networksetup')->not->toContain('security ')->not->toContain('airport')->not->toContain('preferred');
    });
});

function wcmCrashDirs(): array
{
    $base = ScriptTemp::dir('pcc-wcm-crash');
    File::ensureDirectoryExists($base.'/user');
    File::ensureDirectoryExists($base.'/system');

    return [$base.'/user', $base.'/system'];
}

function wcmReport(string $user, string $system, array $env = [], ?string $bin = null): ProcessResult
{
    return Process::path(base_path())->timeout(60)->env(array_merge([
        'PCC_CRASH_USER_DIR' => $user,
        'PCC_CRASH_SYSTEM_DIR' => $system,
        'PCC_TODAY' => '2026-11-06',
        'PATH' => ($bin === null ? '' : $bin.':').getenv('PATH'),
    ], $env))->run(['bash', '-c', 'source scripts/mac-checkup.sh; pcc_crashes_report']);
}

function wcmTouch(string $path, int $daysAgo = 3, string $content = 'SECRET-CONTENT'): void
{
    File::ensureDirectoryExists(dirname($path));
    file_put_contents($path, $content);
    touch($path, time() - $daysAgo * 86400);
}

describe('the Mac crash step', function () {
    it('counts crash reports by app name, newest layout and older layout alike', function () {
        [$user, $system] = wcmCrashDirs();

        foreach (['101010', '111111', '121212'] as $time) {
            wcmTouch("{$user}/Safari-2026-11-05-{$time}.ips");
        }

        wcmTouch($user.'/Notes_2026-11-04-090909_Robbins-MacBook-Pro.crash');
        wcmTouch($user.'/Notes_2026-11-03-090909_Robbins-MacBook-Pro.crash');
        wcmTouch($system.'/Google Chrome Helper-2026-11-02-080808.ips');

        $result = wcmReport($user, $system);

        expect(wcmLines($result))->toBe([
            'format: 1', 'scanned: 2026-11-06', 'window-days: 30', 'scan: complete', 'total: 6',
            'app|Safari|3', 'app|Notes|2', 'app|Google Chrome Helper|1',
        ]);
    })->skip(wcmMissing(), 'perl is not installed here');

    it('never prints a host name, a path, a file content or a user name', function () {
        [$user, $system] = wcmCrashDirs();
        wcmTouch($user.'/Notes_2026-11-04-090909_Robbins-MacBook-Pro.crash');
        wcmTouch($user.'/Safari-2026-11-05-101010.ips');

        $output = wcmReport($user, $system)->throw()->output();

        foreach (['Robbins', 'MacBook', 'SECRET-CONTENT', '/', '.crash', '.ips', '2026-11-04-'] as $leak) {
            expect($output)->not->toContain($leak);
        }
    })->skip(wcmMissing(), 'perl is not installed here');

    it('skips reports that are not app crashes, old reports, folders and names without a date', function () {
        [$user, $system] = wcmCrashDirs();
        wcmTouch($user.'/App-2026-11-05-101010.ips');
        wcmTouch($user.'/JetsamEvent-2026-11-05-101010.ips');
        wcmTouch($user.'/ResetCounter-2026-11-05-101010.diag');
        wcmTouch($user.'/Kernel-2026-11-05-101010.ips');
        wcmTouch($user.'/stacks-2026-11-05-101010.ips');
        wcmTouch($user.'/Panic Viewer-2026-11-05-101010.ips');
        wcmTouch($user.'/Old-2026-09-01-101010.ips', 60);
        wcmTouch($user.'/Edge-2026-10-08-101010.ips', 29);
        wcmTouch($user.'/Retired/Hidden-2026-11-05-101010.ips');
        wcmTouch($user.'/nodate.ips');
        wcmTouch($user.'/Note-2026-11-05-101010.txt');
        wcmTouch($system.'/panic-full-2026-11-05-101010.panic');
        mkdir($user.'/Dir-2026-11-05-101010.ips');

        $names = array_filter(wcmLines(wcmReport($user, $system)), fn (string $line): bool => str_starts_with($line, 'app|'));

        expect(array_values($names))->toBe(['app|App|1', 'app|Edge|1', 'app|Panic Viewer|1']);
    })->skip(wcmMissing(), 'perl is not installed here');

    it('strips pipes and control characters from names and keeps unicode', function () {
        [$user, $system] = wcmCrashDirs();
        wcmTouch($user."/We|ird\tName-2026-11-05-101010.ips");
        wcmTouch($user."/Caf\u{e9} \u{1F4A5}-2026-11-05-101010.ips");

        $apps = array_values(array_filter(wcmLines(wcmReport($user, $system)), fn (string $line): bool => str_starts_with($line, 'app|')));

        expect($apps)->toBe(["app|Caf\u{e9} \u{1F4A5}|1", 'app|WeirdName|1']);
    })->skip(wcmMissing(), 'perl is not installed here');

    it('lists at most ten apps but counts all crashes', function () {
        [$user, $system] = wcmCrashDirs();

        foreach (range(1, 12) as $number) {
            wcmTouch(sprintf('%s/App%02d-2026-11-05-101010.ips', $user, $number));
        }

        $lines = wcmLines(wcmReport($user, $system));

        expect($lines)->toContain('total: 12')->and(array_filter($lines, fn (string $line): bool => str_starts_with($line, 'app|')))->toHaveCount(10);
    })->skip(wcmMissing(), 'perl is not installed here');

    it('reports a clean Mac as zero crashes, not as missing', function () {
        [$user, $system] = wcmCrashDirs();

        expect(wcmLines(wcmReport($user, $system)))->toBe(['format: 1', 'scanned: 2026-11-06', 'window-days: 30', 'scan: complete', 'total: 0']);
    })->skip(wcmMissing(), 'perl is not installed here');

    it('says it could not check when neither folder can be read', function () {
        $base = ScriptTemp::dir('pcc-wcm-none');

        expect(wcmLines(wcmReport($base.'/missing', $base.'/also-missing')))->toBe(['format: 1', 'scanned: 2026-11-06', 'window-days: 30', 'scan: could not check']);
    })->skip(wcmMissing(), 'perl is not installed here');

    it('still counts the folder it can read when the other is missing', function () {
        [$user] = wcmCrashDirs();
        wcmTouch($user.'/App-2026-11-05-101010.ips');

        expect(wcmLines(wcmReport($user, $user.'/missing')))->toContain('scan: complete')->toContain('app|App|1');
    })->skip(wcmMissing(), 'perl is not installed here');

    it('falls back to 30 days for a bad window', function (string $days) {
        [$user, $system] = wcmCrashDirs();

        expect(wcmLines(wcmReport($user, $system, ['PCC_CRASH_DAYS' => $days])))->toContain('window-days: 30');
    })->with(['', '0', '-5', 'abc', '3.5', '07'])->skip(wcmMissing(), 'perl is not installed here');

    it('flags a partial scan when the time cap is reached and leaves no process behind', function () {
        [$user, $system] = wcmCrashDirs();
        $bin = ScriptTemp::bin('pcc-wcm-find', ['find' => 'sleep 30']);
        $started = microtime(true);
        $lines = wcmLines(wcmReport($user, $system, ['PCC_CRASH_CAP' => '1'], $bin));

        expect($lines)->toContain('scan: partial (time limit of 1 seconds reached)')->toContain('total: 0')
            ->and(microtime(true) - $started)->toBeLessThan(15.0);
    })->skip(wcmMissing(), 'perl is not installed here');

    it('only lists file names and never opens one', function () {
        $script = (string) file_get_contents(base_path('scripts/mac-checkup.sh'));
        preg_match('/^pcc_crashes_report\(\) \{.*?^\}$/ms', $script, $match);

        expect($match[0])->toContain('find "$dir" -maxdepth 1 -type f')->not->toContain('cat ')->not->toContain('grep -r')->not->toContain('< "');
    });
});

describe('the Mac script as a whole', function () {
    it('keeps the update check, the privacy notice and the time cap helper, and adds both sections', function () {
        $script = (string) file_get_contents(base_path('scripts/mac-checkup.sh'));

        expect($script)->toContain("pcc_banner\npcc_update_check || exit 1")
            ->toContain('pcc_capped() { perl -e "$PCC_CAP_PL" "$@"; }')
            ->toContain('section "Wi-Fi signal" pcc_wifi_report')
            ->toContain('section "Recent app crashes" pcc_crashes_report');
    });
});
