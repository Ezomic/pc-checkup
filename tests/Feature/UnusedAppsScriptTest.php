<?php

declare(strict_types=1);

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

function uasMissing(): bool
{
    return PHP_OS_FAMILY === 'Windows' || trim((string) shell_exec('command -v perl 2>/dev/null')) === '';
}

function uasBin(array $scripts): string
{
    $dir = sys_get_temp_dir().'/pcc-uas-bin-'.bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);

    foreach ($scripts as $name => $body) {
        file_put_contents($dir.'/'.$name, "#!/bin/bash\n".$body."\n");
        chmod($dir.'/'.$name, 0755);
    }

    return $dir;
}

function uasApp(string $path, int $kib, ?string $mdls = null, ?string $mtime = null): void
{
    File::ensureDirectoryExists($path.'/Contents');
    file_put_contents($path.'/Contents/blob', str_repeat('x', $kib * 1024));

    if ($mtime !== null) {
        touch($path, (int) strtotime($mtime));
    }

    if ($mdls !== null) {
        file_put_contents(dirname($path).'/.mdls-'.basename($path), $mdls);
    }
}

function uasMdls(string $id, string $used, string $added): string
{
    return "kMDItemCFBundleIdentifier = \"{$id}\"\nkMDItemDateAdded          = {$added}\nkMDItemLastUsedDate       = {$used}\n";
}

const UAS_FAKE_MDLS = <<<'SH'
for last; do :; done
meta="$(dirname "$last")/.mdls-$(basename "$last")"
if [ -f "$meta" ]; then cat "$meta"; else echo "could not find $last" >&2; exit 1; fi
SH;

function uasTree(): string
{
    $base = sys_get_temp_dir().'/pcc-uas-'.bin2hex(random_bytes(4));
    $apps = $base.'/Applications';
    $old = '2023-05-01 10:11:12 +0000';
    $added = '2021-01-01 10:11:12 +0000';

    uasApp($apps.'/Old Editor.app', 3000, uasMdls('com.old.editor', $old, $added));
    uasApp($apps.'/Recent.app', 2000, uasMdls('com.recent.app', '2026-09-01 08:00:00 +0000', $added));
    uasApp($apps.'/Edge Case.app', 1900, uasMdls('com.edge.case', '2025-11-07 08:00:00 +0000', $added));
    uasApp($apps.'/Safari.app', 5000, uasMdls('com.apple.Safari', '2020-01-01 00:00:00 +0000', '2019-01-01 00:00:00 +0000'));
    uasApp($apps.'/Never.app', 1500, uasMdls('com.never.app', '(null)', '2022-01-01 10:00:00 +0000'));
    uasApp($apps.'/Brand New.app', 1700, uasMdls('com.brandnew.app', '(null)', '2026-06-01 10:00:00 +0000'));
    uasApp($apps.'/No Metadata.app', 1200, null, '2020-03-04 12:00:00');
    uasApp($apps.'/Tiny.app', 50, uasMdls('com.tiny.app', $old, $added));
    uasApp($apps.'/Utilities/Disk Thing.app', 2100, uasMdls('com.disk.thing', $old, $added));
    uasApp($apps.'/Suite/Suite Old.app', 1000, uasMdls('com.suite.old', $old, $added));
    uasApp($apps.'/We|ird'."\t".'Name.app', 700, uasMdls('com.weird.name', $old, $added));
    uasApp($apps.'/'.str_repeat('L', 120).'.app', 650, uasMdls('com.long.name', $old, $added));
    uasApp($base.'/home/Applications/Home App.app', 800, uasMdls('com.home.app', $old, $added));
    uasApp($base.'/outside/Linked.app', 9000, uasMdls('com.linked.app', $old, $added));
    symlink($base.'/outside/Linked.app', $apps.'/Linked.app');
    file_put_contents($apps.'/Not an app.txt', 'x');

    return $base;
}

/**
 * @param  array<string, string>  $env
 */
function uasRun(string $base, array $env = [], ?string $bin = null, string $function = 'pcc_apps_report'): ProcessResult
{
    $bin ??= uasBin(['mdls' => UAS_FAKE_MDLS]);

    return Process::path(base_path())->timeout(120)->env(array_merge([
        'PCC_APPS_DIR' => $base.'/Applications',
        'PCC_APPS_HOME' => $base.'/home',
        'PCC_APPS_MIN_KB' => '100',
        'PCC_TODAY' => '2026-11-06',
        'PATH' => $bin.':'.getenv('PATH'),
    ], $env))->run(['bash', '-c', 'source scripts/mac-checkup.sh; '.$function]);
}

/**
 * @return array{header: array<string, string>, apps: list<list<string>>, more: int|null}
 */
function uasParse(string $output): array
{
    $header = [];
    $apps = [];
    $more = null;

    foreach (explode("\n", trim($output)) as $line) {
        if (str_starts_with($line, 'app|')) {
            $apps[] = explode('|', $line);
        } elseif (str_starts_with($line, 'more|')) {
            $more = (int) substr($line, 5);
        } elseif (str_contains($line, ': ')) {
            [$key, $value] = explode(': ', $line, 2);
            $header[$key] = $value;
        }
    }

    return compact('header', 'apps', 'more');
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/pcc-uas-*') ?: [] as $dir) {
        File::deleteDirectory($dir);
    }
});

describe('the Mac apps step on a fake Applications tree', function () {
    it('lists only old, non-Apple apps over the size limit, biggest first, with the right dates', function () {
        $found = uasParse(uasRun(uasTree())->throw()->output());
        $names = array_column($found['apps'], 1);

        expect($found['header'])->toMatchArray(['scan' => 'complete', 'limit' => '45', 'format' => '1', 'scanned' => '2026-11-06'])
            ->and($names)->toBe(['Old Editor', 'Never', 'No Metadata', 'Suite Old', 'Home App', 'Weird Name', str_repeat('L', 60)])
            ->and(array_column($found['apps'], 3))->toBe(['2023-05-01', 'unknown', 'unknown', '2023-05-01', '2023-05-01', '2023-05-01', '2023-05-01'])
            ->and((int) $found['apps'][0][2])->toBeGreaterThanOrEqual(3000)->toBeLessThanOrEqual(3030)
            ->and($found['more'])->toBeNull();
    });

    it('keeps Apple apps, new apps, small apps, utilities, links and non-apps out', function () {
        $output = uasRun(uasTree())->throw()->output();

        foreach (['Safari', 'Recent', 'Edge Case', 'Brand New', 'Tiny', 'Disk Thing', 'Linked', 'Not an app'] as $hidden) {
            expect($output)->not->toContain($hidden);
        }
    });

    it('treats an app last opened exactly 12 months ago as unused and a day later as used', function () {
        $base = uasTree();
        uasApp($base.'/Applications/Edge Case.app', 1900, uasMdls('com.edge.case', '2025-11-06 23:59:00 +0000', '2021-01-01 00:00:00 +0000'));
        uasApp($base.'/Applications/Day After.app', 1900, uasMdls('com.day.after', '2025-11-07 00:00:00 +0000', '2021-01-01 00:00:00 +0000'));
        $output = uasRun($base)->throw()->output();

        expect($output)->toContain('app|Edge Case|')->not->toContain('Day After');
    });

    it('prints no path, bundle id or other metadata, and nothing outside the section format', function () {
        $base = uasTree();
        $output = uasRun($base)->throw()->output();

        expect($output)->not->toContain($base)->not->toContain('/')->not->toContain('com.')->not->toContain("\t")->not->toContain('kMDItem');

        foreach (explode("\n", trim($output)) as $line) {
            expect($line)->toMatch('/^(scan: .+|seconds: \d+|limit: \d+|format: 1|scanned: \d{4}-\d{2}-\d{2}|app\|[^|]+\|\d+\|(\d{4}-\d{2}-\d{2}|unknown)|more\|\d+)$/');
        }
    });

    it('keeps at most 15 rows and says how many more there were', function () {
        $base = uasTree();

        foreach (range(1, 20) as $i) {
            uasApp($base.'/Applications/Bulk '.str_pad((string) $i, 2, '0', STR_PAD_LEFT).'.app', 200 + $i * 10, uasMdls('com.bulk.'.$i, '2022-01-01 00:00:00 +0000', '2021-01-01 00:00:00 +0000'));
        }

        $found = uasParse(uasRun($base)->throw()->output());

        expect($found['apps'])->toHaveCount(15)
            ->and($found['more'])->toBe(12)
            ->and($found['apps'][0][1])->toBe('Old Editor');
    });

    it('lists an app that is installed in both application folders once, with the bigger size', function () {
        $base = uasTree();
        $meta = uasMdls('com.old.editor', '2023-05-01 10:11:12 +0000', '2021-01-01 10:11:12 +0000');
        uasApp($base.'/home/Applications/old editor.app', 500, $meta);
        $found = uasParse(uasRun($base)->throw()->output());

        expect(array_count_values(array_map('strtolower', array_column($found['apps'], 1)))['old editor'])->toBe(1)
            ->and((int) $found['apps'][0][2])->toBeGreaterThanOrEqual(3000);
    });

    it('applies the 100 MB limit by default', function () {
        $found = uasParse(uasRun(uasTree(), ['PCC_APPS_MIN_KB' => null])->throw()->output());

        expect($found['apps'])->toBe([]);
    });

    it('reads a Dutch Mac, where mdls and dates keep their ISO form', function () {
        $found = uasParse(uasRun(uasTree(), ['LC_ALL' => 'nl_NL.UTF-8', 'LANG' => 'nl_NL.UTF-8'])->throw()->output());

        expect($found['apps'][0][1])->toBe('Old Editor');
    });

    it('stops at the time limit, says partial and keeps the apps it had', function () {
        $bin = uasBin(['mdls' => "sleep 1.1\n".UAS_FAKE_MDLS]);
        $started = microtime(true);
        $found = uasParse(uasRun(uasTree(), ['PCC_APPS_CAP' => '2'], $bin)->throw()->output());

        expect($found['header']['scan'])->toBe('partial (time limit of 2 seconds reached)')
            ->and($found['header']['limit'])->toBe('2')
            ->and(microtime(true) - $started)->toBeLessThan(15)
            ->and(count($found['apps']))->toBeLessThan(7);
    });

    it('says partial when mdls or du run out of time on one app, and goes on with the others', function () {
        $mdls = uasBin(['mdls' => 'for last; do :; done'."\n".'case "$last" in *"Old Editor"*) kill -ALRM $$ ;; esac'."\n".UAS_FAKE_MDLS]);
        $found = uasParse(uasRun(uasTree(), [], $mdls)->throw()->output());

        expect($found['header']['scan'])->toStartWith('partial')->and(array_column($found['apps'], 1))->not->toContain('Old Editor')->toContain('Never');

        $du = uasBin(['mdls' => UAS_FAKE_MDLS, 'du' => 'for last; do :; done'."\n".'case "$last" in *"Old Editor"*) kill -ALRM $$ ;; esac'."\n".'exec /usr/bin/du "$@"']);
        $found = uasParse(uasRun(uasTree(), [], $du)->throw()->output());

        expect($found['header']['scan'])->toStartWith('partial')->and(array_column($found['apps'], 1))->not->toContain('Old Editor')->toContain('Never');
    });

    it('reports an empty, complete scan when there are no application folders', function () {
        $found = uasParse(uasRun(sys_get_temp_dir().'/pcc-uas-missing')->throw()->output());

        expect($found['apps'])->toBe([])->and($found['header']['scan'])->toBe('complete');
    });

    it('falls back to the bundle id from the Info plist when Spotlight has none, and skips Apple apps', function () {
        $base = uasTree();
        uasApp($base.'/Applications/Plist Apple.app', 2500, "kMDItemLastUsedDate       = 2020-01-01 00:00:00 +0000\n");
        $bin = uasBin(['mdls' => UAS_FAKE_MDLS, 'defaults' => 'case "$2" in *"Plist Apple"*) echo com.apple.thing ;; esac']);

        expect(uasRun($base, [], $bin)->throw()->output())->not->toContain('Plist Apple');
    });

    it('writes the step into the report and reads only', function () {
        $script = (string) file_get_contents(base_path('scripts/mac-checkup.sh'));

        expect($script)->toContain('section "Apps you may not need" echo "$APPS_REPORT"')
            ->and($script)->toContain('section "Backup freshness" pcc_backups_report');
    });
});

const UAS_TM_ON = ['tmutil' => 'case "$1" in destinationinfo) printf "====\nName          : Backup Disk\nKind          : Local\n" ;; latestbackup) echo "/Volumes/Backup Disk/Backups.backupdb/Mac/2026-11-03-101010" ;; esac'];

/**
 * @param  array<string, string>  $scripts
 * @param  array<string, string>  $env
 */
function uasBackups(array $scripts, array $env = []): string
{
    return uasRun('/nonexistent', $env, uasBin($scripts), 'pcc_backups_report')->throw()->output();
}

describe('the Mac backups step with fake tmutil and defaults', function () {
    it('reports a set up Time Machine with its newest date from the preferences', function () {
        $defaults = 'case "$2" in *TimeMachine) printf "{\n  SnapshotDates = (\n  \"2026-10-01 10:00:00 +0000\",\n  \"2026-11-02 10:00:00 +0000\"\n  );\n}\n" ;; *) echo 1 ;; esac';
        $out = uasBackups([...UAS_TM_ON, 'defaults' => $defaults]);

        expect($out)->toContain("format: 1\nscanned: 2026-11-06\ntimemachine: set up\ntimemachine-last: 2026-11-02\nicloud-folders: Desktop, Documents");
    });

    it('falls back to tmutil latestbackup for the date', function () {
        expect(uasBackups(UAS_TM_ON))->toContain('timemachine-last: 2026-11-03');
    });

    it('says none when Time Machine is set up and has no backups', function (string $answer) {
        $out = uasBackups(['tmutil' => 'case "$1" in destinationinfo) echo "Name : Disk" ;; latestbackup) echo "'.$answer.'" >&2; exit 1 ;; esac']);

        expect($out)->toContain("timemachine: set up\ntimemachine-last: none");
    })->with(['No backups found for host', 'No machine directory found for host']);

    it('says could not check when the date cannot be read for another reason', function () {
        $out = uasBackups(['tmutil' => 'case "$1" in destinationinfo) echo "Name : Disk" ;; latestbackup) echo "Failed to mount backup destination" >&2; exit 1 ;; esac']);

        expect($out)->toContain("timemachine: set up\ntimemachine-last: could not check");
    });

    it('says not set up when there is no destination', function () {
        $out = uasBackups(['tmutil' => 'echo "No destinations configured"', 'defaults' => 'echo 0']);

        expect($out)->toContain("timemachine: not set up\ntimemachine-last: none\nicloud-folders: none");
    });

    it('says could not check when tmutil is missing, fails or says something unexpected', function (array $scripts) {
        expect(uasBackups($scripts))->toContain("timemachine: could not check\ntimemachine-last: could not check");
    })->with([
        'missing' => [[]],
        'odd answer' => [['tmutil' => 'echo "something new"']],
        'error' => [['tmutil' => 'echo "tmutil: error" >&2; exit 1']],
        'timeout' => [['tmutil' => 'kill -ALRM $$']],
    ]);

    it('lists the iCloud folders that are on, one at a time', function (string $desktop, string $documents, string $expected) {
        $defaults = 'case "$3" in FXICloudDriveDesktop) echo '.$desktop.' ;; FXICloudDriveDocuments) echo '.$documents.' ;; esac';

        expect(uasBackups(['tmutil' => 'echo "No destinations configured"', 'defaults' => $defaults]))->toContain("icloud-folders: {$expected}");
    })->with([['1', '1', 'Desktop, Documents'], ['1', '0', 'Desktop'], ['0', '1', 'Documents'], ['0', '0', 'none']]);

    it('says could not check for iCloud when defaults cannot be run', function () {
        expect(uasBackups(['tmutil' => 'echo "No destinations configured"']))->toContain('icloud-folders: could not check');
    });

    it('does not run anything that changes a setting', function () {
        $script = (string) file_get_contents(base_path('scripts/mac-checkup.sh'));
        preg_match('/^pcc_backups_report\(\) \{.*?^\}$/ms', $script, $match);

        expect($match[0])->not->toContain('defaults write')->not->toContain('tmutil start')->not->toContain('tmutil enable');
    });
});
