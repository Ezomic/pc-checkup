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
 * @return array{partial: bool, folders: list<array{name: string, bytes: float, count: int|null}>, roots: list<array{name: string, bytes: float, count: int|null}>, categories: list<array{name: string, bytes: float, count: int|null}>, extensions: list<array{name: string, bytes: float, count: int|null}>}|null
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

    it('keeps only the five biggest drive folders and the eight biggest extensions', function () {
        $roots = implode("\n", array_map(fn (int $i): string => "root|Dir{$i}|".($i * 10), range(1, 9)));
        $exts = implode("\n", array_map(fn (int $i): string => "ext|e{$i}|".($i * 10).'|1', range(1, 12)));
        $read = readStorage("scan: complete\n{$roots}\n{$exts}");

        expect(storageNames($read['roots']))->toBe(['Dir9', 'Dir8', 'Dir7', 'Dir6', 'Dir5'])
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
        $read = readStorage("scan: complete\nfolder|Downloads|10\nfolder|Downloads|99\nroot|Users|5\nroot|users|50\ntype|Photos|1|1\ntype|Photos|9|9\next|jpg|1|1\next|JPG|9|9");

        expect($read['folders'][0]['bytes'])->toBe(10240.0)
            ->and(storageNames($read['roots']))->toBe(['Users'])
            ->and($read['categories'][0]['bytes'])->toBe(1024.0)
            ->and($read['extensions'])->toBe([['name' => 'jpg', 'bytes' => 1024.0, 'count' => 1]]);
    });

    it('only accepts the well-known folder names and the seven kinds of file', function () {
        $read = readStorage("scan: complete\nfolder|Secret Project|10\nfolder|Downloads|5\nfolder|downloads|6\ntype|Passwords|10|1\ntype|Photos|5|1");

        expect(storageNames($read['folders']))->toBe(['Downloads'])
            ->and(storageNames($read['categories']))->toBe(['Photos']);
    });

    it('reads an extension only when it is up to ten letters and digits', function (string $ext, ?string $expected) {
        $read = readStorage("scan: complete\nfolder|Downloads|1\next|{$ext}|10|1");

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
