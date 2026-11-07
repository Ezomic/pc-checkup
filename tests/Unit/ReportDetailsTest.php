<?php

declare(strict_types=1);

use App\Services\Reports\ReportDetails;
use App\Services\Reports\ReportFacts;

const MAC_FLAGS = ['Only about 12% of disk is free.', 'macOS 10.14.6 is old and likely out of security support.'];

/**
 * @param  list<string>  $flags
 * @return array<string, array<string, string>>
 */
function detailRows(string $markdown, array $flags = [], ?string $deviceType = 'laptop'): array
{
    $cards = [];

    foreach ((new ReportDetails(new ReportFacts))->cards($markdown, $flags, $deviceType) as $card) {
        if ($card['id'] === 'verdict') {
            continue;
        }

        $cards[$card['id']] = array_column($card['rows'], 'value', 'label');
    }

    return $cards;
}

/**
 * @param  list<string>  $flags
 * @return array{id: string, title: string, rows: list<array{label: string, value: string, tone: string}>, list: array{title: string, items: list<array{label: string, value: string, tone: string}>}|null}|null
 */
function detailCard(string $id, string $markdown, array $flags = [], ?string $deviceType = 'laptop'): ?array
{
    foreach ((new ReportDetails(new ReportFacts))->cards($markdown, $flags, $deviceType) as $card) {
        if ($card['id'] === $id) {
            return $card;
        }
    }

    return null;
}

/**
 * @param  array{rows: list<array{label: string, value: string, tone: string}>}  $card
 */
function toneOf(array $card, string $label): ?string
{
    foreach ($card['rows'] as $row) {
        if ($row['label'] === $label) {
            return $row['tone'];
        }
    }

    return null;
}

function withBlock(string $markdown, string $before, string $after): string
{
    $changed = str_replace($before, $after, $markdown);
    expect($changed)->not->toBe($markdown);

    return $changed;
}

describe('age and support', function () {
    it('describes a Mac', function () {
        $card = detailCard('age', macReportFixture(), MAC_FLAGS);

        expect($card['title'])->toBe('Age and support')
            ->and(array_column($card['rows'], 'value', 'label'))->toBe([
                'Model' => 'MacBook Air',
                'First released' => '2015',
                'Operating system' => 'macOS 10.14.6',
                'Security support' => 'Out of security support',
                'Newest macOS this Mac can run' => 'macOS 12 Monterey',
            ])
            ->and(toneOf($card, 'Security support'))->toBe('bad');
    });

    it('says nothing about a year for a model it does not know', function () {
        $markdown = withBlock(macReportFixture(), 'MacBookAir7,2', 'MacBookAir99,9');

        expect(detailRows($markdown)['age'])->toHaveKey('Model', 'MacBook Air')->not->toHaveKey('First released');
    });

    it('says nothing about a year for a model without an identifier', function () {
        $markdown = withBlock(macReportFixture(), 'MacBook Air (MacBookAir7,2)', 'Hackintosh');

        expect(detailRows($markdown)['age'])->toHaveKey('Model', 'Hackintosh')->not->toHaveKey('First released');
    });

    it('falls back to the whole model text when only an identifier is given', function () {
        $markdown = withBlock(macReportFixture(), 'MacBook Air (MacBookAir7,2)', '(MacBookAir7,2)');

        expect(detailRows($markdown)['age'])->toHaveKey('Model', '(MacBookAir7,2)')->toHaveKey('First released', '2015');
    });

    it('does not flag support when the scan did not', function () {
        $card = detailCard('age', macReportFixture(), []);

        expect(toneOf($card, 'Security support'))->toBe('good')
            ->and(array_column($card['rows'], 'value', 'label'))->toHaveKey('Security support', 'No support problem found');
    });

    it('reads the macOS version from the version section of an older report', function () {
        $markdown = "# Mac checkup: Old\n\n- Model: iMac (iMac14,2)\n\n## macOS version\n\n```\nProductName:\tmacOS\nProductVersion:\t12.7.4\n```\n";

        expect(detailRows($markdown, [])['age'])->toBe([
            'Model' => 'iMac',
            'First released' => '2013',
            'Operating system' => 'macOS 12.7.4',
            'Security support' => 'No support problem found',
        ]);
    });

    it('describes a Windows PC the way its own report words it', function () {
        $card = detailCard('age', windowsLaptopFixture(), ['Windows 10 Pro reached end of support on 14 October 2025, so it only gets updates with a paid extension.']);

        expect(array_column($card['rows'], 'value', 'label'))->toBe([
            'Model' => 'Contoso Voyager 14',
            'Operating system' => 'Windows 10 Pro 22H2 (build 19045.5000)',
            'Security support' => 'Out of security support',
        ]);
    });

    it('uses the singular for one day in the updates card', function () {
        $markdown = withBlock(macReportFixture(), '(1744 days ago)', '(1 day ago)');

        expect(detailRows($markdown)['updates'])->toHaveKey('Last update installed', '24 January 2022, 1 day before the scan');
    });

    it('leaves the updates card out when the date or the section is unusable', function (string $line) {
        $markdown = withBlock(macReportFixture(), 'Newest installed macOS update: 2022-01-24 (1744 days ago)', $line);

        expect(detailRows($markdown))->not->toHaveKey('updates');
    })->with([
        'cannot check' => 'Newest installed macOS update: could not check (no readable update history)',
        'impossible date' => 'Newest installed macOS update: 2022-13-45 (5 days ago)',
        'not a date' => 'Newest installed macOS update: sometime',
    ]);

    it('leaves the updates card out when the report has no update section', function () {
        $markdown = substr(macReportFixture(), 0, (int) strpos(macReportFixture(), '## Software update age'));

        expect(detailRows($markdown))->not->toHaveKey('updates');
    });

    it('never turns markup into anything but text', function () {
        $markdown = withBlock(macReportFixture(), 'MacBook Air (MacBookAir7,2)', '<script>alert(1)</script> (MacBookAir7,2)');

        expect(detailRows($markdown)['age']['Model'])->toBe('<script>alert(1)</script>');
    });
});

describe('storage', function () {
    it('reports a Mac drive', function () {
        $card = detailCard('storage', macReportFixture());

        expect(array_column($card['rows'], 'value', 'label'))->toBe([
            'Free' => '14 GB (12%)',
            'Used' => '99 GB of 113 GB',
            'Health' => 'Healthy',
            'Drive type' => 'Solid state drive',
        ])
            ->and(toneOf($card, 'Health'))->toBe('good')
            ->and($card['list'])->toBe([
                'title' => 'Biggest folders',
                'items' => [
                    ['label' => 'Library', 'value' => '38 GB', 'tone' => 'neutral'],
                    ['label' => 'Movies', 'value' => '21 GB', 'tone' => 'neutral'],
                    ['label' => 'Documents', 'value' => '9.5 GB', 'tone' => 'neutral'],
                    ['label' => 'Library/Caches', 'value' => '2.1 GB', 'tone' => 'neutral'],
                    ['label' => 'Downloads', 'value' => '780 MB', 'tone' => 'neutral'],
                ],
            ]);
    });

    it('takes the free percentage from the free disk fact and never computes its own', function () {
        $markdown = withBlock(macReportFixture(), '- Free disk: 12%', '- Free disk: 40%');

        expect(detailRows($markdown)['storage']['Free'])->toBe('14 GB (40%)');
    });

    it('leaves the percentage out when the fact is not a number', function () {
        $markdown = withBlock(macReportFixture(), '- Free disk: 12%', '- Free disk: n/a%');

        expect(detailRows($markdown)['storage']['Free'])->toBe('14 GB');
    });

    it('shows only the percentage when there is no disk table', function () {
        $markdown = "# Mac checkup: Old\n\n- Free disk: 12%\n";

        expect(detailRows($markdown))->toBe(['storage' => ['Free' => '12%'], 'slowness' => ['Verdict' => 'Nothing obviously slowing it down']]);
    });

    it('reads terabytes, megabytes, kilobytes and decimals from df', function () {
        $df = "Filesystem Size Used Avail Capacity iused ifree %iused Mounted on\n/dev/disk3s1s1 1.8Ti 4.5Mi 512Mi 4% 426k 3.0G 0% /\n";
        $markdown = "# Mac checkup\n\n- Free disk: 28%\n\n## Disk usage\n\n```\n{$df}```\n";

        expect(detailRows($markdown)['storage'])->toBe(['Free' => '512 MB (28%)', 'Used' => '1.8 TB of 1.8 TB']);

        $small = "# Mac checkup\n\n## Disk usage\n\n```\n/dev/x 4.0Ki 3.0Ki 1.0Ki 75% /\n```\n";

        expect(detailRows($small)['storage'])->toBe(['Free' => '1 KB', 'Used' => '3 KB of 4 KB']);
    });

    it('formats a drive with nothing free', function () {
        $full = "# Mac checkup\n\n## Disk usage\n\n```\n/dev/x 10Gi 10Gi 0B 100% /\n```\n";

        expect(detailRows($full)['storage'])->toBe(['Free' => '0 B', 'Used' => '10 GB of 10 GB']);
    });

    it('shows nothing for a df line without usable sizes', function () {
        $broken = "# Mac checkup\n\n## Disk usage\n\n```\n/dev/x n/a n/a n/a 100% /\n```\n";

        expect(detailRows($broken))->toBe([]);
    });

    it('ignores df lines for other mounts', function () {
        $markdown = "# Mac checkup\n\n## Disk usage\n\n```\ndevfs 190Ki 190Ki 0Bi 100% 657 0 100% /dev\n```\n";

        expect(detailRows($markdown))->toBe([]);
    });

    it('never reports used space below zero', function () {
        $markdown = "# Mac checkup\n\n## Disk usage\n\n```\n/dev/x 10Gi 1Gi 20Gi 5% /\n```\n";

        expect(detailRows($markdown)['storage']['Used'])->toBe('0 B of 10 GB');
    });

    it('explains a drive that reports a problem or no status', function (string $smart, string $expected, string $tone) {
        $markdown = withBlock(macReportFixture(), 'SMART Status:              Verified', "SMART Status:              {$smart}");
        $card = detailCard('storage', $markdown);

        expect(array_column($card['rows'], 'value', 'label')['Health'])->toBe($expected)
            ->and(toneOf($card, 'Health'))->toBe($tone);
    })->with([
        'failing' => ['Failing', 'Problem reported: Failing', 'bad'],
        'not supported' => ['Not Supported', 'Not reported by this drive', 'neutral'],
    ]);

    it('names a spinning drive and skips an unknown drive type', function () {
        $spinning = withBlock(macReportFixture(), 'Solid State:               Yes', 'Solid State:               No');
        $unknown = withBlock(macReportFixture(), 'Solid State:               Yes', 'Solid State:               Maybe');

        expect(detailRows($spinning)['storage'])->toHaveKey('Drive type', 'Spinning hard drive')
            ->and(detailRows($unknown)['storage'])->not->toHaveKey('Drive type');
    });

    it('shows the drive type alone when the Mac gives no SMART status', function () {
        $markdown = "# Mac checkup\n\n## Storage (boot volume)\n\n```\n   Solid State:               Yes\n```\n";

        expect(detailRows($markdown))->toBe(['storage' => ['Drive type' => 'Solid state drive']]);
    });

    it('reports a Windows drive', function () {
        $card = detailCard('storage', windowsLaptopFixture());

        expect(array_column($card['rows'], 'value', 'label'))->toBe([
            'Free' => '52 GB (22%)',
            'Used' => '185 GB of 237 GB',
            'Health' => 'Healthy',
            'Drive type' => 'Solid state drive',
        ])->and($card['list'])->toBeNull();
    });

    it('falls back to the first Windows volume when there is no C drive', function () {
        $markdown = withBlock(windowsLaptopFixture(), 'C:       OS         NTFS          237     52    22', 'E:       OS         NTFS          100     10    10');

        expect(detailRows($markdown)['storage']['Free'])->toBe('10 GB (22%)');
    });

    it('describes Windows disks that are unhealthy, spinning or mixed', function () {
        $sick = withBlock(windowsLaptopFixture(), 'SSD       NVMe       238 Healthy      OK', 'HDD       SATA       238 Warning      OK');
        $mixed = withBlock(windowsLaptopFixture(), "Contoso NVMe 256GB   SSD       NVMe       238 Healthy      OK\n", "Contoso NVMe 256GB   SSD       NVMe       238 Healthy      OK\nOld Disk             HDD       SATA       931 Healthy      OK\n");
        $unspecified = withBlock(windowsLaptopFixture(), 'SSD       NVMe       238 Healthy      OK', 'Unspecified NVMe     238 Healthy      OK');

        expect(detailRows($sick)['storage'])->toHaveKey('Health', 'Problem reported: Warning')->toHaveKey('Drive type', 'Spinning hard drive')
            ->and(detailRows($mixed)['storage'])->toHaveKey('Health', 'Healthy')->toHaveKey('Drive type', 'Both solid state and spinning')
            ->and(detailRows($unspecified)['storage'])->not->toHaveKey('Drive type');
    });

    it('leaves the folder list out when there is none', function () {
        $markdown = withBlock(macReportFixture(), '## Performance: biggest folders in home', '## Something else');

        expect(detailCard('storage', $markdown)['list'])->toBeNull();
    });

    it('keeps only five folders and skips lines that are not sizes', function () {
        $lines = ['junk line', "3G\t/Users/me/A", "2G\t/opt/B", "1G\t/Users/me/C", "1G\t/Users/me/D", "1G\t/Users/me/E", "1G\t/Users/me/F"];
        $markdown = "# Mac checkup\n\n## Performance: biggest folders in home\n\n```\n".implode("\n", $lines)."\n```\n";
        $items = detailCard('storage', $markdown)['list']['items'];

        expect(array_column($items, 'label'))->toBe(['A', 'B', 'C', 'D', 'E']);
    });

    it('shows no card when the folder section holds nothing readable', function () {
        $markdown = "# Mac checkup\n\n## Performance: biggest folders in home\n\n```\n(capped at about 15 seconds)\n```\n";

        expect(detailCard('storage', $markdown))->toBeNull();
    });
});

describe('battery', function () {
    it('reports a Mac battery', function () {
        $card = detailCard('battery', macReportFixture());

        expect($card['title'])->toBe('Battery')
            ->and(array_column($card['rows'], 'value', 'label'))->toBe([
                'Charge cycles' => '391',
                'Condition' => 'Normal',
                'Maximum capacity' => 'Not reported',
            ])
            ->and(toneOf($card, 'Condition'))->toBe('good');
    });

    it('shows the capacity when the Mac reports it', function () {
        $markdown = withBlock(macReportFixture(), 'max capacity n/a', 'max capacity 87%');

        expect(detailRows($markdown)['battery'])->toHaveKey('Maximum capacity', '87% of when it was new');
    });

    it('maps conditions to plain words and colours', function (string $condition, string $words, string $tone) {
        $markdown = withBlock(macReportFixture(), 'condition Normal', "condition {$condition}");
        $card = detailCard('battery', $markdown);

        expect(array_column($card['rows'], 'value', 'label')['Condition'])->toBe($words)
            ->and(toneOf($card, 'Condition'))->toBe($tone);
    })->with([
        'service' => ['Service Recommended', 'Service recommended, the battery is worn', 'bad'],
        'poor' => ['Poor', 'Poor, replace the battery', 'bad'],
        'replace soon' => ['Replace Soon', 'Replace soon', 'bad'],
        'replace now' => ['Replace Now', 'Replace now', 'bad'],
        'unknown' => ['Odd State', 'Odd State', 'neutral'],
        'not reported' => ['n/a', 'Not reported', 'neutral'],
    ]);

    it('cuts a ridiculously long condition short', function () {
        $markdown = withBlock(macReportFixture(), 'condition Normal', 'condition '.str_repeat('x', 500));

        expect(strlen(detailRows($markdown)['battery']['Condition']))->toBeLessThanOrEqual(80);
    });

    it('reports a Windows battery', function () {
        expect(detailRows(windowsLaptopFixture())['battery'])->toBe([
            'Charge cycles' => '312',
            'Maximum capacity' => '85% of when it was new',
        ]);
    });

    it('says not reported for a Windows battery with no numbers', function () {
        $markdown = withBlock(windowsLaptopFixture(), '312 cycles, 85% of design capacity', 'n/a cycles, n/a');

        expect(detailRows($markdown)['battery'])->toBe(['Charge cycles' => 'Not reported', 'Maximum capacity' => 'Not reported']);
    });

    it('hides the battery on a machine that has none', function (string $line) {
        $markdown = withBlock(windowsLaptopFixture(), '- Battery: 312 cycles, 85% of design capacity', $line);

        expect(detailRows($markdown, [], 'laptop'))->not->toHaveKey('battery');
    })->with(['none' => '- Battery: none', 'unreadable' => '- Battery: broken', 'missing' => '- Other: thing']);

    it('hides a Mac desktop that prints an empty battery line', function () {
        $markdown = withBlock(macReportFixture(), '391 cycles, condition Normal, max capacity n/a', 'n/a cycles, condition n/a, max capacity n/a');

        expect(detailRows($markdown, [], 'desktop'))->not->toHaveKey('battery')
            ->and(detailRows($markdown, [], null))->not->toHaveKey('battery')
            ->and(detailRows($markdown, [], 'laptop')['battery'])->toBe(['Charge cycles' => 'Not reported', 'Condition' => 'Not reported', 'Maximum capacity' => 'Not reported']);
    });

    it('shows the battery of a desktop that reports real data', function () {
        expect(detailRows(macReportFixture(), [], 'desktop'))->toHaveKey('battery');
    });
});

it('returns nothing for a report without any of the data', function (string $markdown) {
    expect((new ReportDetails(new ReportFacts))->cards($markdown, [], 'laptop'))->toBe([]);
})->with([
    'empty' => '',
    'title only' => "# Report\n",
    'sections without data' => "# Report\n\n## Disk usage\n\n```\n```\n\n## Storage (boot volume)\n\n```\n```\n",
]);

it('reads a report with Windows line endings', function () {
    $lf = detailRows(macReportFixture(), MAC_FLAGS);
    $crlf = detailRows(str_replace("\n", "\r\n", macReportFixture()), MAC_FLAGS);

    expect($crlf)->toBe($lf);
});

it('is not fooled by a heading inside a code fence', function () {
    $markdown = withBlock(macReportFixture(), "Volume Name:               Macintosh HD\n", "Volume Name:               Macintosh HD\n## Disk usage\n");

    expect(detailRows($markdown)['storage'])->toHaveKey('Free', '14 GB (12%)');
});

it('uses the first of two sections with the same heading', function () {
    $markdown = macReportFixture()."\n## Disk usage\n\n```\n/dev/other 1Gi 1Gi 0B 100% /\n```\n";

    expect(detailRows($markdown)['storage'])->toHaveKey('Used', '99 GB of 113 GB');
});
