<?php

declare(strict_types=1);

use App\Services\Reports\ReportDetails;
use App\Services\Reports\ReportFacts;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

function spcFile(string $path, int $kib): void
{
    File::ensureDirectoryExists(dirname($path));
    file_put_contents($path, str_repeat('x', $kib * 1024));
}

function spcTree(): string
{
    $base = sys_get_temp_dir().'/pcc-space-'.bin2hex(random_bytes(4));
    $home = $base.'/home';

    foreach ([
        '.Trash/old.txt' => 100, '.Trash/folder/inner.txt' => 50, '.Trash/.DS_Store' => 4,
        'Library/Caches/app1/x.dat' => 300, 'Library/Caches/app2/y.dat' => 200,
        'Library/Developer/Xcode/DerivedData/proj/o.o' => 64,
        'Library/Application Support/MobileSync/Backup/AAAA1111/f' => 700,
        'Library/Application Support/MobileSync/Backup/BBBB2222/f' => 100,
        'Downloads/a.dmg' => 500, 'Downloads/b.PKG' => 100, 'Downloads/c.iso' => 50,
        'Downloads/d.zip' => 999, 'Downloads/sub/e.dmg' => 300,
        'Movies/film.mov' => 3000, "Documents/Report \u{fc}n\u{ef}.pdf" => 2000,
        'Documents/deep/nested/big.bin' => 1500, 'Documents/we|ird'."\t".'tab.bin' => 1200,
        'Documents/'.str_repeat('n', 120).'.txt' => 900,
        '.hidden/huge.bin' => 9000, 'Library/huge.bin' => 5000, 'Tools/Foo.app/Contents/bin' => 4000,
        'Pictures/Photos.photoslibrary/original.jpg' => 4000, 'Desktop/small.txt' => 10,
    ] as $file => $kib) {
        spcFile($home.'/'.$file, $kib);
    }

    spcFile($base.'/outside/huge.mov', 7000);
    symlink($base.'/outside/huge.mov', $home.'/Documents/linked.mov');
    symlink($base.'/outside/huge.mov', $home.'/Downloads/link.dmg');
    exec('truncate -s 20M '.escapeshellarg($home.'/Documents/cloud-only.bin'));

    return $base;
}

/**
 * @param  array<string, string>  $env
 */
function spcRun(string $base, array $env = [], ?string $path = null): ProcessResult
{
    return Process::path(base_path())->timeout(120)->env(array_filter(array_merge([
        'PCC_SPACE_HOME' => $base.'/home',
        'PCC_SPACE_MIN_KB' => '100',
        'PATH' => $path,
    ], $env), fn (?string $value): bool => $value !== null))->run(['bash', '-c', 'source scripts/mac-checkup.sh; pcc_space_report']);
}

/**
 * @return array<string, list<list<string>>>
 */
function spcRows(string $output): array
{
    $rows = [];

    foreach (explode("\n", trim($output)) as $line) {
        $parts = explode('|', $line);

        if (count($parts) > 1) {
            $rows[array_shift($parts)][] = $parts;
        }
    }

    return $rows;
}

/**
 * @param  list<list<string>>  $rows
 * @return array<string, list<string>>
 */
function spcByKey(array $rows): array
{
    return array_column(array_map(fn (array $row): array => [$row[0], $row], $rows), 1, 0);
}

function spcNear(int $actual, int $expected, int $slack = 24): bool
{
    return $actual >= $expected && $actual <= $expected + $slack;
}

/**
 * @param  array<string, string>  $scripts
 */
function spcBin(array $scripts): string
{
    $dir = sys_get_temp_dir().'/pcc-space-bin-'.bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);

    foreach ($scripts as $name => $body) {
        file_put_contents($dir.'/'.$name, "#!/bin/bash\n".$body."\n");
        chmod($dir.'/'.$name, 0755);
    }

    return $dir;
}

function spcTmutil(int $count): string
{
    $lines = implode('; ', array_map(fn (int $i): string => "echo com.apple.TimeMachine.2024-01-0{$i}-000000.local", range(1, max($count, 1))));

    return spcBin(['tmutil' => $count === 0 ? 'echo "Snapshots for volume group containing disk /:"' : 'echo "Snapshots for volume group containing disk /:"; '.$lines]);
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/pcc-space-*') ?: [] as $dir) {
        File::deleteDirectory($dir);
    }
});

describe('the Mac space step on a tree with known files', function () {
    it('measures the Trash, caches, Xcode folder, backups and installers with their counts', function () {
        $result = spcRun(spcTree());
        $places = spcByKey(spcRows($result->output())['reclaim']);

        expect($result->successful())->toBeTrue()
            ->and(array_keys($places))->toBe(['trash', 'caches', 'installers', 'backups', 'derived'])
            ->and(spcNear((int) $places['trash'][1], 150))->toBeTrue()->and($places['trash'][2])->toBe('2')
            ->and(spcNear((int) $places['caches'][1], 500, 48))->toBeTrue()->and($places['caches'][2])->toBe('2')
            ->and(spcNear((int) $places['installers'][1], 650))->toBeTrue()->and($places['installers'][2])->toBe('3')
            ->and(spcNear((int) $places['backups'][1], 800))->toBeTrue()->and($places['backups'][2])->toBe('2')
            ->and(spcNear((int) $places['derived'][1], 64))->toBeTrue()->and($places['derived'][2])->toBe('1');
    });

    it('only looks in a place that exists, and skips one that is a link', function () {
        $base = spcTree();
        File::deleteDirectory($base.'/home/.Trash');
        symlink($base.'/outside', $base.'/home/.Trash');
        File::deleteDirectory($base.'/home/Library/Caches');
        $places = spcByKey(spcRows(spcRun($base)->output())['reclaim']);

        expect(array_keys($places))->toBe(['installers', 'backups', 'derived']);
    });

    it('counts the Time Machine snapshots and never prints their names', function () {
        $result = spcRun(spcTree(), [], spcTmutil(3).':/usr/bin:/bin');

        expect($result->output())->toContain("\nsnapshots|3\n")->not->toContain('TimeMachine');
    });

    it('prints a snapshot count of zero, and nothing when tmutil is not there', function () {
        expect(spcRun(spcTree(), [], spcTmutil(0).':/usr/bin:/bin')->output())->toContain("\nsnapshots|0\n")
            ->and(spcRun(spcTree())->output())->not->toContain('snapshots');
    });

    it('prints no snapshot count when tmutil fails', function () {
        $bin = spcBin(['tmutil' => 'exit 1']);

        expect(spcRun(spcTree(), [], $bin.':/usr/bin:/bin')->output())->not->toContain('snapshots');
    });

    it('lists the biggest files first with a cleaned name, a kind and only the folder above', function () {
        $files = spcRows(spcRun(spcTree())->output())['file'];

        expect(array_column($files, 0))->toBe([
            'film.mov', "Report \u{fc}n\u{ef}.pdf", 'big.bin', 'weird tab.bin', 'd.zip', str_repeat('n', 80), 'a.dmg', 'e.dmg',
        ])->and(array_column($files, 2))->toBe(['Videos', 'Documents', 'Other', 'Other', 'Archives', 'Documents', 'Apps', 'Apps'])
            ->and(array_column($files, 3))->toBe(['Movies', 'Documents', 'nested', 'Documents', 'Downloads', 'Documents', 'Downloads', 'sub'])
            ->and(spcNear((int) $files[0][1], 3000))->toBeTrue();
    });

    it('leaves out hidden folders, Library, apps, packages, links, other disks and files that only exist in the cloud', function () {
        $output = spcRun(spcTree())->output();

        foreach (['huge', 'Foo', 'original', 'linked', 'cloud-only', 'small'] as $left) {
            expect($output)->not->toContain($left);
        }
    });

    it('keeps at most ten files', function () {
        $base = spcTree();

        foreach (range(1, 15) as $i) {
            spcFile($base."/home/Movies/extra{$i}.mov", 200 + $i);
        }

        expect(spcRows(spcRun($base)->output())['file'])->toHaveCount(10);
    });

    it('states the limit, how long it took, the format and that it finished', function () {
        $lines = explode("\n", trim(spcRun(spcTree())->output()));

        expect($lines[0])->toBe('scan: complete')
            ->and($lines[1])->toMatch('/^seconds: \d+$/')
            ->and($lines[2])->toBe('limit: 60')
            ->and($lines[3])->toBe('format: 1');
    });

    it('prints only the fixed kinds of line and never a path', function () {
        $base = spcTree();
        $output = spcRun($base, [], spcTmutil(2).':/usr/bin:/bin')->output();

        foreach (explode("\n", trim($output)) as $line) {
            expect($line)->toMatch('/^(scan: .+|seconds: \d+|limit: \d+|format: 1|reclaim\|(trash|caches|installers|backups|derived|devicesupport)\|\d+\|\d+|unreadable\|[a-z]+|snapshots\|\d+|file\|[^|\/\\\\\x00-\x1f\x7f]{1,80}\|[1-9]\d*\|(Photos|Videos|Music|Documents|Archives|Apps|Other)\|[^|\/\\\\\x00-\x1f\x7f]{0,60})$/u');
        }

        expect($output)->not->toContain($base)->not->toContain('AAAA1111')->not->toContain('BBBB2222');
    });

    it('shows progress on the screen and keeps it out of the report text', function () {
        $result = spcRun(spcTree());

        expect($result->errorOutput())->toContain('Looking for space')
            ->and($result->output())->not->toContain('Looking');
    });

    it('survives a file name with a line break in it', function () {
        $base = spcTree();
        spcFile($base."/home/Documents/line\nbreak.bin", 800);
        $output = spcRun($base)->output();

        foreach (explode("\n", trim($output)) as $line) {
            expect($line)->toMatch('/^(scan: .+|seconds: \d+|limit: \d+|format: 1|reclaim\|[a-z]+\|\d+\|\d+|snapshots\|\d+|file\|[^|\/\\\\\x00-\x1f\x7f]{1,80}\|[1-9]\d*\|[A-Za-z]+\|[^|\/\\\\\x00-\x1f\x7f]{0,60})$/u');
        }

        expect($output)->not->toContain('file|line|')->not->toContain('file|break.bin|');
    });

    it('names a place it cannot list instead of reporting it as empty', function () {
        $base = spcTree();
        chmod($base.'/home/.Trash', 0);
        $result = spcRun($base);
        chmod($base.'/home/.Trash', 0755);

        expect($result->output())->toContain("\nunreadable|trash\n")->not->toContain('reclaim|trash')->toContain('reclaim|caches')
            ->and($result->errorOutput())->not->toContain('denied');
    })->skip(posix_geteuid() === 0, 'root can list everything');

    it('does not read a glob in the home folder name as a pattern, so Library stays out', function () {
        $base = spcTree();
        rename($base.'/home', $base.'/ho[m]e*');
        $output = spcRun($base, ['PCC_SPACE_HOME' => $base.'/ho[m]e*'])->output();

        expect($output)->toContain('file|film.mov|')->not->toContain('huge')->toContain('reclaim|caches|');
    });

    it('is quiet about folders it cannot read', function () {
        $base = spcTree();
        mkdir($base.'/home/Movies/locked');
        file_put_contents($base.'/home/Movies/locked/x', 'x');
        chmod($base.'/home/Movies/locked', 0);
        $result = spcRun($base);
        chmod($base.'/home/Movies/locked', 0755);

        expect($result->output())->not->toContain('denied')->and($result->errorOutput())->not->toContain('denied')
            ->and($result->output())->toContain('scan: complete');
    });

    it('prints only the header for an empty home, and for a home that does not exist', function () {
        $base = sys_get_temp_dir().'/pcc-space-'.bin2hex(random_bytes(4));
        mkdir($base.'/home', 0777, true);

        expect(array_keys(spcRows(spcRun($base)->output())))->toBe([])
            ->and(trim(spcRun($base.'/nowhere')->output()))->toContain('scan: complete')->not->toContain('reclaim');
    });

    it('does not follow a link that points out of the user folder', function () {
        $base = spcTree();
        File::deleteDirectory($base.'/home/Library/Caches');
        symlink($base.'/outside', $base.'/home/Library/Caches');

        expect(spcRun($base)->output())->not->toContain('reclaim|caches');
    });

    it('reads what the report parser expects', function () {
        $output = spcRun(spcTree(), [], spcTmutil(3).':/usr/bin:/bin')->output();
        $markdown = "# Mac checkup: Test\n\n- Format: 1\n\n## Space you can get back\n\n```\n{$output}\n```\n";
        $card = collect((new ReportDetails(new ReportFacts))->cards($markdown, [], 'laptop'))->firstWhere('id', 'reclaimable');

        expect(array_column($card['bars'][0]['items'], 'label'))->toBe(['Old iPhone and iPad backups', 'Installers in Downloads', 'App caches', 'Trash', 'Xcode build files'])
            ->and($card['bars'][1]['items'][0]['label'])->toBe('film.mov')
            ->and($card['bars'][1]['items'][0]['detail'])->toBe('Videos, in Movies')
            ->and($card['summary'])->toBe('About 1.4 MB can usually be cleared.')
            ->and(implode(' ', $card['notes']))->toContain('3 local Time Machine snapshots')->not->toContain('time limit');
    });
});

describe('the time limit of the space step', function () {
    it('stops a slow disk at the limit and says so', function () {
        $bin = spcBin(['du' => 'exec sleep 30', 'find' => 'exec sleep 30', 'tmutil' => 'exec sleep 30']);
        $start = microtime(true);
        $result = spcRun(spcTree(), ['PCC_SPACE_CAP' => '3'], $bin.':/usr/bin:/bin');
        $took = microtime(true) - $start;

        expect($took)->toBeLessThan(10)
            ->and($result->output())->toContain('scan: partial (time limit of 3 seconds reached)')->toContain('limit: 3')
            ->and($result->errorOutput())->toContain('time limit')->not->toContain('Alarm clock')
            ->and(array_keys(spcRows($result->output())))->toBe([]);
    });

    it('never runs longer than the limit even when every command hangs', function () {
        $bin = spcBin(['du' => 'exec sleep 60', 'find' => 'exec sleep 60', 'tmutil' => 'exec sleep 60']);
        $start = microtime(true);
        spcRun(spcTree(), ['PCC_SPACE_CAP' => '6'], $bin.':/usr/bin:/bin');

        expect(microtime(true) - $start)->toBeLessThan(11);
    });

    it('keeps the places it measured when only the biggest files search runs out of time', function () {
        $bin = spcBin(['find' => 'case " $* " in *" -size "*) exec sleep 30 ;; esac; exec /usr/bin/find "$@"']);
        $result = spcRun(spcTree(), ['PCC_SPACE_CAP' => '5'], $bin.':/usr/bin:/bin');
        $rows = spcRows($result->output());

        expect($result->output())->toContain('scan: partial')
            ->and(array_keys($rows))->toBe(['reclaim'])
            ->and(array_keys(spcByKey($rows['reclaim'])))->toBe(['trash', 'caches', 'installers', 'backups', 'derived']);
    });

    it('keeps the files it found when a place runs out of time', function () {
        $bin = spcBin(['du' => 'for last; do :; done; case "$last" in *Caches) exec sleep 30 ;; esac; exec /usr/bin/du "$@"']);
        $result = spcRun(spcTree(), ['PCC_SPACE_CAP' => '10'], $bin.':/usr/bin:/bin');
        $rows = spcRows($result->output());

        expect($result->output())->toContain('scan: partial')
            ->and(array_keys(spcByKey($rows['reclaim'])))->not->toContain('caches')
            ->and($rows['file'])->not->toBe([]);
    });

    it('falls back to the default limit for a value that is not a positive whole number', function (string $cap) {
        expect(spcRun(spcTree(), ['PCC_SPACE_CAP' => $cap])->output())->toContain('limit: 60');
    })->with(['abc', '0', '-5', '1.5', '']);

    it('defaults to sixty seconds and files of 50 MB', function () {
        $script = (string) file_get_contents(base_path('scripts/mac-checkup.sh'));

        expect($script)->toContain('PCC_SPACE_CAP:-60')->toContain('PCC_SPACE_MIN_KB:-51200');
    });

    it('uses the size limit from the environment only when it is a positive whole number', function (string $min) {
        $base = spcTree();

        expect(spcRows(spcRun($base, ['PCC_SPACE_MIN_KB' => $min])->output())['file'] ?? [])->toBe([]);
    })->with(['abc', '0', '']);
});

describe('what the space step may not do', function () {
    it('only reads: no delete, move, write or network command in the new functions', function () {
        $script = (string) file_get_contents(base_path('scripts/mac-checkup.sh'));
        preg_match('/^pcc_space_count\(\).*?^# Sourcing this file/ms', $script, $match);

        expect($match)->not->toBeEmpty()
            ->and(str_replace('rm -f "$rc_file"', '', $match[0]))->not->toMatch('/\b(rm|mv|cp|curl|tmutil (delete|thinlocalsnapshots)|chmod|touch|tee)\b/');
    });
});
