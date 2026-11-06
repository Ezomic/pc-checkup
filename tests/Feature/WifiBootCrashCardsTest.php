<?php

declare(strict_types=1);

use App\Actions\Machines\ImportReport;
use App\Models\Client;
use App\Models\Machine;
use App\Models\Report;
use App\Models\User;
use App\Services\Reports\ReportDetails;
use App\Services\Reports\ReportUserMasker;
use Carbon\CarbonImmutable;

use function Pest\Laravel\actingAs;

function wbcSection(string $title, string $body): string
{
    return "\n## {$title}\n\n```\n{$body}\n```\n";
}

function wbcCard(string $markdown, string $id): ?array
{
    return array_column(app(ReportDetails::class)->cards($markdown, [], 'laptop'), null, 'id')[$id] ?? null;
}

function wbcWifi(string $body): ?array
{
    return wbcCard(windowsLaptopFixture().wbcSection('Wi-Fi signal', $body), 'wifi');
}

function wbcBoot(string $body): ?array
{
    return wbcCard(windowsLaptopFixture().wbcSection('Boot time', $body), 'boot-time');
}

function wbcCrashes(string $body): ?array
{
    return wbcCard(windowsLaptopFixture().wbcSection('Recent app crashes', $body), 'crashing-apps');
}

function wbcCrashBody(string $apps, string $scan = 'complete', string $total = '9', string $days = '30'): string
{
    return "format: 1\nscanned: 2026-11-06\nwindow-days: {$days}\nscan: {$scan}\ntotal: {$total}\n{$apps}";
}

describe('the Wi-Fi card', function () {
    it('shows strength and band from a Windows percentage', function () {
        $card = wbcWifi("format: 1\nsignal-percent: 82\nband: 5 GHz");

        expect($card['title'])->toBe('Wi-Fi connection')
            ->and($card['rows'])->toBe([
                ['label' => 'Signal', 'value' => 'Strong (82%)', 'tone' => 'good'],
                ['label' => 'Band', 'value' => '5 GHz', 'tone' => 'neutral'],
            ])
            ->and($card['list'])->toBeNull();
    });

    it('turns a Mac dBm reading into the same buckets', function (string $dbm, string $value, string $tone) {
        $card = wbcWifi("format: 1\nsignal-dbm: {$dbm}\nband: 2.4 GHz");

        expect($card['rows'][0])->toBe(['label' => 'Signal', 'value' => $value, 'tone' => $tone]);
    })->with([
        ['-30', 'Strong (-30 dBm)', 'good'],
        ['-65', 'Strong (-65 dBm)', 'good'],
        ['-66', 'Fair (-66 dBm)', 'neutral'],
        ['-80', 'Fair (-80 dBm)', 'neutral'],
        ['-81', 'Weak (-81 dBm)', 'bad'],
        ['-119', 'Weak (-119 dBm)', 'bad'],
    ]);

    it('puts the edges of the percentage buckets in the right place', function (string $percent, string $word) {
        expect(wbcWifi("format: 1\nsignal-percent: {$percent}")['rows'][0]['value'])->toBe("{$word} ({$percent}%)");
    })->with([['100', 'Strong'], ['70', 'Strong'], ['69', 'Fair'], ['40', 'Fair'], ['39', 'Weak'], ['0', 'Weak']]);

    it('explains the 2.4 GHz band and a weak signal, and says only the owner sees it', function () {
        $notes = wbcWifi("format: 1\nsignal-percent: 20\nband: 2.4 GHz")['notes'];

        expect(implode(' ', $notes))->toContain('2.4 GHz reaches further')->toContain('Move closer to the router')
            ->toContain('Only you see this')->toContain('no network names, passwords or saved networks');
    });

    it('reads a band with a decimal comma and drops one it does not know', function () {
        expect(wbcWifi("format: 1\nsignal-percent: 80\nband: 2,4 GHz")['rows'][1]['value'])->toBe('2.4 GHz')
            ->and(wbcWifi("format: 1\nsignal-percent: 80\nband: 60 GHz")['rows'])->toHaveCount(1)
            ->and(wbcWifi("format: 1\nsignal-percent: 80\nband: 6 GHz")['rows'][1]['value'])->toBe('6 GHz');
    });

    it('prefers the percentage when both are present', function () {
        expect(wbcWifi("format: 1\nsignal-percent: 90\nsignal-dbm: -90")['rows'][0]['value'])->toBe('Strong (90%)');
    });

    it('is hidden when nothing usable was read', function (string $body) {
        expect(wbcWifi($body))->toBeNull();
    })->with([
        'only the format' => ['format: 1'],
        'not connected' => ["format: 1\nstatus: not connected or could not check"],
        'band without signal' => ["format: 1\nband: 5 GHz"],
        'percent too high' => ["format: 1\nsignal-percent: 101"],
        'percent negative' => ["format: 1\nsignal-percent: -5"],
        'percent text' => ["format: 1\nsignal-percent: abc"],
        'percent with unit' => ["format: 1\nsignal-percent: 80%"],
        'percent decimal' => ["format: 1\nsignal-percent: 80.5"],
        'dbm zero' => ["format: 1\nsignal-dbm: 0"],
        'dbm positive' => ["format: 1\nsignal-dbm: 55"],
        'dbm absurd' => ["format: 1\nsignal-dbm: -500"],
        'dbm text' => ["format: 1\nsignal-dbm: loud"],
        'empty value' => ["format: 1\nsignal-percent:"],
    ]);

    it('is hidden on a report without the section', function () {
        expect(wbcCard(windowsLaptopFixture(), 'wifi'))->toBeNull()->and(wbcCard(macReportFixture(), 'wifi'))->toBeNull();
    });
});

describe('the boot time card', function () {
    $windows = "format: 1\nscanned: 2026-11-06\nboot-seconds: 47\nboot-latest-seconds: 52\nboot-latest: 2026-11-05\nboot-samples: 5";

    it('shows the typical and the latest start from the Windows log', function () use ($windows) {
        $card = wbcBoot($windows);

        expect($card['title'])->toBe('Startup time')
            ->and($card['rows'])->toBe([
                ['label' => 'Typical startup', 'value' => '47 sec', 'tone' => 'good'],
                ['label' => 'Latest startup', 'value' => '52 sec (2026-11-05)', 'tone' => 'neutral'],
                ['label' => 'Based on', 'value' => 'the last 5 startups', 'tone' => 'neutral'],
            ]);
    });

    it('labels a single start as the last startup', function () {
        $card = wbcBoot("format: 1\nboot-seconds: 83\nboot-latest-seconds: 83\nboot-latest: 2026-11-05\nboot-samples: 1");

        expect($card['rows'])->toBe([['label' => 'Last startup', 'value' => '1 min 23 sec (2026-11-05)', 'tone' => 'neutral']]);
    });

    it('sets the tone at the edges', function (string $seconds, string $tone) {
        expect(wbcBoot("format: 1\nboot-seconds: {$seconds}\nboot-latest-seconds: {$seconds}\nboot-samples: 3")['rows'][0]['tone'])->toBe($tone);
    })->with([['60', 'good'], ['61', 'neutral'], ['120', 'neutral'], ['121', 'bad'], ['1', 'good'], ['7200', 'bad']]);

    it('advises on a slow start and says only the owner sees it', function () {
        $notes = implode(' ', wbcBoot("format: 1\nboot-seconds: 200\nboot-latest-seconds: 200\nboot-samples: 3")['notes']);

        expect($notes)->toContain('startup programs')->toContain('Only you see this');
    });

    it('leaves out the latest date when it is missing or not a date', function (string $latest) {
        $card = wbcBoot("format: 1\nboot-seconds: 40\nboot-latest-seconds: 50\nboot-samples: 3\nboot-latest: {$latest}");

        expect($card['rows'][1]['value'])->toBe('50 sec');
    })->with(['', 'yesterday', '2026-13-45', '1999-01-01']);

    it('is hidden for a start time it cannot trust', function (string $seconds) {
        expect(wbcBoot("format: 1\nboot-seconds: {$seconds}\nboot-samples: 3"))->toBeNull();
    })->with(['0', '-4', '7201', '99999999999', 'abc', '4,7', '', '1e3']);

    it('is hidden when no start was measured', function () {
        expect(wbcBoot("format: 1\nboot-samples: 0"))->toBeNull()
            ->and(wbcBoot("format: 1\nstatus: could not check"))->toBeNull();
    });

    it('caps the number of starts it claims', function () {
        expect(wbcBoot("format: 1\nboot-seconds: 40\nboot-latest-seconds: 41\nboot-samples: 99999")['rows'][2]['value'])->toBe('the last 10 startups');
    });

    it('shows the last restart of a Mac from the boot time it already scans', function () {
        $card = wbcCard(macReportFixture(), 'boot-time');

        expect($card['rows'])->toBe([['label' => 'Last restarted', 'value' => '2026-10-31', 'tone' => 'neutral']])
            ->and(implode(' ', $card['notes']))->toContain('does not record how long');
    });

    it('is hidden for a Mac boot time that cannot be right', function (string $line) {
        $markdown = (string) preg_replace('/^kern\.boottime:.*$/m', $line, macReportFixture());

        expect(wbcCard($markdown, 'boot-time'))->toBeNull();
    })->with([
        'before 2000' => ['kern.boottime: { sec = 100000000, usec = 0 }'],
        'far future' => ['kern.boottime: { sec = 99999999999, usec = 0 }'],
        'text' => ['kern.boottime: { sec = soon, usec = 0 }'],
        'missing' => ['kern.boottime: unavailable'],
    ]);

    it('is hidden for a Mac boot time in the future of the clock', function () {
        CarbonImmutable::setTestNow('2026-01-01 00:00:00');

        expect(wbcCard(macReportFixture(), 'boot-time'))->toBeNull();

        CarbonImmutable::setTestNow();
    });

    it('is hidden on a report without any boot data', function () {
        expect(wbcCard(windowsLaptopFixture(), 'boot-time'))->toBeNull();
    });
});

describe('the crashing apps card', function () {
    it('lists the apps by count with a summary', function () {
        $card = wbcCrashes(wbcCrashBody("app|chrome.exe|5\napp|Notes|3\napp|zoom.exe|1", total: '9'));

        expect($card['title'])->toBe('Apps that crashed')
            ->and($card['summary'])->toBe('9 crashes in the last 30 days')
            ->and($card['rows'])->toBe([
                ['label' => 'chrome.exe', 'value' => '5 crashes', 'tone' => 'bad'],
                ['label' => 'Notes', 'value' => '3 crashes', 'tone' => 'neutral'],
                ['label' => 'zoom.exe', 'value' => '1 crash', 'tone' => 'neutral'],
            ]);
    });

    it('sorts by count then name, merges names that differ in case and keeps the five biggest', function () {
        $card = wbcCrashes(wbcCrashBody("app|b|2\napp|a|2\napp|Zed|1\napp|zed|1\napp|c|4\napp|d|3\napp|e|1\napp|f|1", total: '15'));

        expect(array_column($card['rows'], 'label'))->toBe(['c', 'd', 'a', 'b', 'Zed'])
            ->and($card['rows'][4]['value'])->toBe('2 crashes')
            ->and(implode(' ', $card['notes']))->toContain('five apps');
    });

    it('says so when nothing crashed', function () {
        $card = wbcCrashes(wbcCrashBody('', total: '0'));

        expect($card['summary'])->toBe('No app crashes in the last 30 days')->and($card['rows'])->toBe([]);
    });

    it('uses the number of days the scan looked back', function () {
        expect(wbcCrashes(wbcCrashBody('app|a|1', total: '1', days: '14'))['summary'])->toBe('1 crash in the last 14 days');
    });

    it('never claims fewer crashes than it lists', function () {
        expect(wbcCrashes(wbcCrashBody("app|a|4\napp|b|3", total: '2'))['summary'])->toBe('7 crashes in the last 30 days')
            ->and(wbcCrashes(wbcCrashBody('app|a|4', total: 'many'))['summary'])->toBe('4 crashes in the last 30 days');
    });

    it('flags a partial scan', function () {
        $notes = implode(' ', wbcCrashes(wbcCrashBody('app|a|2', scan: 'partial (time limit of 20 seconds reached)', total: '2'))['notes']);

        expect($notes)->toContain('time limit')->toContain('Only you see this');
    });

    it('does not say nothing crashed after a partial scan', function () {
        expect(wbcCrashes(wbcCrashBody('', scan: 'partial (time limit of 20 seconds reached)', total: '0')))->toBeNull();
    });

    it('is hidden when the logs could not be read', function () {
        expect(wbcCrashes(wbcCrashBody('', scan: 'could not check', total: '0')))->toBeNull()
            ->and(wbcCrashes("format: 1\nscan: could not check"))->toBeNull()
            ->and(wbcCrashes(''))->toBeNull()
            ->and(wbcCard(windowsLaptopFixture(), 'crashing-apps'))->toBeNull();
    });

    it('is hidden for a look-back it cannot trust', function (string $days) {
        expect(wbcCrashes(wbcCrashBody('app|a|1', days: $days)))->toBeNull();
    })->with(['0', '-1', '366', 'abc', '']);

    it('drops lines that are not a name and a sane count', function (string $line) {
        $card = wbcCrashes(wbcCrashBody("app|Good|2\n{$line}", total: '2'));

        expect(array_column($card['rows'], 'label'))->toBe(['Good']);
    })->with([
        'no count' => ['app|NoCount'],
        'extra field' => ['app|Extra|2|3'],
        'text count' => ['app|Text|many'],
        'negative' => ['app|Neg|-3'],
        'zero' => ['app|Zero|0'],
        'decimal comma' => ['app|Comma|1,5'],
        'huge' => ['app|Huge|9999999999'],
        'empty name' => ['app||4'],
        'dots' => ['app|..|4'],
        'slash only' => ['app|//|4'],
        'other kind' => ['more|Other|4'],
    ]);

    it('cleans names of control characters, slashes, pipes and length', function () {
        $long = str_repeat('L', 120);
        $card = wbcCrashes(wbcCrashBody("app|Tab\tName\x07|2\napp|C:\\\\Users\\\\x\\\\evil.exe|2\napp|{$long}|1\napp|Caf\u{e9} \u{1F4A5}|1", total: '6'));

        expect(array_column($card['rows'], 'label'))->toBe(['C:Usersxevil.exe', 'Tab Name', "Caf\u{e9} \u{1F4A5}", str_repeat('L', 60)]);
    });

    it('reads counts with a thousands mark', function () {
        expect(wbcCrashes(wbcCrashBody('app|a|1.234', total: '1.234'))['rows'][0]['value'])->toBe('1,234 crashes');
    });

    it('does not break on invalid UTF-8', function () {
        $card = wbcCrashes(wbcCrashBody("app|Bad\xC3\x28Name|2", total: '2'));

        expect($card['rows'])->toHaveCount(1)->and(mb_check_encoding($card['rows'][0]['label'], 'UTF-8'))->toBeTrue();
    });
});

describe('who sees the cards', function () {
    function wbcMarkdown(): string
    {
        return windowsLaptopFixture()
            .wbcSection('Wi-Fi signal', "format: 1\nsignal-percent: 61\nband: 5 GHz")
            .wbcSection('Boot time', "format: 1\nboot-seconds: 47\nboot-latest-seconds: 52\nboot-latest: 2026-11-05\nboot-samples: 5")
            .wbcSection('Recent app crashes', wbcCrashBody("app|SecretCrasher.exe|5\napp|Other.exe|2", total: '7'));
    }

    it('gives the owner the three cards in the camelCase detailCards prop', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create(['device_type' => 'laptop']);
        Report::factory()->for($machine)->create(['markdown' => wbcMarkdown()]);
        $ids = [];

        actingAs($user)->get("/machines/{$machine->id}")->assertOk()->assertInertia(function ($page) use (&$ids): void {
            $ids = array_column($page->toArray()['props']['detailCards'], 'id');
        });

        expect(array_slice($ids, -4))->toBe(['wifi', 'boot-time', 'crashing-apps', 'verdict']);
    });

    it('never gives the cards, the names or the raw sections to a share page', function () {
        $client = Client::factory()->withShareLink()->create();
        $machine = Machine::factory()->for($client->user)->create(['client_id' => $client->id, 'device_type' => 'laptop']);
        Report::factory()->for($machine)->create(['markdown' => wbcMarkdown(), 'flags' => ['FileVault is OFF.']]);

        foreach (["/c/{$client->share_token}", "/c/{$client->share_token}/machines/{$machine->id}"] as $path) {
            $response = $this->get($path)->assertOk();
            $props = '';
            $response->assertInertia(function ($page) use (&$props): void {
                $props = (string) json_encode($page->toArray()['props']);
            });

            foreach ([$props, (string) $response->getContent()] as $body) {
                foreach (['SecretCrasher', 'Other.exe', 'Apps that crashed', 'crashing-apps', 'Wi-Fi connection', 'Startup time', 'boot-time', 'signal-percent', 'Recent app crashes', 'detailCards', 'trends'] as $hidden) {
                    expect($body)->not->toContain($hidden);
                }
            }
        }
    });

    it('does not show one account the cards of another', function () {
        $mine = User::factory()->create();
        $theirs = Machine::factory()->for(User::factory()->create())->create(['device_type' => 'laptop']);
        Report::factory()->for($theirs)->create(['markdown' => wbcMarkdown()]);

        actingAs($mine)->get("/machines/{$theirs->id}")->assertForbidden();
    });
});

describe('the startup time trend', function () {
    function wbcHistory(array $seconds, bool $withBoot = true): Machine
    {
        $machine = Machine::factory()->for(User::factory()->create())->create();
        $start = CarbonImmutable::parse('2026-05-01 10:00:00', 'UTC');

        foreach ($seconds as $index => $value) {
            $markdown = trendMarkdown(freeGb: 100.0 - $index)
                .($withBoot && $value !== null ? wbcSection('Boot time', "format: 1\nboot-seconds: {$value}\nboot-latest-seconds: {$value}\nboot-samples: 3") : '');
            Report::factory()->for($machine)->create(['markdown' => $markdown, 'created_at' => $start->addDays($index * 30)]);
        }

        return $machine;
    }

    function wbcTrend(Machine $machine): ?array
    {
        $trends = [];

        actingAs($machine->user)->get("/machines/{$machine->id}")->assertOk()->assertInertia(function ($page) use (&$trends): void {
            $trends = $page->toArray()['props']['trends'];
        });

        return array_column($trends, null, 'id')['boot'] ?? null;
    }

    it('follows the typical start time across scans and says when it gets slower', function () {
        $trend = wbcTrend(wbcHistory([40, 45, 60, 85]));

        expect($trend['title'])->toBe('Startup time')
            ->and($trend['latest'])->toBe('1 min 25 sec')
            ->and($trend['change'])->toBe('+45 sec')
            ->and($trend['sentence'])->toBe('Starting up is getting slower.')
            ->and($trend['tone'])->toBe('bad')
            ->and(array_column($trend['points'], 'label'))->toBe(['40 sec', '45 sec', '1 min', '1 min 25 sec']);
    });

    it('says when it gets faster or stays about the same', function () {
        expect(wbcTrend(wbcHistory([90, 70, 50, 40]))['sentence'])->toBe('Starting up is getting faster.')
            ->and(wbcTrend(wbcHistory([50, 52, 49, 55]))['sentence'])->toBe('Startup time has stayed about the same.')
            ->and(wbcTrend(wbcHistory([50, 52, 49, 55]))['tone'])->toBe('neutral');
    });

    it('skips scans without a start time and needs enough of them', function () {
        expect(wbcTrend(wbcHistory([40, null, 60, 70]))['points'])->toHaveCount(3)
            ->and(wbcTrend(wbcHistory([40, null, null, 70])))->toBeNull()
            ->and(wbcTrend(wbcHistory([40, 50])))->toBeNull()
            ->and(wbcTrend(wbcHistory([40, 50, 60, 70], withBoot: false)))->toBeNull();
    });

    it('ignores a start time it cannot trust', function () {
        expect(wbcTrend(wbcHistory([40, 99999, 60, 70, 80]))['points'])->toHaveCount(4);
    });
});

describe('masking of crash names', function () {
    function wbcImport(string $crashes): string
    {
        $text = "# Windows checkup: TEST-PC\n\n- Format: 1\n- OS: Windows\n- Device type: desktop\n- Serial: WBCMASK0001\n- Model: Test\n\n"
            .wbcSection('Startup programs', 'C:\\Users\\Johannes\\AppData\\x.exe and C:\\Users\\Jan\\AppData\\y.exe')
            .wbcSection('Recent app crashes', $crashes)
            .wbcSection('Apps you may not need', "scan: complete\napp|Jan Planner|5242880|2020-01-01");

        return (new ImportReport)->handle(User::factory()->create(), $text)->markdown;
    }

    it('hides a user name inside an app name, even a short one', function () {
        $markdown = wbcImport(wbcCrashBody("app|Johannes Notes.exe|4\napp|Jan Planner|3\napp|Chrome.exe|2", total: '9'));

        expect($markdown)->toContain('app|<user> Notes.exe|4')->toContain('app|<user> Planner|3')->toContain('app|Chrome.exe|2')
            ->not->toContain('Johannes')->toContain('app|Jan Planner|5242880|2020-01-01');
    });

    it('still masks the names when one app name holds invalid UTF-8', function () {
        $text = "## Startup programs\n\n```\nC:\\Users\\Johannes\\AppData\\x.exe\n```\n".wbcSection('Recent app crashes', wbcCrashBody("app|Bad\xC3\x28|1\napp|Johannes Notes.exe|4", total: '5'));
        $markdown = (new ReportUserMasker)->mask($text);

        expect($markdown)->not->toContain('Johannes')->toContain('<user> Notes.exe');
    });

    it('does not turn a longer word into a placeholder', function () {
        $markdown = wbcImport(wbcCrashBody("app|Janitor Pro|4\napp|Jan.exe|2", total: '6'));

        expect($markdown)->toContain('app|Janitor Pro|4')->toContain('app|<user>.exe|2');
    });
});
