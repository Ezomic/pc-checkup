<?php

declare(strict_types=1);

use App\Services\Reports\ReportSections;
use App\Services\Reports\ReportStorage;

/**
 * @return array<string, mixed>|null
 */
function homeRead(string ...$lines): ?array
{
    $body = implode("\n", ['scan: complete', 'format: 2', ...$lines]);

    return (new ReportStorage)->read(ReportSections::parse("# Mac checkup: Test\n\n## Storage breakdown\n\n```\n{$body}\n```\n"));
}

/**
 * @return list<string>
 */
function homeNames(string ...$lines): array
{
    return array_column(homeRead(...$lines)['homeFolders'] ?? [], 'name');
}

describe('the biggest folders in the user folder', function () {
    it('reads the named folders biggest first, with their sizes in bytes', function () {
        $read = homeRead('home|1000', 'home|Library|20971520', 'home|.cache|40', 'home|Projects|31457280');

        expect(array_column($read['homeFolders'], 'name'))->toBe(['Projects', 'Library', '.cache'])
            ->and($read['homeFolders'][0])->toBe(['name' => 'Projects', 'bytes' => 31457280.0 * 1024, 'count' => null])
            ->and($read['home'])->toBe(1000.0 * 1024);
    });

    it('keeps only the eight biggest', function () {
        $lines = array_map(fn (int $i): string => "home|Dir{$i}|".($i * 10), range(1, 12));

        expect(homeNames(...$lines))->toBe(['Dir12', 'Dir11', 'Dir10', 'Dir9', 'Dir8', 'Dir7', 'Dir6', 'Dir5']);
    });

    it('reads the folders inside the two biggest, five each, biggest first', function () {
        $lines = ['home|Library|900', 'home|Projects|800', 'home|Movies|700'];

        foreach (range(1, 7) as $i) {
            $lines[] = "sub|Library|Lib{$i}|".($i * 10);
            $lines[] = "sub|Projects|Proj{$i}|".($i * 5);
            $lines[] = "sub|Movies|Mov{$i}|".($i * 2);
        }

        $inside = homeRead(...$lines)['homeInside'];

        expect(array_column($inside, 'parent'))->toBe(['Library', 'Projects'])
            ->and(array_column($inside[0]['items'], 'name'))->toBe(['Lib7', 'Lib6', 'Lib5', 'Lib4', 'Lib3'])
            ->and(array_column($inside[1]['items'], 'name'))->toBe(['Proj7', 'Proj6', 'Proj5', 'Proj4', 'Proj3']);
    });

    it('drops a folder inside a parent that is not in the list', function () {
        $inside = homeRead('home|Library|900', 'sub|Ghost|x|5', 'sub|Library|y|6')['homeInside'];

        expect(array_column($inside, 'parent'))->toBe(['Library'])
            ->and(array_column($inside[0]['items'], 'name'))->toBe(['y']);
    });

    it('drops a folder inside a parent that is listed but not among the two biggest', function () {
        $inside = homeRead('home|A|900', 'home|B|800', 'home|C|700', 'sub|C|x|5')['homeInside'];

        expect($inside)->toBe([]);
    });

    it('matches the parent name regardless of case and of how it was cleaned', function () {
        $inside = homeRead('home|My Stuff|900', "sub|my\tstuff|y|6")['homeInside'];

        expect(array_column($inside[0]['items'], 'name'))->toBe(['y']);
    });

    it('has no inside list for a parent without readable folders', function () {
        expect(homeRead('home|Library|900', 'home|Other|800')['homeInside'])->toBe([]);
    });

    it('reads a report from before the names existed as having none', function () {
        $read = homeRead('home|5000', 'folder|Downloads|100');
        $old = (new ReportStorage)->read(ReportSections::parse("## Storage breakdown\n\n```\n".file_get_contents(__DIR__.'/../Fixtures/storage-breakdown-old-mac.txt')."\n```\n"));

        expect($read['homeFolders'])->toBe([])->and($read['homeInside'])->toBe([])
            ->and($old['homeFolders'])->toBe([])->and($old['homeInside'])->toBe([]);
    });

    it('shows a card for a section with only named folders, and reads no total from it', function () {
        $read = homeRead('home|Projects|10');

        expect($read)->not->toBeNull()->and($read['home'])->toBeNull();
    });

    it('cleans a name the same way for every kind of odd character', function (string $raw, ?string $expected) {
        expect(homeNames("home|{$raw}|10"))->toBe($expected === null ? [] : [$expected]);
    })->with([
        'plain' => ['Projects', 'Projects'],
        'dot folder' => ['.cache', '.cache'],
        'leading dash' => ['-rf', '-rf'],
        'quotes' => ['it"s \'x\'', 'it"s \'x\''],
        'unicode' => ["Fotos de Mu\u{00f1}oz", "Fotos de Mu\u{00f1}oz"],
        'emoji' => ["Trip \u{1F334}", "Trip \u{1F334}"],
        'letter whose bytes include 0x85' => ["\u{00C5}sa \u{0105}", "\u{00C5}sa \u{0105}"],
        'joined emoji' => ["\u{1F468}\u{200D}\u{1F4BB} work", "\u{1F468}\u{200D}\u{1F4BB} work"],
        'tab becomes a space' => ["a\tb", 'a b'],
        'vertical tab and form feed become a space' => ["a\x0B\x0Cb", 'a b'],
        'many spaces collapse' => ['a      b', 'a b'],
        'control characters go' => ["a\x01\x07\x1Bb", 'ab'],
        'delete goes' => ["a\x7Fb", 'ab'],
        'c1 control goes' => ["a\u{0085}b", 'ab'],
        'forward slashes go' => ['a/b', 'ab'],
        'back slashes go' => ['a\\b', 'ab'],
        'looks like a path' => ['../../etc/passwd', '....etcpasswd'],
        'windows path' => ['C:\\Users\\me', 'C:Usersme'],
        'only dots up' => ['..', null],
        'single dot' => ['.', null],
        'empty' => ['', null],
        'only spaces' => ['    ', null],
        'only separators' => ['///', null],
        'right to left override' => ["a\u{202E}b", 'ab'],
        'line separator' => ["a\u{2028}b", 'a b'],
        'zero width space' => ["a\u{200B}b", 'ab'],
        'invalid utf-8' => ["a\xC3\x28b", 'a?(b'],
    ]);

    it('cuts a long name at sixty characters without splitting a character', function () {
        $read = homeRead('home|'.str_repeat("\u{00e9}", 100).'|10', 'home|'.str_repeat('a', 200).'|9');

        expect(mb_strlen($read['homeFolders'][0]['name']))->toBe(60)
            ->and($read['homeFolders'][0]['name'])->toBe(str_repeat("\u{00e9}", 60))
            ->and(strlen($read['homeFolders'][1]['name']))->toBe(60);
    });

    it('trims the space a cut leaves at the end', function () {
        expect(homeNames('home|'.str_repeat('a', 59).' bbb|10'))->toBe([str_repeat('a', 59)]);
    });

    it('never contains a path separator or a pipe whatever the input', function () {
        $read = homeRead('home|a/b\\c|10', 'sub|a/b\\c|d/e|5');

        expect($read['homeFolders'][0]['name'])->toBe('abc')
            ->and($read['homeInside'][0]['items'][0]['name'])->toBe('de');
    });

    it('skips lines with the wrong number of pipes', function () {
        $read = homeRead('home|a|b|10', 'home|Fine|10', 'sub|Fine|x|y|5', 'sub|Fine|10');

        expect(array_column($read['homeFolders'], 'name'))->toBe(['Fine'])->and($read['homeInside'])->toBe([]);
    });

    it('skips sizes that are not plain positive numbers and absurd ones', function (string $size) {
        expect(homeNames("home|Dir|{$size}", 'home|Ok|7'))->toBe(['Ok']);
    })->with(['-5', '0', 'abc', '1e9', '', '99999999999999999999999', '5 parsecs']);

    it('reads a huge but possible size', function () {
        expect(homeRead('home|Dir|1073741824')['homeFolders'][0]['bytes'])->toBe(1073741824.0 * 1024);
    });

    it('keeps the biggest of folders that end up with the same name', function () {
        expect(homeRead('home|Photos|10', 'home|photos|50', 'home|P/hotos|90')['homeFolders'])->toBe([['name' => 'Photos', 'bytes' => 90.0 * 1024, 'count' => null]]);
    });

    it('keeps the biggest of two folders with the same name inside a parent', function () {
        $inside = homeRead('home|Library|900', 'sub|Library|Caches|10', 'sub|Library|caches|70')['homeInside'];

        expect($inside[0]['items'])->toBe([['name' => 'caches', 'bytes' => 70.0 * 1024, 'count' => null]]);
    });

    it('is not fooled by a name that looks like another kind of line', function () {
        $read = homeRead('home|root|10', 'home|folder|5', 'home|missing|3');

        expect(array_column($read['homeFolders'], 'name'))->toBe(['root', 'folder', 'missing'])
            ->and($read['roots'])->toBe([])->and($read['folders'])->toBe([])->and($read['missing'])->toBe([]);
    });
});
