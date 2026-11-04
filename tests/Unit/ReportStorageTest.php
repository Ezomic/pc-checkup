<?php

declare(strict_types=1);

use App\Services\Reports\ReportSections;
use App\Services\Reports\ReportStorage;

const STORAGE_FULL = "scan: complete\nseconds: 41\nlimit: 90\n"
    ."folder|Downloads|1572864\nfolder|Documents|4096\nfolder|Library|20971520\n"
    ."root|System|9437184\nroot|Users|31457280\nroot|Program Files (x86)|2097152\n"
    ."type|Photos|5242880|1200\ntype|Videos|10485760|40\ntype|Other|1024|9\n"
    ."ext|jpg|4194304|1000\next|mp4|10485760|40\next|png|1048576|200\n";

function storageDoc(string $body, string $title = 'Storage breakdown'): string
{
    return "# Mac checkup: Test\n\n- Format: 1\n\n## {$title}\n\n```\n{$body}\n```\n\n## Disk usage\n\n```\nnothing\n```\n";
}

/**
 * @return array<string, mixed>|null
 */
function readStorage(string $body): ?array
{
    return (new ReportStorage)->read(ReportSections::parse(storageDoc($body)));
}

/**
 * @param  list<array{name: string, bytes: float, count: int|null}>  $items
 * @return list<string>
 */
function storageNames(array $items): array
{
    return array_column($items, 'name');
}

describe('a complete section', function () {
    it('reads folders, drive folders, kinds and extensions biggest first', function () {
        $read = readStorage(STORAGE_FULL);

        expect($read)->not->toBeNull()
            ->and($read['partial'])->toBeFalse()
            ->and(storageNames($read['folders']))->toBe(['Library', 'Downloads', 'Documents'])
            ->and($read['folders'][0]['bytes'])->toBe(20971520.0 * 1024)
            ->and(storageNames($read['roots']))->toBe(['Users', 'System', 'Program Files (x86)'])
            ->and(storageNames($read['categories']))->toBe(['Videos', 'Photos', 'Other'])
            ->and($read['categories'][1])->toBe(['name' => 'Photos', 'bytes' => 5242880.0 * 1024, 'count' => 1200])
            ->and(storageNames($read['extensions']))->toBe(['mp4', 'jpg', 'png']);
    });

    it('reads a report with Windows line endings', function () {
        $lf = readStorage(STORAGE_FULL);
        $crlf = (new ReportStorage)->read(ReportSections::parse(str_replace("\n", "\r\n", storageDoc(STORAGE_FULL))));

        expect($crlf)->toBe($lf);
    });

    it('keeps only the six biggest drive folders and the eight biggest extensions', function () {
        $roots = implode("\n", array_map(fn (int $i): string => "root|Dir{$i}|".($i * 10), range(1, 9)));
        $exts = implode("\n", array_map(fn (int $i): string => "ext|e{$i}|".($i * 1000).'|1', range(1, 12)));
        $read = readStorage("scan: complete\n{$roots}\n{$exts}");

        expect(storageNames($read['roots']))->toBe(['Dir9', 'Dir8', 'Dir7', 'Dir6', 'Dir5', 'Dir4'])
            ->and(storageNames($read['extensions']))->toBe(['e12', 'e11', 'e10', 'e9', 'e8', 'e7', 'e6', 'e5']);
    });
});

describe('the partial flag', function () {
    it('is set when the scan hit its time limit', function (string $line) {
        expect(readStorage("{$line}\nfolder|Downloads|10")['partial'])->toBeTrue();
    })->with([
        'worded' => 'scan: partial (time limit of 90 seconds reached)',
        'bare' => 'scan: partial',
        'upper case' => 'SCAN: PARTIAL',
        'unknown word' => 'scan: whatever',
        'missing' => 'seconds: 3',
    ]);

    it('is not set for a complete scan in any casing', function (string $line) {
        expect(readStorage("{$line}\nfolder|Downloads|10")['partial'])->toBeFalse();
    })->with(['scan: complete', 'Scan: Complete', 'scan:complete']);
});

describe('data that cannot be used', function () {
    it('returns nothing when the section is missing', function () {
        expect((new ReportStorage)->read(ReportSections::parse("# Report\n\n## Disk usage\n\n```\nx\n```\n")))->toBeNull()
            ->and((new ReportStorage)->read(ReportSections::parse('')))->toBeNull();
    });

    it('returns nothing when the section holds no usable line', function (string $body) {
        expect(readStorage($body))->toBeNull();
    })->with([
        'empty' => '',
        'unavailable note' => 'unavailable (needs admin)',
        'only the header lines' => "scan: complete\nseconds: 4\nlimit: 90",
        'garbage' => "lorem ipsum\n||||\nfolder\nfolder|\nfolder||5\n",
        'every size is n/a' => "folder|Downloads|n/a\nroot|Users|N/A\ntype|Photos|n/a|n/a\next|jpg|n/a|1",
        'a hand written heading is not the section' => '## nothing here',
    ]);

    it('skips a size that is not a plain non-negative number', function (string $size) {
        $read = readStorage("scan: complete\nfolder|Downloads|{$size}\nfolder|Music|5");

        expect(storageNames($read['folders']))->toBe(['Music']);
    })->with([
        'n/a' => 'n/a',
        'negative' => '-5',
        'negative with unit' => '-5 GB',
        'words' => 'lots',
        'empty' => '',
        'exponent' => '1e9',
        'two points' => '1.2.3',
        'more than a petabyte' => '99999999999999999999',
        'huge with unit' => '5000000 TB',
    ]);

    it('reads sizes given in different units', function () {
        $read = readStorage("scan: complete\nfolder|Downloads|2 MB\nfolder|Documents|1.5GB\nfolder|Desktop|512 kb\nfolder|Music|2048 B\nfolder|Pictures|1 TB\nfolder|Library|7");

        expect(array_column($read['folders'], 'bytes', 'name'))->toEqual([
            'Downloads' => 2.0 * 1024 ** 2,
            'Documents' => 1.5 * 1024 ** 3,
            'Desktop' => 512.0 * 1024,
            'Music' => 2048.0,
            'Pictures' => 1024.0 ** 4,
            'Library' => 7.0 * 1024,
        ]);
    });

    it('keeps a folder with no data in it but drops empty kinds, extensions and drive folders', function () {
        $read = readStorage("scan: complete\nfolder|Downloads|0\nroot|Empty|0\ntype|Photos|0|0\next|jpg|0|0");

        expect(storageNames($read['folders']))->toBe(['Downloads'])
            ->and($read['roots'])->toBe([])
            ->and($read['categories'])->toBe([])
            ->and($read['extensions'])->toBe([]);
    });

    it('skips a file count that is not a whole number but keeps the size', function (string $count) {
        $read = readStorage("scan: complete\ntype|Photos|10|{$count}");

        expect($read['categories'][0]['count'])->toBeNull()
            ->and($read['categories'][0]['bytes'])->toBe(10240.0);
    })->with(['n/a', '-3', '2.5', 'many', '']);

    it('keeps the first of two rows with the same name', function () {
        $read = readStorage("scan: complete\nfolder|Downloads|10\nfolder|Downloads|99\nroot|Users|5\nroot|users|50\ntype|Photos|1|1\ntype|Photos|9|9\next|jpg|1000|1\next|JPG|9000|9");

        expect($read['folders'][0]['bytes'])->toBe(10240.0)
            ->and(storageNames($read['roots']))->toBe(['Users'])
            ->and($read['categories'][0]['bytes'])->toBe(1024.0)
            ->and($read['extensions'])->toBe([['name' => 'jpg', 'bytes' => 1024000.0, 'count' => 1]]);
    });

    it('only accepts the well-known folder names and the seven kinds of file', function () {
        $read = readStorage("scan: complete\nfolder|Secret Project|10\nfolder|Downloads|5\nfolder|downloads|6\ntype|Passwords|10|1\ntype|Photos|5|1");

        expect(storageNames($read['folders']))->toBe(['Downloads'])
            ->and(storageNames($read['categories']))->toBe(['Photos']);
    });

    it('reads an extension only when it is up to ten letters and digits', function (string $ext, ?string $expected) {
        $read = readStorage("scan: complete\nfolder|Downloads|1\next|{$ext}|1000|1");

        expect(storageNames($read['extensions']))->toBe($expected === null ? [] : [$expected]);
    })->with([
        'plain' => ['jpg', 'jpg'],
        'upper case' => ['JPG', 'jpg'],
        'digits' => ['7z', '7z'],
        'ten characters' => ['abcdefghij', 'abcdefghij'],
        'eleven characters' => ['abcdefghijk', null],
        'dot inside' => ['tar.gz', null],
        'leading dot' => ['.jpg', null],
        'unicode' => ['fo'."\u{00F6}", null],
        'emoji' => ["\u{1F4A9}", null],
        'space' => ['j pg', null],
        'markup' => ['<b>', null],
        'path' => ['../etc', null],
        'very long' => [str_repeat('a', 5000), null],
    ]);

    it('cleans a drive folder name and drops one that is empty afterwards', function () {
        $read = readStorage("scan: complete\nroot|Program Files (x86)|5\nroot|My   Stuff|4\nroot|\x01\x02|3\nroot|<script>alert(1)</script>|2\nroot|".str_repeat('x', 200).'|1');

        expect(storageNames($read['roots']))->toBe([
            'Program Files (x86)',
            'My Stuff',
            'scriptalert(1)script',
            str_repeat('x', 40),
        ]);
    });

    it('skips a drive folder row with an extra pipe', function () {
        expect(readStorage("scan: complete\nroot|a|b|5"))->toBeNull();
    });
});

describe('an old report from before the format line', function () {
    it('reads as version one without a total, a home size or missing folders', function () {
        $read = readStorage((string) file_get_contents(__DIR__.'/../Fixtures/storage-breakdown-old-mac.txt'));

        expect($read['version'])->toBe(1)
            ->and($read['partial'])->toBeTrue()
            ->and($read['home'])->toBeNull()
            ->and($read['rootsTotal'])->toBeNull()
            ->and($read['missing'])->toBe([])
            ->and($read['library'])->toBe([])
            ->and(storageNames($read['roots']))->toBe(['Applications', 'System', 'Library'])
            ->and(storageNames($read['folders']))->toBe(['Desktop', 'Pictures', 'Downloads', 'Music', 'Documents', 'Movies']);
    });

    it('turns the odd endings into readable ones and drops the small ones', function () {
        $read = readStorage((string) file_get_contents(__DIR__.'/../Fixtures/storage-breakdown-old-mac.txt'));

        expect($read['extensions'])->toBe([
            ['name' => 'file', 'bytes' => 2695188.0 * 1024, 'count' => 765],
            ['name' => 'app and system bundles', 'bytes' => 919100.0 * 1024, 'count' => 463],
            ['name' => 'ver1', 'bytes' => 779396.0 * 1024, 'count' => 155],
            ['name' => 'png', 'bytes' => 276636.0 * 1024, 'count' => 2199],
            ['name' => 'db', 'bytes' => 171036.0 * 1024, 'count' => 16],
            ['name' => 'avi', 'bytes' => 159760.0 * 1024, 'count' => 3],
            ['name' => 'tbz', 'bytes' => 126704.0 * 1024, 'count' => 3],
            ['name' => 'pptx', 'bytes' => 114520.0 * 1024, 'count' => 1],
        ]);
    });
});

const STORAGE_V2 = "scan: partial (time limit of 150 seconds reached)\nseconds: 150\nlimit: 150\nformat: 2\n"
    ."root|Applications|13333212\nroot|System|7232700\nroots_total|20565912\n"
    ."missing|root/Users\nmissing|home/Library\nmissing|library/Caches\n"
    ."home|50000000\nfolder|Downloads|248540\nfolder|Applications|100\nlibrary|Application Support|900\nlibrary|Temp|5\n"
    ."type|Other|1000|10\nnoext|500000|40\next|package|300|2\next|app|200|1\next|bundle|200|1\n";

describe('the second format', function () {
    it('reads the version, the total, the home size, the library items and the missing names', function () {
        $read = readStorage(STORAGE_V2);

        expect($read['version'])->toBe(2)
            ->and($read['rootsTotal'])->toBe(20565912.0 * 1024)
            ->and($read['home'])->toBe(50000000.0 * 1024)
            ->and(storageNames($read['library']))->toBe(['Application Support', 'Temp files'])
            ->and($read['missing'])->toBe(['Users', 'Library (in your user folder)', 'Library: Caches']);
    });

    it('names the user folders that share a name with a folder on the drive', function () {
        $second = readStorage(STORAGE_V2);
        $first = readStorage("scan: complete\nfolder|Applications|100\nfolder|Library|50\nfolder|Downloads|10");

        expect(storageNames($second['folders']))->toBe(['Downloads', 'Applications (in your user folder)'])
            ->and(storageNames($first['folders']))->toBe(['Applications', 'Library', 'Downloads']);
    });

    it('adds the bundle endings together and labels files without an ending', function () {
        $read = readStorage(STORAGE_V2);

        expect($read['extensions'])->toBe([
            ['name' => 'no extension', 'bytes' => 500000.0 * 1024, 'count' => 40],
            ['name' => 'app and system bundles', 'bytes' => 700.0 * 1024, 'count' => 4],
        ]);
    });
});

describe('odd data in the second format', function () {
    it('ignores a total or home size that is not a plain non-negative number', function (string $size) {
        $read = readStorage("scan: complete\nformat: 2\nfolder|Downloads|5\nroots_total|{$size}\nhome|{$size}");

        expect($read['rootsTotal'])->toBeNull()->and($read['home'])->toBeNull();
    })->with(['n/a', '-5', '', '1e9', '99999999999999999999', '1|2']);

    it('keeps the first total and the first home size when there are two', function () {
        $read = readStorage("scan: complete\nformat: 2\nroots_total|10\nroots_total|99\nhome|20\nhome|88");

        expect($read['rootsTotal'])->toBe(10240.0)->and($read['home'])->toBe(20480.0);
    });

    it('keeps a total of zero, which means nothing could be measured', function () {
        $read = readStorage("scan: partial\nformat: 2\nroots_total|0");

        expect($read['rootsTotal'])->toBe(0.0)->and($read['folders'])->toBe([]);
    });

    it('shows a card for a section with only missing folders', function () {
        expect(readStorage("scan: partial\nformat: 2\nmissing|root/Users")['missing'])->toBe(['Users']);
    });

    it('lists a missing name only when it is on a fixed list, once, in plain words', function (string $line, array $expected) {
        expect(readStorage("scan: partial\nformat: 2\nroots_total|1\n{$line}")['missing'])->toBe($expected);
    })->with([
        'a drive folder' => ['missing|root/Users', ['Users']],
        'a drive folder in any case' => ['missing|root/users', ['Users']],
        'a home folder' => ['missing|home/Downloads', ['Downloads']],
        'the home Library is told apart from the drive one' => ['missing|home/Library', ['Library (in your user folder)']],
        'the home Applications is told apart from the drive one' => ['missing|home/Applications', ['Applications (in your user folder)']],
        'a library item' => ['missing|library/Group Containers', ['Library: Group Containers']],
        'a Windows library item' => ['missing|library/Local', ['Library: AppData Local']],
        'a name without a scope' => ['missing|Users', ['Users']],
        'a bare home name' => ['missing|Downloads', ['Downloads']],
        'a bare library name' => ['missing|Caches', ['Library: Caches']],
        'a made up name' => ['missing|root/Secret Project', []],
        'a made up name without a scope' => ['missing|Secret Project', []],
        'a made up scope' => ['missing|elsewhere/Users', []],
        'a name from the wrong list' => ['missing|root/Downloads', []],
        'a path' => ['missing|root/Users/me', []],
        'an empty name' => ['missing|', []],
        'an extra pipe' => ['missing|root/Users|5', []],
        'a duplicate' => ["missing|root/Users\nmissing|Users", ['Users']],
    ]);

    it('treats a format line that is not a number as the first format', function (string $line) {
        expect(readStorage("scan: complete\n{$line}\nfolder|Downloads|1")['version'])->toBe(1);
    })->with(['format: two', 'format: ', 'format: 2.5', 'format: -2', 'format: 99999']);

    it('never goes below the first format', function () {
        expect(readStorage("scan: complete\nformat: 0\nfolder|Downloads|1")['version'])->toBe(1);
    });

    it('reads a library item only from the fixed list of names', function () {
        $read = readStorage("scan: complete\nformat: 2\nlibrary|Caches|10\nlibrary|Secret Stuff|99\nlibrary|caches|20\nlibrary|Mobile Documents|5");

        expect(storageNames($read['library']))->toBe(['Caches', 'Mobile Documents (iCloud)']);
    });

    it('skips endings under a hundred kilobytes and keeps one at exactly that', function () {
        $read = readStorage("scan: complete\next|big|100|1\next|small|99.9|1\nnoext|99|9\nnoext|100|1");

        expect(storageNames($read['extensions']))->toBe(['big']);
    });

    it('adds up bundle endings with unknown counts without inventing a count', function () {
        $read = readStorage("scan: complete\next|package|200|n/a\next|app|200|n/a");

        expect($read['extensions'])->toBe([['name' => 'app and system bundles', 'bytes' => 409600.0, 'count' => null]]);
    });

    it('ignores a no extension line that is malformed', function (string $line) {
        expect(readStorage("scan: complete\nfolder|Downloads|1\n{$line}")['extensions'])->toBe([]);
    })->with(['noext|n/a|5', 'noext|500', 'noext|500|5|6', 'noext|-5|5']);

    it('keeps a no extension row with an unreadable count', function () {
        expect(readStorage("scan: complete\nnoext|500|many")['extensions'])->toBe([['name' => 'no extension', 'bytes' => 512000.0, 'count' => null]]);
    });

    it('does not mistake a format line for data', function () {
        expect(readStorage("scan: complete\nformat: 2"))->toBeNull();
    });
});
