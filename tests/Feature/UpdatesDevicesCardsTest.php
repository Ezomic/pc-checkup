<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Machine;
use App\Models\Report;
use App\Models\User;
use App\Services\Reports\ReportDetails;
use App\Services\Reports\ReportUpdates;
use Carbon\CarbonImmutable;

use function Pest\Laravel\actingAs;

beforeEach(fn () => CarbonImmutable::setTestNow('2026-11-06 12:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

function udSection(string $title, string $body): string
{
    return "\n## {$title}\n\n```\n{$body}\n```\n";
}

/**
 * @return array<string, mixed>|null
 */
function udCard(string $markdown, string $id): ?array
{
    return array_column(app(ReportDetails::class)->cards($markdown, [], 'laptop'), null, 'id')[$id] ?? null;
}

function udWindows(string $update, string $restart = 'no', string $renames = 'no'): string
{
    return "# Windows checkup: PC\n\n- Format: 1\n- Windows: Windows 11 Pro 24H2 (build 26100.1000)\n"
        .udSection('Windows Update', "Newest installed update: {$update}\nRestart pending: {$restart}\nPending file rename operations (often harmless): {$renames}\n\nPending updates:\n(none)");
}

/**
 * @return array<string, array{value: string, tone: string}>
 */
function udRows(?array $card): array
{
    $rows = [];

    foreach ($card['rows'] ?? [] as $row) {
        $rows[$row['label']] = ['value' => $row['value'], 'tone' => $row['tone']];
    }

    return $rows;
}

function udDevices(string $body): ?array
{
    return udCard(windowsLaptopFixture().udSection('Devices with problems (Device Manager)', $body), 'device-problems');
}

describe('the updates card', function () {
    it('shows a recent update and no restart as good', function () {
        $card = udCard(udWindows('2026-10-20 (17 days ago)'), 'updates');

        expect($card['title'])->toBe('Updates')
            ->and(udRows($card))->toBe([
                'Last update installed' => ['value' => '20 October 2026, 17 days before the scan', 'tone' => 'good'],
                'Restart pending' => ['value' => 'No', 'tone' => 'good'],
            ])
            ->and($card['list'])->toBeNull();
    });

    it('marks an update older than the limit as bad with advice, and exactly the limit as good', function () {
        $old = udRows(udCard(udWindows('2026-08-01 (97 days ago)'), 'updates'));
        $edge = udRows(udCard(udWindows('2026-09-07 ('.ReportUpdates::STALE_AFTER_DAYS.' days ago)'), 'updates'));

        expect($old['Last update installed'])->toBe(['value' => '1 August 2026, 97 days before the scan. Check for updates and install them.', 'tone' => 'bad'])
            ->and($edge['Last update installed']['tone'])->toBe('good');
    });

    it('uses the singular for one day', function () {
        expect(udRows(udCard(udWindows('2026-11-05 (1 day ago)'), 'updates'))['Last update installed']['value'])->toBe('5 November 2026, 1 day before the scan');
    });

    it('says a restart is pending in plain words', function () {
        $rows = udRows(udCard(udWindows('2026-10-20 (17 days ago)', 'Windows Update is waiting for a restart; component servicing is waiting for a restart'), 'updates'));

        expect($rows['Restart pending'])->toBe(['value' => 'Yes, to finish installing updates. Restart the computer.', 'tone' => 'bad']);
    });

    it('does not call files waiting for a restart a pending update restart', function () {
        $rows = udRows(udCard(udWindows('2026-10-20 (17 days ago)', 'no', 'yes'), 'updates'));

        expect($rows['Restart pending'])->toBe(['value' => 'No, but some files will be replaced at the next restart', 'tone' => 'neutral']);
    });

    it('hides a restart line it cannot read', function (string $restart) {
        expect(udRows(udCard(udWindows('2026-10-20 (17 days ago)', $restart), 'updates')))->not->toHaveKey('Restart pending');
    })->with(['empty' => '', 'unknown' => 'maybe', 'n/a' => 'n/a']);

    it('hides the date when it cannot be trusted but keeps the restart row', function (string $update) {
        $rows = udRows(udCard(udWindows($update, 'Windows Update is waiting for a restart'), 'updates'));

        expect($rows)->not->toHaveKey('Last update installed')
            ->and($rows)->toHaveKey('Restart pending');
    })->with([
        'unknown' => 'unknown (no install date could be read)',
        'impossible date' => '2026-02-30 (10 days ago)',
        'negative days' => '2026-12-01 (-25 days ago)',
        'huge days' => '1601-01-01 (155000 days ago)',
        'not a date' => 'last week',
        'locale comma' => '2026-10-20 (17,5 days ago)',
    ]);

    it('is hidden when nothing can be read', function () {
        expect(udCard(udWindows('unknown (no install date could be read)', 'perhaps'), 'updates'))->toBeNull()
            ->and(udCard("# Windows checkup: PC\n\n- Format: 1\n", 'updates'))->toBeNull()
            ->and(udCard('', 'updates'))->toBeNull();
    });

    it('reads the Mac update history, which has no restart line', function () {
        $card = udCard(macReportFixture(), 'updates');

        expect(udRows($card))->toBe([
            'Last update installed' => ['value' => '24 January 2022, 1744 days before the scan. Check for updates and install them.', 'tone' => 'bad'],
        ]);
    });

    it('is hidden on a Mac whose update history could not be read', function () {
        $markdown = str_replace('Newest installed macOS update: 2022-01-24 (1744 days ago)', 'Newest installed macOS update: could not check (no readable update history)', macReportFixture());

        expect(udCard($markdown, 'updates'))->toBeNull();
    });

    it('reads an older Windows report the same way', function () {
        expect(udRows(udCard(windowsLaptopFixture(), 'updates')))->toBe([
            'Last update installed' => ['value' => '20 October 2026, 14 days before the scan', 'tone' => 'good'],
            'Restart pending' => ['value' => 'No', 'tone' => 'good'],
        ]);
    });

    it('is no longer repeated in the age card', function () {
        expect(array_column(udCard(windowsLaptopFixture(), 'age')['rows'] ?? [], 'label'))->not->toContain('Last update installed');
    });
});

describe('the updates card on a macOS that gets no more updates', function () {
    function udOldMac(string $updates = "No new software available.\nSoftware Update Tool\n\nFinding available software", string $model = 'MacBook Air (MacBookAir7,2)', string $macos = '10.14.6 (18G9323)'): string
    {
        $markdown = str_replace(['- Model: MacBook Air (MacBookAir7,2)', '- macOS: 10.14.6 (18G9323)'], ["- Model: {$model}", "- macOS: {$macos}"], macReportFixture());

        return $markdown.udSection('Software updates', $updates);
    }

    function udInstalled(string $markdown): array
    {
        return udRows(udCard($markdown, 'updates'))['Last update installed'];
    }

    it('says no more updates come out and that the newest macOS the Mac can run gets none either', function () {
        expect(udInstalled(udOldMac()))->toBe([
            'value' => '24 January 2022, 1744 days before the scan. No more updates come out for macOS 10.14 Mojave. The newest macOS this Mac can run, macOS 12 Monterey, no longer gets security updates either.',
            'tone' => 'bad',
        ]);
    });

    it('names the newest macOS the Mac can run when Apple still supports it', function () {
        expect(udInstalled(udOldMac(model: 'MacBook Air (MacBookAir8,1)', macos: '12.7.6 (21H1320)')))->toBe([
            'value' => '24 January 2022, 1744 days before the scan. No more updates come out for macOS 12 Monterey. The newest macOS this Mac can run is macOS 14 Sonoma.',
            'tone' => 'bad',
        ]);
    });

    it('says when the Mac already runs the newest macOS it can', function () {
        expect(udInstalled(udOldMac(macos: '12.7.6 (21H1320)')))->toBe([
            'value' => '24 January 2022, 1744 days before the scan. This Mac already runs the newest macOS it can, macOS 12 Monterey, and it no longer gets security updates.',
            'tone' => 'bad',
        ]);
    });

    it('keeps asking to install updates when some are waiting', function (string $updates) {
        expect(udInstalled(udOldMac($updates))['value'])->toEndWith('Check for updates and install them.');
    })->with([
        'Mojave style list' => "Software Update Tool\n\nFinding available software\nSoftware Update found the following new or updated software:\n   * Security Update 2021-005-10.14.6\n\tSecurity Update 2021-005 (10.14.6), 1626783K [recommended] [restart]",
        'label style list' => "Software Update Tool\n\nFinding available software\nSoftware Update found the following new or updated software:\n* Label: Safari15.6.1-15.6.1\n\tTitle: Safari, Version: 15.6.1, Size: 99000KiB, Recommended: YES,",
    ]);

    it('keeps today\'s wording when it cannot tell', function (string $markdown) {
        expect(udInstalled($markdown)['value'])->toBe('24 January 2022, 1744 days before the scan. Check for updates and install them.');
    })->with([
        'no software updates section' => fn () => macReportFixture(),
        'the check did not finish' => fn () => udOldMac("Software Update Tool\n\nFinding available software"),
        'an unknown model' => fn () => udOldMac(model: 'MacBook Air (MacBookAir99,9)'),
        'a model without identifier' => fn () => udOldMac(model: 'MacBook Air'),
        'a macOS that still gets updates' => fn () => udOldMac(model: 'MacBook Air (MacBookAir9,1)', macos: '14.7.1 (23H222)'),
        'a macOS newer than the model allows' => fn () => udOldMac(macos: '13.6.1 (22G313)'),
    ]);

    it('adds nothing to a recent update', function () {
        $markdown = str_replace('2022-01-24 (1744 days ago)', '2026-10-20 (17 days ago)', udOldMac());

        expect(udInstalled($markdown))->toBe(['value' => '20 October 2026, 17 days before the scan', 'tone' => 'good']);
    });

    it('reads an old report that has the version only in the macOS version section', function () {
        $markdown = str_replace("- macOS: 10.14.6 (18G9323)\n", '', udOldMac());

        expect(udInstalled($markdown)['value'])->toEndWith('No more updates come out for macOS 10.14 Mojave. The newest macOS this Mac can run, macOS 12 Monterey, no longer gets security updates either.');
    });

    it('leaves Windows alone even with a Software updates section', function () {
        $markdown = udWindows('2026-08-01 (97 days ago)').udSection('Software updates', 'No new software available.');

        expect(udInstalled($markdown)['value'])->toBe('1 August 2026, 97 days before the scan. Check for updates and install them.');
    });
});

describe('the devices card', function () {
    it('lists each device with what its problem code means', function () {
        $card = udDevices("format: 1\nscan: complete\ntotal: 2\ndevice|Intel(R) Wireless Bluetooth(R)|43\ndevice|Unknown device|28");

        expect($card['title'])->toBe('Devices with problems')
            ->and($card['summary'])->toBe('2 devices report a problem in Device Manager')
            ->and($card['rows'])->toBe([
                ['label' => 'Intel(R) Wireless Bluetooth(R)', 'value' => 'Windows stopped it because it reported problems. Update or reinstall its driver.', 'tone' => 'bad'],
                ['label' => 'Unknown device', 'value' => 'No driver is installed. Install the driver from the maker of the computer or device.', 'tone' => 'bad'],
            ])
            ->and($card['notes'])->toContain('Only you see this: it is not on the pages you share with clients.');
    });

    it('says so when every device works', function () {
        $card = udDevices("format: 1\nscan: complete\ntotal: 0");

        expect($card['summary'])->toBe('No device problems found in Device Manager')
            ->and($card['rows'])->toBe([]);
    });

    it('uses the singular for one device', function () {
        expect(udDevices("format: 1\nscan: complete\ntotal: 1\ndevice|Camera|10")['summary'])->toBe('1 device reports a problem in Device Manager');
    });

    it('gives an unknown code by its number', function () {
        expect(udDevices("format: 1\nscan: complete\ntotal: 1\ndevice|Odd thing|77")['rows'][0]['value'])->toBe('Problem code 77 in Device Manager');
    });

    it('drops rows that are not a device name and a real problem code', function (string $line) {
        expect(udDevices("format: 1\nscan: complete\ntotal: 0\n{$line}")['rows'])->toBe([]);
    })->with([
        'code 0' => 'device|Fine|0',
        'disabled' => 'device|Off on purpose|22',
        'not present' => 'device|Unplugged|24',
        'not connected' => 'device|Old USB stick|45',
        'negative' => 'device|Odd|-3',
        'decimal' => 'device|Odd|4,5',
        'huge' => 'device|Odd|99999999999',
        'no name' => 'device||28',
        'only slashes' => 'device|//|28',
        'too many parts' => 'device|A|B|28',
        'not a device' => 'app|chrome.exe|3',
    ]);

    it('keeps the ten first devices and says more were found', function () {
        $lines = implode("\n", array_map(fn (int $i): string => "device|Device {$i}|28", range(1, 14)));
        $card = udDevices("format: 1\nscan: complete\ntotal: 14\n{$lines}");

        expect($card['rows'])->toHaveCount(10)
            ->and($card['summary'])->toBe('14 devices report a problem in Device Manager')
            ->and($card['notes'])->toContain('Only the first ten are listed.');
    });

    it('does not believe a device count it cannot trust', function (string $total) {
        expect(udDevices("format: 1\nscan: complete\ntotal: {$total}\ndevice|Camera|10")['summary'])->toBe('1 device reports a problem in Device Manager');
    })->with(['huge' => '99999999999999999999', 'negative' => '-4', 'decimal comma' => '2,5', 'words' => 'many']);

    it('never claims fewer devices than it lists', function () {
        expect(udDevices("format: 1\nscan: complete\ntotal: 0\ndevice|Camera|10\ndevice|Mic|10")['summary'])->toBe('2 devices report a problem in Device Manager');
    });

    it('cleans names of control characters, pipes and length', function () {
        $card = udDevices("format: 1\nscan: complete\ntotal: 1\ndevice|Caf\u{e9}\u{7}\u{202E} cam ".str_repeat('x', 200).'|10');

        expect($card['rows'][0]['label'])->toStartWith("Caf\u{e9} cam x")
            ->and(mb_strlen($card['rows'][0]['label']))->toBeLessThanOrEqual(60);
    });

    it('does not break on invalid UTF-8', function () {
        expect(udDevices("format: 1\nscan: complete\ntotal: 1\ndevice|Bad \xC3\x28 name|10")['rows'][0]['label'])->toBeString();
    });

    it('is hidden when the devices could not be read or the report is from before this card', function (string $body) {
        expect(udDevices($body))->toBeNull();
    })->with([
        'could not check' => "format: 1\nscan: could not check",
        'no scan line' => "format: 1\ndevice|Camera|10",
        'old table' => "Name          DeviceID              ConfigManagerErrorCode\n----          --------              ----------------------\nCamera        USB\\VID_1234\\ABC123   10",
        'old none' => '(none)',
        'empty' => '',
    ]);

    it('is hidden on a Mac', function () {
        expect(udCard(macReportFixture(), 'device-problems'))->toBeNull();
    });
});

describe('needs attention', function () {
    /**
     * @return list<string>
     */
    function udReasons(string $markdown): array
    {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create(['created_at' => CarbonImmutable::now()->subYear()]);
        Report::factory()->for($machine)->create(['markdown' => $markdown, 'flags' => [], 'created_at' => CarbonImmutable::now()->subDay()]);
        $found = [];

        actingAs($user)->get('/machines?attention=all')->assertOk()->assertInertia(function ($page) use (&$found): void {
            $found = $page->toArray()['props']['needsAttention']['rows'][0]['reasons'] ?? [];
        });

        return array_map(fn (array $reason): string => "{$reason['level']}: {$reason['text']}", $found);
    }

    it('flags an old update and a pending restart as soon', function () {
        expect(udReasons(udWindows('2026-08-01 (97 days ago)', 'Windows Update is waiting for a restart')))->toBe([
            'soon: No update installed for more than 60 days',
            'soon: A restart is waiting to finish installing updates',
        ]);
    });

    it('stays quiet for a fresh update, files waiting on a restart, or unreadable data', function (string $markdown) {
        expect(udReasons($markdown))->toBe([]);
    })->with([
        'fresh' => fn () => udWindows('2026-10-20 (17 days ago)'),
        'renames only' => fn () => udWindows('2026-10-20 (17 days ago)', 'no', 'yes'),
        'unknown' => fn () => udWindows('unknown (no install date could be read)', 'maybe'),
    ]);
});

describe('who sees the cards', function () {
    function udMarkdown(): string
    {
        return udWindows('2026-08-01 (97 days ago)', 'Windows Update is waiting for a restart')
            .udSection('Devices with problems (Device Manager)', "format: 1\nscan: complete\ntotal: 1\ndevice|SecretBluetoothThing|43");
    }

    it('gives the owner both cards in the camelCase detailCards prop', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create(['device_type' => 'laptop']);
        Report::factory()->for($machine)->create(['markdown' => udMarkdown()]);
        $ids = [];

        actingAs($user)->get("/machines/{$machine->id}")->assertOk()->assertInertia(function ($page) use (&$ids): void {
            $ids = array_column($page->toArray()['props']['detailCards'], 'id');
        });

        expect($ids)->toContain('updates', 'device-problems');
    });

    it('never gives the cards or device names to a share page', function () {
        $client = Client::factory()->withShareLink()->create();
        $machine = Machine::factory()->for($client->user)->create(['client_id' => $client->id, 'device_type' => 'laptop']);
        Report::factory()->for($machine)->create(['markdown' => udMarkdown()]);

        foreach (["/c/{$client->share_token}", "/c/{$client->share_token}/machines/{$machine->id}"] as $path) {
            $response = $this->get($path)->assertOk();
            $props = '';
            $response->assertInertia(function ($page) use (&$props): void {
                $props = (string) json_encode($page->toArray()['props']);
            });

            foreach ([$props, (string) $response->getContent()] as $body) {
                foreach (['SecretBluetoothThing', 'Devices with problems', 'device-problems', 'Restart pending', 'Last update installed', 'detailCards'] as $hidden) {
                    expect($body)->not->toContain($hidden);
                }
            }
        }
    });

    it('does not show one account the cards of another', function () {
        $mine = User::factory()->create();
        $theirs = Machine::factory()->for(User::factory()->create())->create(['device_type' => 'laptop']);
        Report::factory()->for($theirs)->create(['markdown' => udMarkdown()]);

        actingAs($mine)->get("/machines/{$theirs->id}")->assertForbidden();
    });
});
