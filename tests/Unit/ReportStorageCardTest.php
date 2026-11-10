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

    expect($bars[1]['title'])->toBe('Your folders')
        ->and($bars[1]['items'])->toBe([
            ['label' => 'Library', 'value' => '20 GB', 'detail' => '25% of used space', 'share' => 100],
            ['label' => 'Downloads', 'value' => '1.5 GB', 'detail' => '2% of used space', 'share' => 8],
            ['label' => 'Documents', 'value' => '4 MB', 'detail' => 'under 1% of used space', 'share' => 1],
        ])
        ->and($bars[0]['title'])->toBe('Biggest folders on the drive')
        ->and($bars[0]['items'][0])->toBe(['label' => 'Users', 'value' => '30 GB', 'detail' => '38% of used space', 'share' => 100]);
});

it('shows the kinds of files with counts and their share of what was counted', function () {
    $bars = bdCard(bdDoc(BD_LINES))['bars'];

    expect($bars[2]['title'])->toBe('Kinds of files in your user folder')
        ->and($bars[2]['items'])->toBe([
            ['label' => 'Videos', 'value' => '10 GB', 'detail' => '1 file, 67% of what was counted', 'share' => 100],
            ['label' => 'Photos', 'value' => '5 GB', 'detail' => '1,200 files, 33% of what was counted', 'share' => 50],
            ['label' => 'Other', 'value' => '1 MB', 'detail' => '9 files, under 1% of what was counted, mostly system and app files', 'share' => 1],
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
        $items = bdCard(bdDoc(BD_LINES, $disk))['bars'][1]['items'];

        expect(array_column($items, 'detail'))->toBe([null, null, null])
            ->and(array_column($items, 'share'))->toBe([100, 8, 1]);
    })->with([
        'no disk section' => null,
        'zero sized disk' => '/dev/disk1s1  0Gi  0Gi  0Gi  0%  1  2  3%  /',
        'free more than the size' => '/dev/disk1s1  10Gi  1Gi  20Gi  1%  1  2  3%  /',
    ]);

    it('caps the share at a hundred percent when a folder is bigger than the used space', function () {
        $items = bdCard(bdDoc(BD_LINES, '/dev/disk1s1  100Gi  10Gi  90Gi  10%  1  2  3%  /'))['bars'][1]['items'];

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
        $card = bdCard(bdDoc("scan: complete\nroot|Users|100\next|jpg|500|2"));

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

        expect(bdCard($markdown)['bars'][1]['items'][0]['detail'])->toBe('25% of used space');
    });
});

const BD_REAL_DISK = '/dev/disk1s1  113Gi  101Gi  12Gi  90%  1  2  3%  /';

const BD_V2 = "scan: partial (time limit of 150 seconds reached)\nseconds: 150\nlimit: 150\nformat: 2\n"
    ."root|Applications|13333212\nroot|System|7232700\nroot|Library|4357068\nroots_total|24922980\n"
    ."missing|root/Users\nmissing|root/private\n";

const BD_NOT_MEASURED_NOTE = 'Not measured is used space that the folders above do not add up to, such as hidden system files, snapshots and anything the scan could not read.';

/**
 * @param  array<string, mixed>|null  $card
 * @return list<array{label: string, value: string, detail: string|null, share: int}>
 */
function bdItems(?array $card, int $group): array
{
    return $card['bars'][$group]['items'];
}

function bdFolders(string $lines, string $folders): string
{
    return bdDoc($lines, BD_REAL_DISK)."\n## Performance: biggest folders in home\n\n```\n{$folders}\n```\n";
}

/**
 * @return array<string, mixed>
 */
function realOldCard(): array
{
    $card = bdCard(bdDoc((string) file_get_contents(__DIR__.'/../Fixtures/storage-breakdown-old-mac.txt'), BD_REAL_DISK));
    expect($card)->not->toBeNull();

    return $card;
}

describe('the real section from an old Mac, in the first format', function () {
    it('still renders, names what it found and does not invent a not measured number', function () {
        $card = realOldCard();

        expect($card['summary'])->toBe('The biggest folders found are Applications (13 GB), System (6.9 GB) and Library (4.2 GB).')
            ->and(array_column($card['bars'], 'title'))->toBe(['Biggest folders on the drive', 'Your folders', 'Kinds of files in your user folder', 'Biggest file endings'])
            ->and(array_column(bdItems($card, 0), 'label'))->toBe(['Applications', 'System', 'Library'])
            ->and($card['notes'])->toBe([
                'The scan stopped at its time limit, so these sizes are a minimum and the real numbers may be bigger.',
                'Kinds of files count your own user folder only. Only sizes and counts were recorded, no file names.',
            ]);
    });

    it('reads the odd endings as bundles and leaves the small ones out', function () {
        expect(array_column(bdItems(realOldCard(), 3), 'label'))->toBe(['.file', 'app and system bundles', '.ver1', '.png', '.db', '.avi', '.tbz', '.pptx']);
    });

    it('tells the owner that Other is mostly system and app files', function () {
        $other = collect(bdItems(realOldCard(), 2))->firstWhere('label', 'Other');

        expect($other['detail'])->toBe('31,019 files, 91% of what was counted, mostly system and app files');
    });
});

describe('the second format', function () {
    it('explains where the space went, what was not measured and which folders those were', function () {
        $card = bdCard(bdDoc(BD_V2, BD_REAL_DISK));

        expect($card['summary'])->toBe('The biggest folders found are Applications (13 GB), System (6.9 GB) and Library (4.2 GB). 77 GB was not measured.')
            ->and(bdItems($card, 0)[3])->toBe(['label' => 'Not measured', 'value' => '77 GB', 'detail' => '76% of used space', 'share' => 100])
            ->and($card['notes'])->toBe([
                'The scan stopped at its time limit, so these sizes are a minimum and the real numbers may be bigger.',
                'Not measured before the time ran out: Users, private.',
                BD_NOT_MEASURED_NOTE,
            ]);
    });

    it('says most of the space when the three biggest folders are over half of it', function () {
        $card = bdCard(bdDoc("scan: complete\nformat: 2\nroot|Users|52428800\nroot|System|10485760\nroots_total|62914560", BD_REAL_DISK));

        expect($card['summary'])->toStartWith('Most of the space is used by Users (50 GB) and System (10 GB).');
    });

    it('lists the user folder as a total first, then the folders and the library items', function () {
        $lines = "scan: complete\nformat: 2\nroot|Users|52428800\nroots_total|52428800\nhome|50331648\nfolder|Downloads|1048576\nlibrary|Caches|2097152\nlibrary|Local|1024";
        $card = bdCard(bdDoc($lines, BD_REAL_DISK));

        expect(array_column($card['bars'], 'title'))->toBe(['Biggest folders on the drive', 'Your folders', 'Library and AppData'])
            ->and(array_column(bdItems($card, 1), 'label'))->toBe(['Everything in your user folder', 'Downloads'])
            ->and(bdItems($card, 1)[0]['value'])->toBe('48 GB')
            ->and(array_column(bdItems($card, 2), 'label'))->toBe(['Caches', 'AppData Local']);
    });

    it('says the user folder uses space when the drive pass found nothing', function () {
        $card = bdCard(bdDoc("scan: partial\nformat: 2\nhome|2097152\nfolder|Downloads|10", BD_REAL_DISK));

        expect($card['summary'])->toBe('Your user folder uses 2 GB.');
    });

    it('says everything was unmeasured when the drive pass measured nothing', function () {
        $card = bdCard(bdDoc("scan: partial\nformat: 2\nroots_total|0\nmissing|root/Users", BD_REAL_DISK));

        expect($card['summary'])->toBe('101 GB was not measured.')
            ->and(bdItems($card, 0))->toBe([['label' => 'Not measured', 'value' => '101 GB', 'detail' => '100% of used space', 'share' => 100]]);
    });

    it('has no summary when there is nothing to say', function () {
        expect(bdCard(bdDoc("scan: complete\nformat: 2\nfolder|Downloads|10", BD_REAL_DISK))['summary'])->toBeNull();
    });

    it('calls a folder that exists but could not be read unreadable, not out of time', function () {
        $card = bdCard(bdDoc("scan: complete\nformat: 2\nroot|Users|10\nroots_total|10\nmissing|home/Downloads", BD_REAL_DISK));

        expect($card['notes'])->toContain('Could not be read: Downloads.');
    });

    it('keeps the two Applications folders apart with a note', function () {
        $card = bdCard(bdDoc("scan: complete\nformat: 2\nroot|Applications|100\nfolder|Applications|50", BD_REAL_DISK));

        expect($card['notes'])->toContain('Applications in your user folder is a separate folder from the Applications folder on the drive.');
    });

    it('does not show a not measured number that is zero or negative', function (string $total) {
        $card = bdCard(bdDoc("scan: complete\nformat: 2\nroot|Users|10\nroots_total|{$total}", BD_REAL_DISK));

        expect(array_column(bdItems($card, 0), 'label'))->toBe(['Users'])
            ->and($card['summary'])->not->toContain('not measured')
            ->and($card['notes'])->not->toContain(BD_NOT_MEASURED_NOTE);
    })->with(['exactly the used space' => '105906176', 'more than the used space' => '999999999']);

    it('hides the not measured number when the used space cannot be read', function (?string $disk) {
        $card = bdCard(bdDoc(BD_V2, $disk));

        expect(array_column(bdItems($card, 0), 'label'))->not->toContain('Not measured')
            ->and($card['summary'])->toBe('The biggest folders found are Applications (13 GB), System (6.9 GB) and Library (4.2 GB).');
    })->with([
        'no disk section' => null,
        'zero sized disk' => '/dev/disk1s1  0Gi  0Gi  0Gi  0%  1  2  3%  /',
        'free more than the size' => '/dev/disk1s1  10Gi  1Gi  20Gi  1%  1  2  3%  /',
        'a disk line that cannot be read' => '/dev/disk1s1  big  lots  few  1%  1  2  3%  /',
    ]);

    it('does not show a not measured number for the first format, which has no total', function () {
        $card = bdCard(bdDoc("scan: complete\nroot|Users|10", BD_REAL_DISK));

        expect(array_column(bdItems($card, 0), 'label'))->toBe(['Users']);
    });

    it('ignores a missing name that is not on the fixed lists', function () {
        $card = bdCard(bdDoc("scan: partial\nformat: 2\nroot|Users|10\nroots_total|10\nmissing|root/My Secret Project", BD_REAL_DISK));

        expect($card['notes'])->toBe(['The scan stopped at its time limit, so these sizes are a minimum and the real numbers may be bigger.', BD_NOT_MEASURED_NOTE])
            ->and(json_encode($card))->not->toContain('Secret');
    });
});

describe('the storage card next to the breakdown', function () {
    $folders = "38G\t/Users/me/Applications\n21G\t/Users/me/Movies";

    it('drops its biggest folders list when the breakdown measured the user folder, so the two never disagree', function () use ($folders) {
        $card = collect((new ReportDetails(new ReportFacts))->cards(bdFolders("scan: complete\nformat: 2\nhome|2097152\nfolder|Applications|1024", $folders), [], 'laptop'))->firstWhere('id', 'storage');

        expect($card['list'])->toBeNull();
    });

    it('keeps its list for a first format breakdown, which never measured the user folder as a whole', function () use ($folders) {
        $card = collect((new ReportDetails(new ReportFacts))->cards(bdFolders("scan: complete\nfolder|Downloads|1024", $folders), [], 'laptop'))->firstWhere('id', 'storage');

        expect(array_column($card['list']['items'], 'label'))->toBe(['Applications', 'Movies']);
    });

    it('keeps its list when the second format breakdown measured no user folder at all', function () use ($folders) {
        $card = collect((new ReportDetails(new ReportFacts))->cards(bdFolders("scan: partial\nformat: 2\nroots_total|0\nmissing|root/Users", $folders), [], 'laptop'))->firstWhere('id', 'storage');

        expect($card['list']['items'])->toHaveCount(2);
    });
});

const BD_MAC_SHORT = "scan: partial (time limit of 150 seconds reached)\nseconds: 75\nlimit: 150\nformat: 2\n"
    ."root|Library|3145728\nroot|usr|1572864\nroot|sbin|209715\nroot|bin|104858\nroots_total|5033165\n"
    ."missing|root/Applications\nmissing|root/System\nmissing|root/Users\nmissing|root/private\n"
    ."home|52496856\nhome|Library|31457280\nfolder|Library|31457280\nfolder|Documents|10485760\n";

const BD_WINDOWS_SHORT = "scan: partial (time limit of 150 seconds reached)\nseconds: 150\nlimit: 150\nformat: 2\n"
    ."root|Program Files (x86)|144649377\nroot|XboxGames|132703014\nroot|World of Warcraft|50761723\nroot|Program Files|20008352\n"
    ."root|ProgramData|13550024\nroot|build-queue|10485760\nroots_total|372158250\nmissing|root/Users\nmissing|root/Windows\n"
    ."home|AppData|238349894\nhome|Repositories|60411534\n"
    ."folder|Downloads|6845726\nfolder|Documents|34761120\nfolder|Desktop|8061383\nfolder|Pictures|3029739\nfolder|Videos|3917179\nfolder|AppData|238349894\n";

function bdWindows(string $lines): string
{
    return bdDoc($lines, null)."\n## Storage (volumes and free space)\n\n```\nDeviceID VolumeName FileSystem SizeGB FreeGB Free%\nC:       OS         NTFS       931    131    14\n```\n";
}

/**
 * @return array<string, string>
 */
function bdValues(?array $card): array
{
    return array_column(bdItems($card, 0), 'value', 'label');
}

describe('a user folder that was measured while the Users folder on the drive was not', function () {
    it('counts the user folder as measured on a Mac and shows it as a folder on the drive', function () {
        $card = bdCard(bdDoc(BD_MAC_SHORT, '/dev/disk1s1  112Gi  100Gi  12Gi  90%  1  2  3%  /'));

        expect(array_column(bdItems($card, 0), 'label'))->toBe(['Users (your folder)', 'Library', 'usr', 'sbin', 'bin', 'Not measured'])
            ->and(bdValues($card)['Users (your folder)'])->toBe('50 GB')
            ->and(bdValues($card)['Not measured'])->toBe('45 GB')
            ->and($card['summary'])->toBe('Most of the space is used by your user folder (50 GB), Library (3 GB) and usr (1.5 GB). The biggest folder in your user folder is Library (30 GB). 45 GB was not measured.')
            ->and($card['notes'])->toContain('Not measured before the time ran out: Applications, System, Users, private.');
    });

    it('counts the folders measured in the user folder on Windows when its total is not known', function () {
        $card = bdCard(bdWindows(BD_WINDOWS_SHORT));

        expect(array_column(bdItems($card, 0), 'label'))->toBe(['Users (your folder)', 'Program Files (x86)', 'XboxGames', 'World of Warcraft', 'Program Files', 'ProgramData', 'build-queue', 'Not measured'])
            ->and(bdValues($card)['Users (your folder)'])->toBe('281 GB')
            ->and(bdValues($card)['Not measured'])->toBe('164 GB')
            ->and($card['summary'])->toBe('Most of the space is used by your user folder (281 GB), Program Files (x86) (138 GB) and XboxGames (127 GB). The biggest folder in your user folder is AppData (227 GB). 164 GB was not measured.');
    });

    it('does not add the user folder again when the Users folder on the drive was measured', function () {
        $card = bdCard(bdDoc("scan: partial\nformat: 2\nroot|Users|52496856\nroot|Library|3145728\nroots_total|55642584\nmissing|root/System\nhome|52496856\nfolder|Library|31457280", '/dev/disk1s1  112Gi  100Gi  12Gi  90%  1  2  3%  /'));

        expect(array_column(bdItems($card, 0), 'label'))->toBe(['Users', 'Library', 'Not measured'])
            ->and(bdValues($card)['Not measured'])->toBe('47 GB');
    });

    it('adds nothing when neither the user folder nor any folder in it was measured', function () {
        $card = bdCard(bdDoc("scan: partial\nformat: 2\nroot|Library|3145728\nroots_total|3145728\nmissing|root/Users\nfolder|Downloads|0", '/dev/disk1s1  112Gi  100Gi  12Gi  90%  1  2  3%  /'));

        expect(array_column(bdItems($card, 0), 'label'))->toBe(['Library', 'Not measured'])
            ->and(bdValues($card)['Not measured'])->toBe('97 GB');
    });

    it('shows no not measured number when the user folder and the drive folders cover the used space', function () {
        $card = bdCard(bdDoc("scan: partial\nformat: 2\nroot|Library|3145728\nroots_total|3145728\nmissing|root/Users\nhome|104857600", '/dev/disk1s1  112Gi  100Gi  12Gi  90%  1  2  3%  /'));

        expect(array_column(bdItems($card, 0), 'label'))->toBe(['Users (your folder)', 'Library'])
            ->and($card['notes'])->not->toContain(BD_NOT_MEASURED_NOTE);
    });

    it('leaves the first format alone, which has no total for the drive', function () {
        $card = bdCard(bdDoc("scan: partial\nroot|Library|10\nmissing|root/Users\nhome|2097152", BD_REAL_DISK));

        expect(array_column(bdItems($card, 0), 'label'))->toBe(['Library']);
    });
});
