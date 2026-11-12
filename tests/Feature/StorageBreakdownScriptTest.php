<?php

declare(strict_types=1);

use App\Services\Reports\ReportSections;
use App\Services\Reports\ReportStorage;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\ScriptTemp;

function storageFile(string $path, int $kib): void
{
    File::ensureDirectoryExists(dirname($path));
    file_put_contents($path, str_repeat('x', $kib * 1024));
}

function storageTree(): string
{
    $base = ScriptTemp::dir('pcc-script-storage');
    $home = $base.'/home';

    foreach ([
        'Downloads/photo.JPG' => 1024, 'Downloads/shot.png' => 512, 'Downloads/clip.mp4' => 2048,
        'Downloads/a.zip' => 256, 'Downloads/b.tar.gz' => 136, 'Downloads/my report.pdf' => 64,
        'Downloads/notes.txt' => 4, 'Downloads/setup.exe' => 16, 'Downloads/noext' => 8,
        'Downloads/.hidden' => 4, 'Downloads/weird.a-b' => 4, 'Downloads/long.abcdefghijk' => 4,
        'Downloads/Secret Subfolder/inner.jpg' => 16, 'Documents/doc.docx' => 32,
        'Documents/song.mp3' => 128, 'Pictures/sub/pic.jpeg' => 68, 'Library/cache.bin' => 260,
        'Library/Caches/c.dat' => 200, 'Library/Application Support/s.dat' => 100,
        'Library/Secret Stuff/z.dat' => 300, 'Applications/App.app/bin' => 40,
    ] as $file => $kib) {
        storageFile("{$home}/{$file}", $kib);
    }

    foreach (range(1, 5) as $i) {
        storageFile("{$base}/root/dir{$i}/data", $i * 100);
    }

    storageFile("{$base}/root/Users/u/data", 800);
    storageFile("{$base}/root/System/data", 900);
    storageFile("{$base}/root/with space/data", 52);
    storageFile("{$base}/root/Volumes/huge", 9996);
    storageFile("{$base}/root/file.txt", 8);
    symlink("{$base}/root/dir5", "{$base}/root/link");

    return $base;
}

/**
 * @param  array<string, string>  $env
 */
function runStorage(string $base, array $env = [], ?string $path = null): ProcessResult
{
    return Process::path(base_path())->timeout(120)->env(array_filter(array_merge([
        'PCC_STORAGE_HOME' => $base.'/home',
        'PCC_STORAGE_ROOT' => $base.'/root',
        'PCC_STORAGE_DATA' => $base.'/no-data-volume',
        'PCC_STORAGE_FIRMLINKS' => $base.'/no-firmlinks',
        'PATH' => $path,
    ], $env), fn (?string $value): bool => $value !== null))->run(['bash', '-c', 'source scripts/mac-checkup.sh; pcc_storage_report']);
}

/**
 * @return array<string, list<list<string>>>
 */
function storageRows(string $output): array
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
 * @return array<string, int>
 */
function storageSizes(array $rows): array
{
    $sizes = array_column($rows, 1, 0);
    ksort($sizes);

    return array_map('intval', $sizes);
}

function storageNear(int $actual, int $expected, int $slack = 24): bool
{
    return $actual >= $expected && $actual <= $expected + $slack;
}

function slowDu(string ...$pathEnds): string
{
    return fakeMacBin(['du' => 'for last; do :; done; case "$last" in *'.implode('|*', $pathEnds).') exec sleep 120 ;; esac; exec /usr/bin/du "$@"']);
}

/**
 * Hangs on every du pass over the user folder and the folders in it, but leaves the du that find runs
 * per file alone: find is killed by the clock, and a hung du under it would outlive the script.
 */
function slowHomeDu(): string
{
    return fakeMacBin(['du' => 'for last; do :; done; case " $* " in *" -d "*) case "$last" in */home|*/home/*) exec sleep 120 ;; esac ;; esac; exec /usr/bin/du "$@"']);
}

/**
 * A du whose whole pass is stopped by the clock and prints nothing, as a buffering du does, while
 * measuring one folder with -d 0 works. $pathPattern limits it to the passes over matching paths.
 */
function lostPass(string $pathPattern = '*'): string
{
    return fakeMacBin(['du' => 'for last; do :; done; case " $* " in *" -d 0 "*) exec /usr/bin/du "$@" ;; esac; case "$last" in '.$pathPattern.') kill -ALRM $$ ;; esac; exec /usr/bin/du "$@"']);
}

describe('the Mac step on a tree with known files', function () {
    it('measures the well-known folders in the user folder, leaving out the ones that do not exist', function () {
        $result = runStorage(storageTree());
        $folders = storageSizes(storageRows($result->output())['folder']);

        expect($result->successful())->toBeTrue()
            ->and(array_keys($folders))->toBe(['Applications', 'Documents', 'Downloads', 'Library', 'Pictures'])
            ->and(storageNear($folders['Downloads'], 4096))->toBeTrue()
            ->and(storageNear($folders['Documents'], 160))->toBeTrue()
            ->and(storageNear($folders['Applications'], 40))->toBeTrue()
            ->and(storageNear($folders['Library'], 860, 48))->toBeTrue();
    });

    it('measures the whole user folder as one number', function () {
        $home = array_values(array_filter(storageRows(runStorage(storageTree())->output())['home'], fn (array $row): bool => count($row) === 1));

        expect($home)->toHaveCount(1)
            ->and(storageNear((int) $home[0][0], 5224, 96))->toBeTrue();
    });

    it('measures only the well-known folders inside Library', function () {
        $library = storageSizes(storageRows(runStorage(storageTree())->output())['library']);

        expect(array_keys($library))->toBe(['Application Support', 'Caches'])
            ->and(storageNear($library['Caches'], 200))->toBeTrue()
            ->and(storageNear($library['Application Support'], 100))->toBeTrue();
    });

    it('lists the six biggest folders at the top of the drive from one pass, without volumes, links and loose files', function () {
        $rows = storageRows(runStorage(storageTree())->output());
        $roots = $rows['root'];

        expect(array_column($roots, 0))->toBe(['System', 'Users', 'dir5', 'dir4', 'dir3', 'dir2'])
            ->and(storageNear((int) $roots[0][1], 900))->toBeTrue()
            ->and(storageNear((int) $rows['roots_total'][0][0], 3252, 96))->toBeTrue();
    });

    it('groups the user folder into kinds of file with sizes and counts', function () {
        $types = storageRows(runStorage(storageTree())->output())['type'];

        expect($types)->toBe([
            ['Photos', '1620', '4'],
            ['Videos', '2048', '1'],
            ['Music', '128', '1'],
            ['Documents', '100', '3'],
            ['Archives', '392', '2'],
            ['Apps', '16', '1'],
            ['Other', '920', '9'],
        ]);
    });

    it('counts files without an extension on their own line', function () {
        expect(storageRows(runStorage(storageTree())->output())['noext'])->toBe([['52', '3']]);
    });

    it('keeps the biggest extensions, lower case, and drops odd ones', function () {
        $extensions = storageRows(runStorage(storageTree())->output())['ext'];

        expect($extensions)->toBe([
            ['mp4', '2048', '1'],
            ['jpg', '1040', '2'],
            ['dat', '600', '3'],
            ['png', '512', '1'],
            ['bin', '260', '1'],
            ['zip', '256', '1'],
            ['gz', '136', '1'],
            ['mp3', '128', '1'],
            ['jpeg', '68', '1'],
            ['pdf', '64', '1'],
            ['docx', '32', '1'],
            ['exe', '16', '1'],
            ['txt', '4', '1'],
        ]);
    });

    it('states the limit, how long it took, the format and that it finished', function () {
        $lines = explode("\n", trim(runStorage(storageTree())->output()));

        expect($lines[0])->toBe('scan: complete')
            ->and($lines[1])->toMatch('/^seconds: \d+$/')
            ->and($lines[2])->toBe('limit: 600')
            ->and($lines[3])->toBe('format: 2');
    });

    it('prints only the fixed kinds of line, with folder names in the user folder and never a file name', function () {
        $output = runStorage(storageTree())->output();
        $name = '[^|\/\\\\\x00-\x1f\x7f]{1,60}';

        foreach (explode("\n", trim($output)) as $line) {
            expect($line)->toMatch('/^(scan: .+|seconds: \d+|limit: \d+|format: 2|home\|\d+|home\|'.$name.'\|[1-9]\d*|sub\|'.$name.'\|'.$name.'\|[1-9]\d*|tree\|'.$name.'(\/'.$name.'){0,3}\|[1-9]\d*|roots_total\|\d+|folder\|[A-Za-z]+\|\d+|library\|[A-Za-z ]+\|\d+|root\|[A-Za-z0-9 ._()+-]{1,40}\|\d+|missing\|(root|home|library)\/[A-Za-z ]+|type\|[A-Za-z]+\|\d+\|\d+|noext\|\d+\|\d+|ext\|[a-z0-9]{1,10}\|\d+\|\d+)$/u');
        }

        expect($output)->not->toContain('inner')->not->toContain('photo')->not->toContain('report')->not->toContain('hidden')->not->toContain('.dat')->not->toContain('.jpg')
            ->toContain('sub|Downloads|Secret Subfolder|')->toContain('sub|Library|Secret Stuff|');
    });

    it('reads what the report parser expects', function () {
        $output = runStorage(storageTree())->output();
        $read = (new ReportStorage)->read(ReportSections::parse("## Storage breakdown\n\n```\n{$output}\n```\n"));

        expect($read['partial'])->toBeFalse()
            ->and($read['version'])->toBe(2)
            ->and(array_column($read['folders'], 'name'))->toBe(['Downloads', 'Library (in your user folder)', 'Documents', 'Pictures', 'Applications (in your user folder)'])
            ->and(array_column($read['library'], 'name'))->toBe(['Caches', 'Application Support'])
            ->and(array_column($read['categories'], 'name'))->toBe(['Videos', 'Photos', 'Other', 'Archives', 'Music', 'Documents', 'Apps'])
            ->and($read['roots'])->toHaveCount(6)
            ->and($read['missing'])->toBe([])
            ->and($read['home'])->not->toBeNull()
            ->and($read['rootsTotal'])->not->toBeNull();
    });

    it('does not follow a symbolic link out of the user folder', function () {
        $base = storageTree();
        storageFile("{$base}/outside/big.mp4", 8192);
        symlink("{$base}/outside", "{$base}/home/Documents/linked");
        symlink("{$base}/outside/big.mp4", "{$base}/home/Documents/linked-file.mp4");
        File::deleteDirectory("{$base}/home/Music");
        symlink("{$base}/outside", "{$base}/home/Music");
        $rows = storageRows(runStorage($base)->output());

        expect($rows['type'][1])->toBe(['Videos', '2048', '1'])
            ->and(storageSizes($rows['folder'])['Documents'])->toBeLessThan(1000)
            ->and(array_column($rows['folder'], 0))->not->toContain('Music')
            ->and(array_column($rows['missing'] ?? [], 0))->not->toContain('home/Music');
    });

    it('shows progress on the screen and keeps it out of the report text', function () {
        $result = runStorage(storageTree());

        expect($result->errorOutput())->toContain('Measuring')
            ->and($result->output())->not->toContain('Measuring');
    });

    it('is quiet about folders it cannot read', function () {
        $base = storageTree();
        mkdir("{$base}/home/Downloads/locked");
        file_put_contents("{$base}/home/Downloads/locked/x", 'x');
        chmod("{$base}/home/Downloads/locked", 0);
        $result = runStorage($base);
        chmod("{$base}/home/Downloads/locked", 0755);

        expect($result->output())->not->toContain('denied')->and($result->errorOutput())->not->toContain('denied')
            ->and($result->output())->toContain('scan: complete');
    });

    it('prints a scan with nothing in it for an empty home and an empty drive', function () {
        $base = ScriptTemp::dir('pcc-script-storage');
        mkdir($base.'/home', 0777, true);
        mkdir($base.'/root');
        $rows = storageRows(runStorage($base)->output());

        expect(array_keys($rows))->toBe(['roots_total', 'home'])
            ->and($rows['roots_total'])->toBe([['0']]);
    });

    it('lists what exists on the drive but could not be read as not measured', function () {
        $base = storageTree();
        $bin = fakeMacBin(['du' => 'exit 1']);
        $rows = storageRows(runStorage($base, [], $bin.':/usr/bin:/bin')->output());

        expect(array_column($rows['missing'], 0))->toBe(['root/System', 'root/Users', 'home/Downloads', 'home/Documents', 'home/Pictures', 'home/Library', 'home/Applications', 'library/Application Support', 'library/Caches']);
    });
});

describe('a Mac with a separate data volume (macOS 10.15 and later)', function () {
    function dataTree(): string
    {
        $base = ScriptTemp::dir('pcc-script-storage');
        storageFile("{$base}/root/System/data", 900);
        storageFile("{$base}/root/Users/placeholder", 4);
        storageFile("{$base}/root/Library/placeholder", 4);
        storageFile("{$base}/root/usr/bin/tool", 100);
        storageFile("{$base}/data/Users/u/big", 5000);
        storageFile("{$base}/data/Library/lib", 300);
        File::ensureDirectoryExists("{$base}/home");
        file_put_contents("{$base}/firmlinks", "/Users\tUsers\n/Library\tLibrary\n/usr/local\tusr/local\n");

        return $base;
    }

    function runData(string $base, array $env = [], ?string $path = null): ProcessResult
    {
        return runStorage($base, array_merge(['PCC_STORAGE_DATA' => $base.'/data', 'PCC_STORAGE_FIRMLINKS' => $base.'/firmlinks'], $env), $path);
    }

    it('takes the folders that are firmlinked from the data volume and the rest from the system volume', function () {
        $roots = storageSizes(storageRows(runData(dataTree())->output())['root']);

        expect(array_keys($roots))->toBe(['Library', 'System', 'Users', 'usr'])
            ->and(storageNear($roots['Users'], 5000))->toBeTrue()
            ->and(storageNear($roots['Library'], 300))->toBeTrue()
            ->and(storageNear($roots['System'], 900))->toBeTrue()
            ->and(storageNear($roots['usr'], 100))->toBeTrue();
    });

    it('takes the bigger number when it cannot tell which folders are firmlinked', function () {
        $base = dataTree();
        $roots = storageSizes(storageRows(runData($base, ['PCC_STORAGE_FIRMLINKS' => $base.'/none'])->output())['root']);

        expect(storageNear($roots['Users'], 5000))->toBeTrue();
    });

    it('measures a firmlinked folder on its own when the pass over the data volume is stopped', function () {
        $base = dataTree();
        $result = runData($base, [], lostPass('*/data').':/usr/bin:/bin');
        $roots = storageSizes(storageRows($result->output())['root']);

        expect($result->output())->toContain('scan: partial')
            ->and(array_keys($roots))->toBe(['Library', 'System', 'Users', 'usr'])
            ->and(storageNear($roots['Users'], 5000))->toBeTrue()
            ->and(storageNear($roots['Library'], 300))->toBeTrue();
    });

    it('lists a firmlinked folder as not measured instead of trusting the empty one on the system volume', function () {
        $base = dataTree();
        $result = runData($base, ['PCC_STORAGE_CAP' => '10'], slowDu('/data', '/Users').':/usr/bin:/bin');
        $rows = storageRows($result->output());
        expect($result->output())->toContain('scan: partial')
            ->and(array_column($rows['root'], 0))->toContain('System')->toContain('Library')->not->toContain('Users')
            ->and(array_column($rows['missing'], 0))->toBe(['root/Users']);
    });
});

describe('the time limit', function () {
    it('stops a slow disk at the limit and says so', function () {
        $bin = fakeMacBin(['du' => 'exec sleep 120', 'find' => 'exec sleep 120']);
        $start = microtime(true);
        $result = runStorage(storageTree(), ['PCC_STORAGE_CAP' => '3'], $bin.':/usr/bin:/bin');
        $took = microtime(true) - $start;
        $rows = storageRows($result->output());

        expect($took)->toBeLessThan(3 + 20)
            ->and($result->output())->toContain('scan: partial (time limit of 3 seconds reached)')
            ->and($result->output())->toContain('limit: 3')
            ->and($result->errorOutput())->toContain('time limit')->not->toContain('Alarm clock')
            ->and(array_keys($rows))->toBe(['roots_total', 'missing'])
            ->and($rows['roots_total'])->toBe([['0']])
            ->and(array_column($rows['missing'], 0))->toContain('root/Users')->toContain('home/Downloads');
    });

    it('never runs longer than the limit even when every command hangs', function () {
        $bin = fakeMacBin(['du' => 'exec sleep 120', 'find' => 'exec sleep 120']);
        $start = microtime(true);
        runStorage(storageTree(), ['PCC_STORAGE_CAP' => '6'], $bin.':/usr/bin:/bin');

        expect(microtime(true) - $start)->toBeLessThan(6 + 20);
    });

    it('keeps what it measured before the time ran out and marks the scan partial', function () {
        $bin = fakeMacBin(['find' => 'exec sleep 120']);
        $result = runStorage(storageTree(), ['PCC_STORAGE_CAP' => '6'], $bin.':/usr/bin:/bin');
        $rows = storageRows($result->output());

        expect($result->output())->toContain('scan: partial')
            ->and($rows['folder'])->not->toBe([])
            ->and($rows['root'])->not->toBe([])
            ->and($rows)->not->toHaveKey('type');
    });

    it('lists the user folder as not measured when only that pass runs out of time', function () {
        $base = storageTree();
        $result = runStorage($base, ['PCC_STORAGE_CAP' => '6'], slowHomeDu().':/usr/bin:/bin');
        $rows = storageRows($result->output());

        expect($result->output())->toContain('scan: partial')
            ->and($rows['root'])->not->toBe([])
            ->and($rows)->not->toHaveKey('home')->not->toHaveKey('folder')
            ->and(array_column($rows['missing'], 0))->toContain('home/Downloads')->toContain('home/Library')->toContain('library/Caches');
    });

    it('measures the well-known folders one at a time when a stopped pass printed nothing', function () {
        $result = runStorage(storageTree(), [], lostPass().':/usr/bin:/bin');
        $rows = storageRows($result->output());

        expect($result->output())->toContain('scan: partial')
            ->and(array_keys(storageSizes($rows['root'])))->toBe(['System', 'Users'])
            ->and(storageNear((int) $rows['roots_total'][0][0], 1700, 48))->toBeTrue()
            ->and(array_keys(storageSizes($rows['folder'])))->toBe(['Applications', 'Documents', 'Downloads', 'Library', 'Pictures'])
            ->and(array_keys(storageSizes($rows['library'])))->toBe(['Application Support', 'Caches'])
            ->and(storageNear((int) $rows['home'][0][0], 5224, 96))->toBeTrue()
            ->and($rows)->not->toHaveKey('missing');
    });

    it('takes the whole list from the single pass when it finishes, not just the well-known folders', function () {
        $bin = fakeMacBin(['du' => 'case " $* " in *" -d 0 "*) exit 3 ;; esac; exec /usr/bin/du "$@"']);
        $rows = storageRows(runStorage(storageTree(), [], $bin.':/usr/bin:/bin')->output());

        expect(array_keys(storageSizes($rows['root'])))->toBe(['System', 'Users', 'dir2', 'dir3', 'dir4', 'dir5'])
            ->and((int) $rows['roots_total'][0][0])->toBeGreaterThan(3000);
    });

    it('falls back to the default limit for a value that is not a positive whole number', function (string $cap) {
        expect(runStorage(storageTree(), ['PCC_STORAGE_CAP' => $cap])->output())->toContain('limit: 600');
    })->with(['abc', '0', '-5', '1.5', '']);

    it('defaults to six hundred seconds', function () {
        expect((string) file_get_contents(base_path('scripts/mac-checkup.sh')))->toContain('PCC_STORAGE_CAP:-600');
    });
});

function namedHomeTree(): string
{
    $base = ScriptTemp::dir('pcc-script-storage');
    $home = $base.'/home';

    foreach ([
        'Library/Application Support/big.dat' => 3000, 'Library/Caches/c.dat' => 600, 'Library/Logs/l.dat' => 100,
        'Library/Containers/c.dat' => 90, 'Library/Mail/m.dat' => 80, 'Library/Safari/s.dat' => 70, 'Library/Fonts/f.dat' => 60,
        'Projects/app/node_modules/x.js' => 1500, 'Projects/app/src/a.js' => 20, 'Projects/site/index.html' => 300,
        '.cache/pip/w.whl' => 700, 'Downloads/old/o.bin' => 400, 'Documents/d.pdf' => 300, 'Movies/m.mov' => 200,
        'Music/s.mp3' => 150, 'Pictures/p.jpg' => 120, 'Desktop/x.txt' => 50, '.Trash/old.bin' => 20,
    ] as $file => $kib) {
        storageFile("{$home}/{$file}", $kib);
    }

    storageFile("{$base}/root/Users/u/data", 100);

    return $base;
}

/**
 * @param  array<string, list<list<string>>>  $rows
 * @return array<string, int>
 */
function namedHome(array $rows): array
{
    return array_map('intval', array_column(array_filter($rows['home'] ?? [], fn (array $row): bool => count($row) === 2), 1, 0));
}

/**
 * @param  array<string, list<list<string>>>  $rows
 * @return array<string, int>
 */
function namedSub(array $rows, string $parent): array
{
    return array_map('intval', array_column(array_map(fn (array $row): array => [$row[1], $row[2]], array_filter($rows['sub'] ?? [], fn (array $row): bool => $row[0] === $parent)), 1, 0));
}

/**
 * @param  array<string, int|string>  $folders  directory name => size in KiB
 */
function oddNamesTree(array $folders): string
{
    $base = ScriptTemp::dir('pcc-script-storage');

    foreach ($folders as $name => $kib) {
        storageFile("{$base}/home/{$name}/file.dat", (int) $kib);
    }

    File::ensureDirectoryExists("{$base}/root");

    return $base;
}

function lostDeepPass(): string
{
    return fakeMacBin(['du' => 'case " $* " in *" -d 4 "*) kill -ALRM $$ ;; esac; exec /usr/bin/du "$@"']);
}

describe('the biggest folders in the user folder (Mac)', function () {
    it('lists the eight biggest folders by name, hidden ones and Library included, with their sizes', function () {
        $rows = storageRows(runStorage(namedHomeTree())->output());
        $named = namedHome($rows);

        expect(array_keys($named))->toBe(['Library', 'Projects', '.cache', 'Downloads', 'Documents', 'Movies', 'Music', 'Pictures'])
            ->and(storageNear($named['Library'], 4000, 64))->toBeTrue()
            ->and(storageNear($named['Projects'], 1820, 32))->toBeTrue()
            ->and(storageNear($named['.cache'], 700, 16))->toBeTrue()
            ->and(storageNear($named['Pictures'], 120, 16))->toBeTrue();
    });

    it('lists the folders inside only the two biggest, five each, biggest first', function () {
        $rows = storageRows(runStorage(namedHomeTree())->output());
        $library = namedSub($rows, 'Library');
        $projects = namedSub($rows, 'Projects');

        expect(array_keys($library))->toBe(['Application Support', 'Caches', 'Logs', 'Containers', 'Mail'])
            ->and(storageNear($library['Application Support'], 3000, 16))->toBeTrue()
            ->and(array_keys($projects))->toBe(['app', 'site'])
            ->and(storageNear($projects['app'], 1520, 24))->toBeTrue()
            ->and(array_values(array_unique(array_column($rows['sub'], 0))))->toBe(['Library', 'Projects']);
    });

    it('prints the user folder total before the names, and the old kinds of line unchanged', function () {
        $rows = storageRows(runStorage(namedHomeTree())->output());

        expect($rows['home'][0])->toHaveCount(1)
            ->and(storageNear((int) $rows['home'][0][0], 7760, 200))->toBeTrue()
            ->and(array_column($rows['library'], 0))->toBe(['Application Support', 'Caches', 'Containers', 'Mail', 'Logs', 'Safari'])
            ->and($rows)->toHaveKey('type');
    });

    it('is read by the report parser into the same names', function () {
        $output = runStorage(namedHomeTree())->output();
        $read = (new ReportStorage)->read(ReportSections::parse("## Storage breakdown\n\n```\n{$output}\n```\n"));

        expect(array_column($read['homeFolders'], 'name'))->toBe(['Library', 'Projects', '.cache', 'Downloads', 'Documents', 'Movies', 'Music', 'Pictures'])
            ->and(array_column($read['homeInside'], 'parent'))->toBe(['Library', 'Projects'])
            ->and(array_column($read['homeInside'][0]['items'], 'name'))->toBe(['Application Support', 'Caches', 'Logs', 'Containers', 'Mail']);
    });

    it('does not follow or list a symbolic link', function () {
        $base = namedHomeTree();
        storageFile("{$base}/outside/big.dat", 9000);
        symlink("{$base}/outside", "{$base}/home/linked");
        symlink("{$base}/outside", "{$base}/home/Projects/linked-inside");
        $rows = storageRows(runStorage($base)->output());

        expect(array_keys(namedHome($rows)))->not->toContain('linked')
            ->and(array_keys(namedSub($rows, 'Projects')))->not->toContain('linked-inside')
            ->and(namedHome($rows)['Projects'])->toBeLessThan(2000);
    });

    it('is quiet about a folder it cannot read and still names it', function () {
        $base = namedHomeTree();
        mkdir("{$base}/home/Locked");
        chmod("{$base}/home/Locked", 0);
        $result = runStorage($base);
        chmod("{$base}/home/Locked", 0755);

        expect($result->output())->toContain('scan: complete')->not->toContain('denied')
            ->and($result->errorOutput())->not->toContain('denied');
    });

    it('cleans names of pipes, separators, tabs, control characters and runs of spaces, and cuts them at sixty bytes', function () {
        $base = oddNamesTree([
            'pipe|name' => 190, "tab\tname" => 180, "ctl\x01\x07x" => 170, '-dash' => 160, '"quote" \'x\'' => 150,
            "Fotos de Mu\u{00f1}oz \u{1F334}" => 140, str_repeat('L', 200) => 130, '  spaced    out  ' => 120,
        ]);
        $named = namedHome(storageRows(runStorage($base)->output()));

        expect(array_keys($named))->toBe(['pipename', 'tab name', 'ctlx', '-dash', '"quote" \'x\'', "Fotos de Mu\u{00f1}oz \u{1F334}", str_repeat('L', 60), 'spaced out']);
    });

    it('never cuts a multi byte character in half', function () {
        $base = oddNamesTree(['a'.str_repeat("\u{00e9}", 40) => 190, 'ab'.str_repeat("\u{1F600}", 20) => 150]);
        $names = array_keys(namedHome(storageRows(runStorage($base)->output())));

        expect($names)->toBe(['a'.str_repeat("\u{00e9}", 29), 'ab'.str_repeat("\u{1F600}", 14)])
            ->and(mb_check_encoding(implode('', $names), 'UTF-8'))->toBeTrue();
    });

    it('drops names that are empty once cleaned and keeps the bigger of two that clean to the same name', function () {
        $base = oddNamesTree([
            '|||' => 190, '   ' => 180, 'dup|' => 150, 'dup' => 160, 'back\\slash' => 140, 'C:\\Users\\x' => 130, '...' => 120, 'a..b' => 110,
        ]);
        $named = namedHome(storageRows(runStorage($base)->output()));

        expect(array_keys($named))->toBe(['dup', 'backslash', 'C:Usersx', '...', 'a..b'])
            ->and(storageNear($named['dup'], 160, 8))->toBeTrue();
    });

    it('never prints a malformed line for a folder name with a newline or a leading dot', function () {
        $base = oddNamesTree(["new\nline" => 190, '.hidden' => 180, '..x' => 170]);
        $output = runStorage($base)->output();

        foreach (explode("\n", trim($output)) as $line) {
            expect($line)->toMatch('/^(scan: .+|seconds: \d+|limit: \d+|format: 2|home\|\d+|home\|[^|\/\\\\\x00-\x1f\x7f]{1,60}\|[1-9]\d*|sub\|[^|\/\\\\\x00-\x1f\x7f]{1,60}\|[^|\/\\\\\x00-\x1f\x7f]{1,60}\|[1-9]\d*|tree\|[^|\/\\\\\x00-\x1f\x7f]{1,60}\|[1-9]\d*|roots_total\|\d+|folder\|[A-Za-z]+\|\d+|library\|[A-Za-z ]+\|\d+|missing\|.+|type\|[A-Za-z]+\|\d+\|\d+|noext\|\d+\|\d+|ext\|[a-z0-9]{1,10}\|\d+\|\d+)$/u');
        }

        expect(array_keys(namedHome(storageRows($output))))->toContain('.hidden')->toContain('..x');
    });

    it('skips a folder inside a folder whose name holds the character awk joins keys with', function () {
        $base = namedHomeTree();
        storageFile("{$base}/home/Projects/we\x1Cird/f", 50);
        $rows = storageRows(runStorage($base)->output());

        expect(array_keys(namedSub($rows, 'Projects')))->toBe(['app', 'site']);
    });

    it('keeps the cleaned names out of every path: no full path is printed', function () {
        $output = runStorage(namedHomeTree())->output();

        expect($output)->not->toContain('/home')->not->toContain('/tmp')->not->toContain(sys_get_temp_dir());
    });

    it('does not list a folder with no size', function () {
        $base = namedHomeTree();
        File::ensureDirectoryExists("{$base}/home/Empty");

        expect(array_keys(namedHome(storageRows(runStorage($base)->output()))))->not->toContain('Empty');
    });

    it('prints no names for an empty user folder', function () {
        $base = ScriptTemp::dir('pcc-script-storage');
        mkdir($base.'/home', 0777, true);
        mkdir($base.'/root');
        $rows = storageRows(runStorage($base)->output());

        expect(namedHome($rows))->toBe([])->and($rows)->not->toHaveKey('sub');
    });

    it('measures the folders one at a time when the pass over the user folder prints nothing', function () {
        $result = runStorage(namedHomeTree(), [], lostDeepPass().':/usr/bin:/bin');
        $rows = storageRows($result->output());
        $named = namedHome($rows);

        expect($result->output())->toContain('scan: partial')
            ->and(array_keys($named))->toBe(['Library', 'Projects', '.cache', 'Downloads', 'Documents', 'Movies', 'Music', 'Pictures'])
            ->and(storageNear($named['Library'], 4000, 64))->toBeTrue()
            ->and(array_keys(namedSub($rows, 'Library')))->toBe(['Application Support', 'Caches', 'Logs', 'Containers', 'Mail'])
            ->and(array_keys(namedSub($rows, 'Projects')))->toBe(['app', 'site'])
            ->and(array_column(array_filter($rows['home'], fn (array $row): bool => count($row) === 1), 0))->not->toBe([]);
    });

    it('shows what it measured and stops at the limit when the user folder is slow', function () {
        $bin = fakeMacBin(['du' => 'case "$*" in *home*) exec sleep 120 ;; esac; exec /usr/bin/du "$@"', 'find' => 'exec sleep 120']);
        $start = microtime(true);
        $result = runStorage(namedHomeTree(), ['PCC_STORAGE_CAP' => '6'], $bin.':/usr/bin:/bin');
        $rows = storageRows($result->output());

        expect(microtime(true) - $start)->toBeLessThan(6 + 20)
            ->and($result->output())->toContain('scan: partial (time limit of 6 seconds reached)')
            ->and(namedHome($rows))->toBe([])
            ->and($rows['root'])->not->toBe([])
            ->and(array_column($rows['missing'], 0))->toContain('home/Downloads');
    });

    it('measures the big folders it can reach and leaves out the one that hangs', function () {
        $bin = fakeMacBin(['du' => 'for last; do :; done; case "$last" in *home) kill -ALRM $$ ;; *home/Library) exec sleep 120 ;; esac; exec /usr/bin/du "$@"']);
        $result = runStorage(namedHomeTree(), ['PCC_STORAGE_CAP' => '30'], $bin.':/usr/bin:/bin');
        $named = namedHome(storageRows($result->output()));

        expect($result->output())->toContain('scan: partial')
            ->and($named)->toHaveKey('Projects')->toHaveKey('Downloads')->not->toHaveKey('Library');
    });

    it('keeps the limit at a hundred and fifty seconds and gives the user folder a bigger share of it', function () {
        $script = (string) file_get_contents(base_path('scripts/mac-checkup.sh'));

        expect($script)->toContain('PCC_STORAGE_CAP:-600')->and($script)->toContain('pcc_storage_stop "$start" "$cap" 40');
    });
});

const TREE_GIB = 1048576;

/**
 * The tree| lines for du lines made up from $folders (path inside the user folder => KB).
 *
 * @param  array<string, int>  $folders
 * @return list<string>
 */
function treeLines(array $folders): array
{
    $input = '';

    foreach ($folders as $path => $kb) {
        $input .= "{$kb}\t/Users/me/{$path}\n";
    }

    $output = Process::path(base_path())->input($input)->run(['bash', '-c', 'source scripts/mac-checkup.sh; pcc_storage_homerows /Users/me'])->output();

    return array_values(array_filter(explode("\n", trim($output)), fn (string $line): bool => str_starts_with($line, 'tree|')));
}

/**
 * A du that reports every size 1024 times bigger, so a few MiB on disk act as GiB. With $lose the
 * pass four levels deep is stopped by the clock and prints nothing.
 */
function scaledDu(bool $lose = false): string
{
    $scale = <<<'SH'
        /usr/bin/du "$@" | awk -F '\t' 'BEGIN { OFS = "\t" } { $1 = $1 * 1024; print }'
        SH;

    return fakeMacBin(['du' => ($lose ? 'case " $* " in *" -d 4 "*) kill -ALRM $$ ;; esac; ' : '').$scale]);
}

function deepHomeTree(): string
{
    $base = namedHomeTree();
    storageFile("{$base}/home/Projects/app/node_modules/pkg/deep/y.js", 200);

    return $base;
}

/**
 * @param  list<string>  $lines
 * @return list<string>
 */
function treePaths(array $lines, string $prefix): array
{
    $paths = array_map(fn (string $line): string => explode('|', $line)[1], $lines);

    return array_values(array_filter($paths, fn (string $path): bool => str_starts_with($path, $prefix)));
}

describe('the folder tree in the user folder (Mac)', function () {
    it('starts with the same eight folders as the home names, whatever their size, and opens none under 1 GiB', function () {
        $lines = treeLines([
            'A' => 900, 'A/inside' => 800, 'B' => 800, 'C' => 700, 'D' => 600, 'E' => 500, 'F' => 400, 'G' => 300, 'H' => 200, 'I' => 100,
        ]);

        expect($lines)->toBe(['tree|A|900', 'tree|B|800', 'tree|C|700', 'tree|D|600', 'tree|E|500', 'tree|F|400', 'tree|G|300', 'tree|H|200']);
    });

    it('opens a folder of 1 GiB or more to its six biggest folders of 100 MiB or more, biggest first and then by name', function () {
        $lines = treeLines([
            'Big' => 2 * TREE_GIB, 'Big/c1' => 300000, 'Big/c2' => 200000, 'Big/b' => 200000, 'Big/c3' => 150000, 'Big/c4' => 120000,
            'Big/c5' => 110000, 'Big/c6' => 102400, 'Big/small' => 102399,
            'Exact' => TREE_GIB, 'Exact/y' => 102400, 'Exact/z' => 102399,
            'Under' => TREE_GIB - 1, 'Under/x' => 500000,
        ]);

        expect($lines)->toBe([
            'tree|Big|2097152', 'tree|Big/c1|300000', 'tree|Big/b|200000', 'tree|Big/c2|200000', 'tree|Big/c3|150000', 'tree|Big/c4|120000', 'tree|Big/c5|110000',
            'tree|Exact|1048576', 'tree|Exact/y|102400',
            'tree|Under|1048575',
        ]);
    });

    it('goes at most four levels deep and puts every folder right before the folders inside it', function () {
        $lines = treeLines([
            'A' => 5 * TREE_GIB, 'A/B' => 4 * TREE_GIB, 'A/B/C' => 3 * TREE_GIB, 'A/B/C/D' => 2 * TREE_GIB, 'A/B/C/D/E' => TREE_GIB + 1,
            'A/B/C/D/E/F' => TREE_GIB, 'A/B/C/D2' => 102399, 'A/B/C2' => 500000, 'A/B/C2/x' => 300000, 'A/B2' => 200000, 'Z' => 10,
        ]);

        expect($lines)->toBe([
            'tree|A|5242880', 'tree|A/B|4194304', 'tree|A/B/C|3145728', 'tree|A/B/C/D|2097152', 'tree|A/B/C2|500000', 'tree|A/B2|200000', 'tree|Z|10',
        ]);
    });

    it('keeps the bigger of two folders that clean to the same name and fills the six from the rest', function () {
        $lines = treeLines([
            'P' => 3 * TREE_GIB, 'P|' => 50, 'P/dup|' => 400000, 'P/dup' => 500000, "P/a\tb" => 300000, 'P/a b' => 450000,
            'P/k1' => 200000, 'P/k2' => 190000, 'P/k3' => 180000, 'P/k4' => 170000, 'P/k5' => 160000,
            'Q' => 2 * TREE_GIB, 'Q/dup' => 200000, 'Q/'.str_repeat('L', 80) => 150000,
        ]);

        expect($lines)->toBe([
            'tree|P|3145728', 'tree|P/dup|500000', 'tree|P/a b|450000', 'tree|P/k1|200000', 'tree|P/k2|190000', 'tree|P/k3|180000', 'tree|P/k4|170000',
            'tree|Q|2097152', 'tree|Q/dup|200000', 'tree|Q/'.str_repeat('L', 60).'|150000',
        ]);
    });

    it('counts a folder listed twice once, with the bigger number', function () {
        $output = Process::path(base_path())->input("100\t/Users/me/A\n".TREE_GIB."\t/Users/me/A\n200000\t/Users/me/A/x\n150000\t/Users/me/A/x\n")
            ->run(['bash', '-c', 'source scripts/mac-checkup.sh; pcc_storage_homerows /Users/me'])->output();

        expect(array_values(array_filter(explode("\n", trim($output)), fn (string $line): bool => str_starts_with($line, 'tree|'))))
            ->toBe(['tree|A|1048576', 'tree|A/x|200000']);
    });

    it('prints the tree after the sub lines, four levels deep from one du pass, with no deeper folder', function () {
        $output = runStorage(deepHomeTree(), [], scaledDu().':/usr/bin:/bin')->output();
        $kinds = array_map(fn (string $line): string => explode('|', $line)[0], explode("\n", trim($output)));
        $tree = array_values(array_filter(explode("\n", trim($output)), fn (string $line): bool => str_starts_with($line, 'tree|')));

        expect(treePaths($tree, 'Projects'))->toBe(['Projects', 'Projects/app', 'Projects/app/node_modules', 'Projects/app/node_modules/pkg', 'Projects/site'])
            ->and(array_search('tree', $kinds, true))->toBeGreaterThan(array_search('sub', array_reverse($kinds, true), true))
            ->and($output)->not->toContain('deep')->not->toContain('y.js');
    });

    it('still lists three levels inside the two biggest folders when the pass over the user folder is lost', function () {
        $result = runStorage(deepHomeTree(), [], scaledDu(true).':/usr/bin:/bin');
        $tree = array_values(array_filter(explode("\n", trim($result->output())), fn (string $line): bool => str_starts_with($line, 'tree|')));

        expect($result->output())->toContain('scan: partial')
            ->and(treePaths($tree, 'Projects'))->toBe(['Projects', 'Projects/app', 'Projects/app/node_modules', 'Projects/app/node_modules/pkg', 'Projects/site']);
    });
});

describe('what the scripts contain', function () {
    $functions = function (string $file, string $pattern): string {
        preg_match_all($pattern, (string) file_get_contents(base_path($file)), $found);

        return implode("\n", $found[0]);
    };

    it('runs the Mac step as its own section with a progress line, after the disk usage', function () {
        $script = (string) file_get_contents(base_path('scripts/mac-checkup.sh'));

        expect($script)->toContain('section "Storage breakdown" echo "$STORAGE_BREAKDOWN"')
            ->and(strpos($script, 'section "Storage breakdown"'))->toBeGreaterThan(strpos($script, 'section "Disk usage"'))
            ->and($script)->toContain('Measuring storage');
    });

    it('runs the Windows step as its own section, after the volumes', function () {
        $script = (string) file_get_contents(base_path('scripts/windows-checkup.ps1'));

        expect($script)->toContain("Add-Section 'Storage breakdown'")
            ->and(strpos($script, "Add-Section 'Storage breakdown'"))->toBeGreaterThan(strpos($script, "Add-Section 'Storage (volumes and free space)'"))
            ->and($script)->toContain('Measuring storage');
    });

    it('never touches the network, deletes, writes or schedules anything from the storage step', function () use ($functions) {
        $mac = $functions('scripts/mac-checkup.sh', '/^pcc_storage_\w+\(\) \{.*?^\}/ms');
        $windows = $functions('scripts/windows-checkup.ps1', '/^function (?:Get|Measure|Test)-Storage\w+.*?^\}/ms');

        expect($mac)->not->toBe('')->and($windows)->not->toBe('');
        expect($mac)->not->toMatch('/\b(curl|wget|nc|ssh|scp|mv|cp|chmod|chown|crontab|launchctl)\b|>\s*\/dev\/tcp|\$\{?PCC_TOKEN/');
        expect($windows)->not->toMatch('/Invoke-WebRequest|Invoke-RestMethod|WebClient|BitsTransfer|Send-|Remove-Item|Set-Content|Out-File|Add-Content|New-Item|Register-ScheduledTask|schtasks|Start-Process|\$Token|\$ScanCode/i');
    });

    it('keeps the Mac step to what bash 3.2 and macOS 10.14 have', function () use ($functions) {
        $mac = $functions('scripts/mac-checkup.sh', '/^pcc_storage_\w+\(\) \{.*?^\}/ms');

        expect($mac)->not->toMatch('/declare -[Aan]|local -[An]|mapfile|readarray|\$\{[^}]*(,,|\^\^)[^}]*\}|&>>|\|&|find [^\n]*-(printf|maxdepth)|stat -c|sort -V|date -d|readlink -f|xargs -r|grep -[a-zA-Z]*P|sed -r|timeout |gawk|--max-depth|du -[a-z]*b\b|-print0|<<<|\[\[|\{[0-9]+,[0-9]*\}|asort|gensub/')
            ->and($mac)->toContain('du -x -k -d "$depth"');
    });

    it('is valid bash', function () {
        $result = Process::path(base_path())->run(['bash', '-n', 'scripts/mac-checkup.sh']);

        expect($result->successful())->toBeTrue()->and($result->errorOutput())->toBe('');
    });

    it('keeps the Windows step to Windows PowerShell 5.1 and plain ASCII', function () use ($functions) {
        $script = (string) file_get_contents(base_path('scripts/windows-checkup.ps1'));
        $windows = $functions('scripts/windows-checkup.ps1', '/^function (?:Get|Measure|Test)-Storage\w+.*?^\}/ms');

        expect($script)->toMatch('/\A[\x00-\x7F]*\z/')
            ->and($windows)->not->toMatch('/\?\?|\?\.|&&|\|\||-Parallel|Get-ChildItem|\[System\.IO\.Enumeration|-AsHashtable|\$IsWindows/')
            ->and($windows)->toContain('ReparsePoint')
            ->and($windows)->toContain('$Stats')
            ->and($windows)->toContain('Stopwatch');
    });

    it('uses a hundred and fifty second limit in both scripts', function () {
        expect((string) file_get_contents(base_path('scripts/windows-checkup.ps1')))->toContain('PCC_STORAGE_CAP')->toContain('$StorageCapSeconds = 150')
            ->and((string) file_get_contents(base_path('scripts/mac-checkup.sh')))->toContain('limit: ');
    });

    it('tells the person about the longer limit before the scan starts', function () {
        expect((string) file_get_contents(base_path('scripts/mac-checkup.sh')))->toContain('stopping after $cap seconds at the latest')
            ->and((string) file_get_contents(base_path('scripts/windows-checkup.ps1')))->toContain('stopping after $StorageCapSeconds seconds at the latest');
    });
});

describe('the Windows step under PowerShell', function () {
    $pwsh = trim((string) shell_exec('command -v pwsh 2>/dev/null'));

    function runWindowsStep(string $base, array $env): ProcessResult
    {
        $script = (string) file_get_contents(base_path('scripts/windows-checkup.ps1'));
        preg_match_all('/^function (?:Get|Measure|Test)-Storage\w+.*?^\}/ms', $script, $found);
        file_put_contents($base.'/step.ps1', implode("\n", $found[0])."\nGet-StorageBreakdown\n");

        return Process::timeout(120)->env($env)->run([trim((string) shell_exec('command -v pwsh 2>/dev/null')), '-NoProfile', '-File', $base.'/step.ps1']);
    }

    it('measures a temp folder with known files', function () {
        $base = storageTree();
        $result = runWindowsStep($base, ['PCC_STORAGE_PROFILE' => $base.'/home', 'PCC_STORAGE_ROOT' => $base.'/root']);
        $rows = storageRows($result->output());

        expect($result->output())->toContain('scan: complete')->toContain('format: 2')
            ->and($rows['type'][0])->toBe(['Photos', '1620', '4'])
            ->and($rows['noext'])->toBe([['52', '3']])
            ->and(array_column($rows['root'], 0))->toBe(['Volumes', 'System', 'Users', 'dir5', 'dir4', 'dir3'])
            ->and(storageNear((int) $rows['roots_total'][0][0], 3252 + 9996, 128))->toBeTrue()
            ->and(storageNear((int) $rows['home'][0][0], 5224, 96))->toBeTrue()
            ->and(array_keys(storageSizes($rows['folder'])))->toBe(['Documents', 'Downloads', 'Pictures']);
    })->skip($pwsh === '', 'PowerShell is not installed here');

    it('adds the user folder to the Users folder it lives in', function () {
        $base = storageTree();
        storageFile("{$base}/root/Users/me/Downloads/big.bin", 1000);
        storageFile("{$base}/root/Users/me/AppData/Local/Temp/t.tmp", 50);
        storageFile("{$base}/root/Users/me/AppData/Local/Other/l.dat", 70);
        storageFile("{$base}/root/Users/me/AppData/Roaming/r.dat", 30);
        $result = runWindowsStep($base, ['PCC_STORAGE_PROFILE' => $base.'/root/Users/me', 'PCC_STORAGE_ROOT' => $base.'/root']);
        $rows = storageRows($result->output());
        $library = storageSizes($rows['library']);

        expect(storageNear((int) storageSizes($rows['root'])['Users'], 800 + 1150, 96))->toBeTrue()
            ->and(storageNear($library['Temp'], 50, 16))->toBeTrue()
            ->and(storageNear($library['Local'], 120, 24))->toBeTrue()
            ->and(storageNear($library['Roaming'], 30, 16))->toBeTrue()
            ->and(storageNear(storageSizes($rows['folder'])['AppData'], 150, 40))->toBeTrue();
    })->skip($pwsh === '', 'PowerShell is not installed here');

    it('lists the eight biggest folders in the profile by name and the five biggest inside the two biggest', function () {
        $base = namedHomeTree();
        $rows = storageRows(runWindowsStep($base, ['PCC_STORAGE_PROFILE' => $base.'/home', 'PCC_STORAGE_ROOT' => $base.'/root'])->output());

        expect(array_keys(namedHome($rows)))->toBe(['Library', 'Projects', '.cache', 'Downloads', 'Documents', 'Movies', 'Music', 'Pictures'])
            ->and(storageNear(namedHome($rows)['Library'], 4000, 64))->toBeTrue()
            ->and(array_keys(namedSub($rows, 'Library')))->toBe(['Application Support', 'Caches', 'Logs', 'Containers', 'Mail'])
            ->and(array_keys(namedSub($rows, 'Projects')))->toBe(['app', 'site'])
            ->and(array_values(array_unique(array_column($rows['sub'], 0))))->toBe(['Library', 'Projects']);
    })->skip($pwsh === '', 'PowerShell is not installed here');

    it('cleans the names the same way as the Mac step', function () {
        $base = oddNamesTree([
            'pipe|name' => 190, "tab\tname" => 180, "ctl\x01\x07x" => 170, '-dash' => 160, '"quote" \'x\'' => 150,
            "Fotos de Mu\u{00f1}oz \u{1F334}" => 140, str_repeat('L', 200) => 130, '  spaced    out  ' => 120,
        ]);
        $rows = storageRows(runWindowsStep($base, ['PCC_STORAGE_PROFILE' => $base.'/home', 'PCC_STORAGE_ROOT' => $base.'/root'])->output());

        expect(array_keys(namedHome($rows)))->toBe(['pipename', 'tab name', 'ctlx', '-dash', '"quote" \'x\'', "Fotos de Mu\u{00f1}oz \u{1F334}", str_repeat('L', 60), 'spaced out']);
    })->skip($pwsh === '', 'PowerShell is not installed here');

    it('does not follow a link out of the profile or list it', function () {
        $base = namedHomeTree();
        storageFile("{$base}/outside/big.dat", 9000);
        symlink("{$base}/outside", "{$base}/home/linked");
        $rows = storageRows(runWindowsStep($base, ['PCC_STORAGE_PROFILE' => $base.'/home', 'PCC_STORAGE_ROOT' => $base.'/root'])->output());

        expect(array_keys(namedHome($rows)))->not->toContain('linked');
    })->skip($pwsh === '', 'PowerShell is not installed here');
});

function rootsTree(): string
{
    $base = ScriptTemp::dir('pcc-script-storage');

    foreach (['Users/me/Downloads/d.bin' => 600, 'Users/me/Documents/x.pdf' => 200, 'Users/other/o.dat' => 300, 'Applications/A.app/bin' => 700,
        'System/s.dat' => 900, 'private/p.dat' => 400, 'Library/l.dat' => 100, 'usr/u.dat' => 50, 'bin/b' => 20, 'sbin/s' => 20] as $file => $kib) {
        storageFile("{$base}/root/{$file}", $kib);
    }

    return $base;
}

/**
 * A du whose pass over the top of the drive is lost, that logs the folder each one-folder measurement
 * is for, and that runs $slow first for the folders matching $pattern.
 */
function rootsDu(string $pattern = '*/nothing', string $slow = ':'): string
{
    return fakeMacBin(['du' => 'for last; do :; done; case " $* " in *" -d 0 "*) echo "$last" >> "$(dirname "$0")/order"; case "$last" in '.$pattern.') '.$slow.' ;; esac; exec /usr/bin/du "$@" ;; esac; case "$last" in */root) kill -ALRM $$ ;; esac; exec /usr/bin/du "$@"']);
}

/**
 * @return list<string>
 */
function rootsOrder(string $bin, string $base): array
{
    return array_map(fn (string $line): string => str_replace($base.'/root/', '', $line), file($bin.'/order', FILE_IGNORE_NEW_LINES) ?: []);
}

describe('the order of the Mac step', function () {
    it('measures the user folder before the rest of the drive', function () {
        $base = storageTree();
        $bin = fakeMacBin(['du' => 'for last; do :; done; echo "$last" >> "$(dirname "$0")/order"; exec /usr/bin/du "$@"']);
        runStorage($base, [], $bin.':/usr/bin:/bin');
        $order = file($bin.'/order', FILE_IGNORE_NEW_LINES) ?: [];

        expect($order[0])->toBe($base.'/home')->and($order)->toContain($base.'/root');
    });

    it('measures the big well-known folders first when the pass over the drive is lost', function () {
        $base = rootsTree();
        $bin = rootsDu();
        runStorage($base, ['PCC_STORAGE_HOME' => $base.'/root/Users/me'], $bin.':/usr/bin:/bin');

        expect(array_values(array_filter(rootsOrder($bin, $base), fn (string $name): bool => ! str_contains($name, '/'))))
            ->toBe(['Applications', 'System', 'private', 'Library', 'usr', 'bin', 'sbin']);
    });

    it('adds the user folder it already measured to the rest of Users instead of measuring it again', function () {
        $base = rootsTree();
        $bin = rootsDu();
        $rows = storageRows(runStorage($base, ['PCC_STORAGE_HOME' => $base.'/root/Users/me'], $bin.':/usr/bin:/bin')->output());

        expect(storageNear(storageSizes($rows['root'])['Users'], 600 + 200 + 300, 48))->toBeTrue()
            ->and(rootsOrder($bin, $base))->not->toContain('Users')->toContain('Users/other');
    });

    it('measures the slow well-known folders in the time the user folder did not need', function () {
        $base = rootsTree();
        $bin = rootsDu('*/root/Applications|*/root/System|*/root/private|*/root/Users/other', 'sleep 2');
        $result = runStorage($base, ['PCC_STORAGE_HOME' => $base.'/root/Users/me', 'PCC_STORAGE_CAP' => '30'], $bin.':/usr/bin:/bin');
        $rows = storageRows($result->output());

        expect(array_keys(storageSizes($rows['root'])))->toBe(['Applications', 'Library', 'System', 'Users', 'private', 'usr'])
            ->and(storageNear((int) $rows['roots_total'][0][0], 3290, 96))->toBeTrue()
            ->and($rows)->not->toHaveKey('missing');
    });

    it('goes back to a well-known folder that did not finish in its share while time is left', function () {
        $base = rootsTree();
        $bin = rootsDu('*/root/Applications', 'if [ ! -e "$(dirname "$0")/tried" ]; then : > "$(dirname "$0")/tried"; exec sleep 120; fi');
        $result = runStorage($base, ['PCC_STORAGE_HOME' => $base.'/root/Users/me', 'PCC_STORAGE_CAP' => '40'], $bin.':/usr/bin:/bin');
        $roots = storageSizes(storageRows($result->output())['root']);

        expect($roots)->toHaveKey('Applications')
            ->and(storageNear($roots['Applications'], 700, 16))->toBeTrue()
            ->and(array_count_values(rootsOrder($bin, $base))['Applications'])->toBe(2);
    });
});
