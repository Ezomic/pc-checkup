<?php

declare(strict_types=1);

use App\Services\Reports\ReportDetails;
use App\Services\Reports\ReportFacts;

const BD_DISK = '/dev/disk1s1  100Gi  80Gi  20Gi  80%  100  200  33%  /';

const BD_LINES = "scan: complete\nseconds: 41\nlimit: 90\n"
    ."folder|Downloads|1572864\nfolder|Documents|4096\nfolder|Library|20971520\n"
    ."root|System|9437184\nroot|Users|31457280\n"
    ."type|Photos|5242880|1200\ntype|Videos|10485760|1\ntype|Other|1024|9\n"
    ."ext|mp4|10485760|1\next|jpg|4194304|1000\n";

function bdDoc(string $lines, ?string $disk = BD_DISK): string
{
    $usage = $disk === null ? '' : "\n## Disk usage\n\n```\nFilesystem Size Used Avail Capacity iused ifree %iused Mounted on\n{$disk}\n```\n";

    return "# Mac checkup: Test\n\n- Format: 1\n{$usage}\n## Storage breakdown\n\n```\n{$lines}\n```\n";
}

/**
 * @return array{id: string, title: string, rows: list<array<string, string>>, list: null, bars: list<array{title: string, items: list<array{label: string, value: string, detail: string|null, share: int}>}>, notes: list<string>}|null
 */
function bdCard(string $markdown): ?array
{
    foreach ((new ReportDetails(new ReportFacts))->cards($markdown, [], 'laptop') as $card) {
        if ($card['id'] === 'storage-breakdown') {
            return $card;
        }
    }

    return null;
}

it('shows the card right after the storage card', function () {
    $ids = array_column((new ReportDetails(new ReportFacts))->cards(bdDoc(BD_LINES), [], 'laptop'), 'id');

    expect($ids)->toBe(['storage', 'storage-breakdown']);
});

it('lists the folders with sizes, the share of used space and a bar relative to the biggest', function () {
    $bars = bdCard(bdDoc(BD_LINES))['bars'];

    expect($bars[0]['title'])->toBe('Your folders')
        ->and($bars[0]['items'])->toBe([
            ['label' => 'Library', 'value' => '20 GB', 'detail' => '25% of used space', 'share' => 100],
            ['label' => 'Downloads', 'value' => '1.5 GB', 'detail' => '2% of used space', 'share' => 8],
            ['label' => 'Documents', 'value' => '4 MB', 'detail' => 'under 1% of used space', 'share' => 1],
        ])
        ->and($bars[1]['title'])->toBe('Biggest folders on the drive')
        ->and($bars[1]['items'][0])->toBe(['label' => 'Users', 'value' => '30 GB', 'detail' => '38% of used space', 'share' => 100]);
});

it('shows the kinds of files with counts and their share of what was counted', function () {
    $bars = bdCard(bdDoc(BD_LINES))['bars'];

    expect($bars[2]['title'])->toBe('Kinds of files in your user folder')
        ->and($bars[2]['items'])->toBe([
            ['label' => 'Videos', 'value' => '10 GB', 'detail' => '1 file, 67% of what was counted', 'share' => 100],
            ['label' => 'Photos', 'value' => '5 GB', 'detail' => '1,200 files, 33% of what was counted', 'share' => 50],
            ['label' => 'Other', 'value' => '1 MB', 'detail' => '9 files, under 1% of what was counted', 'share' => 1],
        ])
        ->and($bars[3]['title'])->toBe('Biggest file endings')
        ->and($bars[3]['items'][1])->toBe(['label' => '.jpg', 'value' => '4 GB', 'detail' => '1,000 files, 27% of what was counted', 'share' => 40]);
});

it('says in plain words that only sizes were kept, and when the scan ran out of time', function () {
    $complete = bdCard(bdDoc(BD_LINES));
    $partial = bdCard(bdDoc(str_replace('scan: complete', 'scan: partial (time limit of 90 seconds reached)', BD_LINES)));

    expect($complete['rows'])->toBe([])
        ->and($complete['list'])->toBeNull()
        ->and($complete['title'])->toBe('Storage breakdown')
        ->and($complete['notes'])->toBe(['Kinds of files count your own user folder only. Only sizes and counts were recorded, no file names.'])
        ->and($partial['notes'])->toBe([
            'The scan stopped at its time limit, so these sizes are a minimum and the real numbers may be bigger.',
            'Kinds of files count your own user folder only. Only sizes and counts were recorded, no file names.',
        ]);
});

describe('without a known amount of used space', function () {
    it('leaves the percentages out of the folders', function (?string $disk) {
        $items = bdCard(bdDoc(BD_LINES, $disk))['bars'][0]['items'];

        expect(array_column($items, 'detail'))->toBe([null, null, null])
            ->and(array_column($items, 'share'))->toBe([100, 8, 1]);
    })->with([
        'no disk section' => null,
        'zero sized disk' => '/dev/disk1s1  0Gi  0Gi  0Gi  0%  1  2  3%  /',
        'free more than the size' => '/dev/disk1s1  10Gi  1Gi  20Gi  1%  1  2  3%  /',
    ]);

    it('caps the share at a hundred percent when a folder is bigger than the used space', function () {
        $items = bdCard(bdDoc(BD_LINES, '/dev/disk1s1  100Gi  10Gi  90Gi  10%  1  2  3%  /'))['bars'][0]['items'];

        expect($items[0]['detail'])->toBe('100% of used space');
    });
});

describe('odd data', function () {
    it('shows no card for a report without the section or with nothing usable in it', function () {
        expect(bdCard("# Mac checkup\n\n## Disk usage\n\n```\n".BD_DISK."\n```\n"))->toBeNull()
            ->and(bdCard(bdDoc('scan: complete')))->toBeNull()
            ->and(bdCard(bdDoc("folder|Downloads|n/a\nfolder|Music|-4")))->toBeNull();
    });

    it('divides by nothing when every kind is empty', function () {
        $card = bdCard(bdDoc("scan: complete\nfolder|Downloads|0\ntype|Photos|0|0\next|jpg|0|0"));

        expect($card['bars'])->toHaveCount(1)
            ->and($card['bars'][0]['items'])->toBe([['label' => 'Downloads', 'value' => '0 B', 'detail' => null, 'share' => 0]]);
    });

    it('leaves out a group that has no usable rows', function () {
        $card = bdCard(bdDoc("scan: complete\nroot|Users|100\next|jpg|50|2"));

        expect(array_column($card['bars'], 'title'))->toBe(['Biggest folders on the drive', 'Biggest file endings']);
    });

    it('leaves out the note about kinds of files when the card has none', function () {
        expect(bdCard(bdDoc('scan: complete
folder|Downloads|10'))['notes'])->toBe([]);
    });

    it('shows a drive folder name as plain text', function () {
        $card = bdCard(bdDoc("scan: complete\nroot|<b>Evil</b>|100"));

        expect($card['bars'][0]['items'][0]['label'])->toBe('bEvilb');
    });

    it('reads a Windows disk', function () {
        $markdown = bdDoc(BD_LINES, null)."\n## Storage (volumes and free space)\n\n```\nDeviceID VolumeName FileSystem SizeGB FreeGB Free%\nC:       OS         NTFS       100    20     20\n```\n";

        expect(bdCard($markdown)['bars'][0]['items'][0]['detail'])->toBe('25% of used space');
    });
});
