<?php

declare(strict_types=1);

use App\Services\Reports\ReportDetails;
use App\Services\Reports\ReportFacts;
use App\Services\Reports\ReportSections;
use App\Services\Reports\ReportStorage;

const HT_GIB = 1048576;

const HT_DISK = '/dev/disk1s1  100Gi  80Gi  20Gi  80%  100  200  33%  /';

const HT_LINES = "scan: complete\nformat: 2\nhome|20971520\n"
    ."home|AppData|10485760\nhome|Documents|3145728\n"
    ."sub|AppData|Local|6291456\nsub|AppData|Roaming|2097152\n"
    ."tree|AppData|10485760\ntree|AppData/Local|6291456\ntree|AppData/Local/Google|3145728\n"
    ."tree|AppData/Local/Google/Chrome|2097152\ntree|AppData/Local/Temp|1048576\ntree|AppData/Roaming|2097152\n"
    ."tree|Documents|3145728\n"
    ."library|Local|6291456\nlibrary|Roaming|2097152\n";

/**
 * @return array<string, mixed>|null
 */
function htRead(string ...$lines): ?array
{
    $body = implode("\n", ['scan: complete', 'format: 2', ...$lines]);

    return (new ReportStorage)->read(ReportSections::parse("# Windows checkup: Test\n\n## Storage breakdown\n\n```\n{$body}\n```\n"));
}

/**
 * @return list<array<string, mixed>>
 */
function htTree(string ...$lines): array
{
    return htRead(...$lines)['homeTree'] ?? [];
}

function htCard(string $lines, ?string $disk = HT_DISK): ?array
{
    $usage = $disk === null ? '' : "\n## Disk usage\n\n```\nFilesystem Size Used Avail Capacity iused ifree %iused Mounted on\n{$disk}\n```\n";
    $markdown = "# Mac checkup: Test\n\n- Format: 1\n{$usage}\n## Storage breakdown\n\n```\n{$lines}\n```\n";

    return collect((new ReportDetails(new ReportFacts))->cards($markdown, [], 'laptop'))->firstWhere('id', 'storage-breakdown');
}

/**
 * @return list<array<string, mixed>>
 */
function htHome(array $card): array
{
    return collect($card['bars'])->firstWhere('title', 'Biggest folders in your user folder')['items'];
}

describe('reading the folder tree', function () {
    it('nests each folder under its parent with its size in bytes', function () {
        expect(htTree('tree|AppData|5000', 'tree|AppData/Local|3000', 'tree|AppData/Local/Google|2000', 'tree|AppData/Local/Google/Chrome|1000', 'tree|Documents|400'))->toBe([
            ['name' => 'AppData', 'bytes' => 5000.0 * 1024, 'children' => [
                ['name' => 'Local', 'bytes' => 3000.0 * 1024, 'children' => [
                    ['name' => 'Google', 'bytes' => 2000.0 * 1024, 'children' => [
                        ['name' => 'Chrome', 'bytes' => 1000.0 * 1024, 'children' => []],
                    ]],
                ]],
            ]],
            ['name' => 'Documents', 'bytes' => 400.0 * 1024, 'children' => []],
        ]);
    });

    it('orders siblings biggest first, then by name', function () {
        $tree = htTree('tree|A|900', 'tree|A/zeta|5', 'tree|A/beta|5', 'tree|A/big|50', 'tree|small|10', 'tree|Large|800');

        expect(array_column($tree, 'name'))->toBe(['A', 'Large', 'small'])
            ->and(array_column($tree[0]['children'], 'name'))->toBe(['big', 'beta', 'zeta']);
    });

    it('keeps the eight biggest folders at the top and six inside a folder', function () {
        $lines = ['tree|Top|999999'];

        foreach (range(1, 10) as $i) {
            $lines[] = "tree|Dir{$i}|{$i}";
            $lines[] = "tree|Top/Sub{$i}|{$i}";
        }

        $tree = htTree(...$lines);

        expect(array_column($tree, 'name'))->toBe(['Top', 'Dir10', 'Dir9', 'Dir8', 'Dir7', 'Dir6', 'Dir5', 'Dir4'])
            ->and(array_column($tree[0]['children'], 'name'))->toBe(['Sub10', 'Sub9', 'Sub8', 'Sub7', 'Sub6', 'Sub5']);
    });

    it('finds a parent whatever the case of its name and the order of the lines', function () {
        $tree = htTree('tree|my stuff/Inner|5', "tree|My\tStuff|9");

        expect($tree)->toBe([['name' => 'My Stuff', 'bytes' => 9.0 * 1024, 'children' => [['name' => 'Inner', 'bytes' => 5.0 * 1024, 'children' => []]]]]);
    });

    it('keeps the bigger of two folders whose names end up the same', function () {
        $tree = htTree('tree|Photos|10', 'tree|photos|50', 'tree|Photos/Trip|5');

        expect($tree)->toBe([['name' => 'photos', 'bytes' => 50.0 * 1024, 'children' => [['name' => 'Trip', 'bytes' => 5.0 * 1024, 'children' => []]]]]);
    });

    it('cleans every name the way the folder names are cleaned', function () {
        $tree = htTree("tree|a\x01b|10", "tree|ab/c\u{202E}d|5");

        expect($tree[0]['name'])->toBe('ab')->and($tree[0]['children'][0]['name'])->toBe('cd');
    });

    it('ignores lines it cannot trust', function (string $line) {
        expect(htTree('tree|A|900', 'tree|A/B|500', 'tree|A/B/C|300', 'tree|A/B/C/D|100', $line))->toBe([
            ['name' => 'A', 'bytes' => 900.0 * 1024, 'children' => [
                ['name' => 'B', 'bytes' => 500.0 * 1024, 'children' => [
                    ['name' => 'C', 'bytes' => 300.0 * 1024, 'children' => [
                        ['name' => 'D', 'bytes' => 100.0 * 1024, 'children' => []],
                    ]],
                ]],
            ]],
        ]);
    })->with([
        'deeper than four levels' => ['tree|A/B/C/D/E|50'],
        'parent never listed' => ['tree|Ghost/x|50'],
        'missing size' => ['tree|A/x'],
        'extra field' => ['tree|A/x|50|1'],
        'size that is not a number' => ['tree|A/x|abc'],
        'zero size' => ['tree|A/x|0'],
        'empty segment' => ['tree|A//x|50'],
        'empty path' => ['tree||50'],
        'unreadable parent' => ['tree|../x|50'],
        'unreadable name' => ['tree|A/..|50'],
    ]);

    it('has no tree in a report from before the tree existed', function () {
        expect(htRead('home|5000', 'home|AppData|4000', 'sub|AppData|Local|3000')['homeTree'])->toBe([]);
    });

    it('shows a card for a section with only tree lines', function () {
        expect(htRead('tree|Projects|10'))->not->toBeNull();
    });
});

describe('the card for the folder tree', function () {
    it('draws the user folder as a tree, each folder as a share of its parent, ending with what the folders leave', function () {
        expect(htHome(htCard(HT_LINES)))->toBe([
            ['label' => 'AppData', 'value' => '10 GB', 'detail' => '50% of your user folder', 'share' => 50, 'children' => [
                ['label' => 'Local', 'value' => '6 GB', 'detail' => '60% of AppData', 'share' => 60, 'children' => [
                    ['label' => 'Google', 'value' => '3 GB', 'detail' => '50% of Local', 'share' => 50, 'children' => [
                        ['label' => 'Chrome', 'value' => '2 GB', 'detail' => '67% of Google', 'share' => 67],
                        ['label' => 'Other files and smaller folders', 'value' => '1 GB', 'detail' => '33% of Google', 'share' => 33],
                    ]],
                    ['label' => 'Temp', 'value' => '1 GB', 'detail' => '17% of Local', 'share' => 17],
                    ['label' => 'Other files and smaller folders', 'value' => '2 GB', 'detail' => '33% of Local', 'share' => 33],
                ]],
                ['label' => 'Roaming', 'value' => '2 GB', 'detail' => '20% of AppData', 'share' => 20],
                ['label' => 'Other files and smaller folders', 'value' => '2 GB', 'detail' => '20% of AppData', 'share' => 20],
            ]],
            ['label' => 'Documents', 'value' => '3 GB', 'detail' => '15% of your user folder', 'share' => 15],
            ['label' => 'Other files and smaller folders', 'value' => '7 GB', 'detail' => '35% of your user folder', 'share' => 35],
        ]);
    });

    it('leaves out the Library and AppData group, since the tree covers it', function () {
        expect(array_column(htCard(HT_LINES)['bars'], 'title'))->toBe(['Biggest folders in your user folder']);
    });

    it('measures the top against the used space and adds no remainder there when the user folder total is unknown', function () {
        $lines = "scan: complete\nformat: 2\nhome|AppData|10485760\ntree|AppData|10485760\ntree|AppData/Local|9437184";
        $items = htHome(htCard($lines));

        expect(array_column($items, 'label'))->toBe(['AppData'])
            ->and($items[0]['detail'])->toBe('13% of used space')
            ->and($items[0]['share'])->toBe(13)
            ->and(array_column($items[0]['children'], 'label'))->toBe(['Local', 'Other files and smaller folders']);
    });

    it('sizes the bars against the biggest folder when there is nothing to measure them against', function () {
        $items = htHome(htCard("scan: complete\nformat: 2\nhome|A|2048\nhome|B|1024\ntree|A|2048\ntree|B|1024", null));

        expect($items)->toBe([
            ['label' => 'A', 'value' => '2 MB', 'detail' => null, 'share' => 100],
            ['label' => 'B', 'value' => '1 MB', 'detail' => null, 'share' => 50],
        ]);
    });

    it('leaves out a remainder under 1 MB', function () {
        $lines = "scan: complete\nformat: 2\nhome|4097023\nhome|A|4096000\ntree|A|4096000\ntree|A/B|4095000";
        $items = htHome(htCard($lines));

        expect(array_column($items, 'label'))->toBe(['A'])
            ->and(array_column($items[0]['children'], 'label'))->toBe(['B']);
    });

    it('never draws a bar wider than its parent when the sizes disagree', function () {
        $items = htHome(htCard("scan: complete\nformat: 2\nhome|A|1024\ntree|A|1024\ntree|A/B|4096"));

        expect($items[0]['children'][0]['share'])->toBe(100)
            ->and($items[0]['children'][0]['detail'])->toBe('100% of A');
    });

    it('has no inside lists once there is a tree', function () {
        expect(htHome(htCard(HT_LINES))[0])->not->toHaveKey('inside');
    });

    it('says the names were recorded up to four levels deep', function () {
        expect(htCard(HT_LINES)['notes'])->toContain('The names and sizes of the biggest folders in your user folder, up to 4 levels deep, were recorded so this card can say what takes the space. Only you see them: they are not on the pages you share with clients.');
    });

    it('keeps naming the biggest folder in the summary', function () {
        expect(htCard(HT_LINES, null)['summary'])->toBe('Your user folder uses 20 GB; the biggest part is AppData (10 GB).');
    });

    it('draws a report from before the tree exactly as before', function () {
        $lines = (string) preg_replace('/^tree\|.*\n/m', '', HT_LINES);
        $card = htCard($lines);
        $items = htHome($card);

        expect($lines)->not->toContain('tree|')
            ->and(array_column($card['bars'], 'title'))->toBe(['Biggest folders in your user folder', 'Library and AppData'])
            ->and($items[0])->not->toHaveKey('children')
            ->and($items[0]['detail'])->toBe('13% of used space')
            ->and($items[0]['inside']['title'])->toBe('Inside AppData');
    });
});
