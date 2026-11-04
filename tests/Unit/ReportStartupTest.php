<?php

declare(strict_types=1);

use App\Services\Reports\ReportSections;
use App\Services\Reports\ReportStartup;

const SU_LOGIN = 'Login items';

const SU_BACKGROUND = 'Background items (macOS 13+)';

const SU_LAUNCH = 'Startup items: LaunchAgents and LaunchDaemons';

const SU_COUNTS = 'Performance: login item and startup counts';

const SU_PROGRAMS = 'Startup programs';

/**
 * @param  array<string, string>  $sections
 */
function suDoc(array $sections): string
{
    $markdown = "# Checkup: Test\n\n- Format: 1\n";

    foreach ($sections as $title => $body) {
        $markdown .= "\n## {$title}\n\n```\n{$body}\n```\n";
    }

    return $markdown;
}

/**
 * @param  array<string, string>  $sections
 * @return array{id: string, title: string, rows: list<array{label: string, value: string, tone: string}>, list: array{title: string, items: list<array{label: string, value: string, tone: string}>}|null, notes?: list<string>}|null
 */
function suCard(array $sections): ?array
{
    return (new ReportStartup)->card(ReportSections::parse(suDoc($sections)));
}

/**
 * @param  list<array{string, string, string}>  $rows
 */
function suTable(array $rows, ?int $count = null): string
{
    $name = max(4, ...array_map(fn (array $row): int => mb_strlen($row[0]), $rows));
    $command = max(7, ...array_map(fn (array $row): int => mb_strlen($row[1]), $rows));
    $lines = ['Count: '.($count ?? count($rows)), str_pad('Name', $name).' '.str_pad('Command', $command).' Location', str_repeat('-', $name).' '.str_repeat('-', $command).' --------'];

    foreach ($rows as [$n, $c, $l]) {
        $lines[] = mb_str_pad($n, $name).' '.mb_str_pad($c, $command).' '.$l;
    }

    return implode("\n", $lines);
}

/**
 * @return array<string, string>
 */
function suRows(?array $card): array
{
    return array_column($card['rows'] ?? [], 'value', 'label');
}

/**
 * @return list<string>
 */
function suLabels(?array $card): array
{
    return array_column($card['list']['items'] ?? [], 'label');
}

function suRecord(string $name, string $type = 'app (0x2)', string $developer = 'Some Developer', string $disposition = '[enabled, allowed, visible, notified] (0xb)'): string
{
    return " Name: {$name}\n Developer Name: {$developer}\n Type: {$type}\n Disposition: {$disposition}\n";
}

it('reads the counts and names from a real Mac report', function () {
    $card = suCard([
        SU_LAUNCH => "/Library/LaunchAgents:\ncom.example.backup.plist\n\n/Library/LaunchDaemons:\ncom.example.indexer.plist\ncom.example.sync.plist\n\n/Users/testuser/Library/LaunchAgents:\ncom.example.menubar.plist",
        SU_LOGIN => 'Dropbox, Slack',
        SU_COUNTS => "LaunchAgents/Daemons:\n4",
    ]);

    expect($card['id'])->toBe('startup')
        ->and($card['title'])->toBe('Startup items')
        ->and($card['rows'])->toBe([
            ['label' => 'Login items', 'value' => '2', 'tone' => 'neutral'],
            ['label' => 'Launch agents and daemons', 'value' => '4 (2 agents, 2 daemons)', 'tone' => 'neutral'],
        ])
        ->and(suLabels($card))->toBe(['backup', 'menubar', 'indexer', 'sync', 'Dropbox', 'Slack'])
        ->and($card['list']['items'][0])->toBe(['label' => 'backup', 'value' => 'Not recognised. From: system launch agent.', 'tone' => 'neutral'])
        ->and($card['list']['items'][1]['value'])->toBe('Not recognised. From: user launch agent.')
        ->and($card['list']['items'][2]['value'])->toBe('Not recognised. From: system launch daemon. A launch daemon runs for the whole Mac, before anyone logs in.')
        ->and($card['list']['items'][4])->toBe(['label' => 'Dropbox', 'value' => 'Syncs Dropbox files. From: login item.', 'tone' => 'neutral'])
        ->and($card['notes'])->toHaveCount(2)
        ->and($card['notes'][0])->toContain('overlap')
        ->and($card['notes'][1])->toContain('does not record how much');
});

it('reads a Windows table and says the scan named fewer than it counted', function () {
    $card = suCard([SU_PROGRAMS => suTable([['OneDrive', 'C:\x\OneDrive.exe', 'HKU\Run']], 3)]);

    expect(suRows($card))->toBe(['Startup programs' => '3'])
        ->and(suLabels($card))->toBe(['OneDrive'])
        ->and($card['notes'][0])->toBe('The scan named 1 of the 3 startup programs.');
});

it('ships no card when no startup section is in the report', function () {
    expect(suCard([]))->toBeNull()
        ->and(suCard(['Something else' => 'x']))->toBeNull();
});

it('counts honestly when sections are empty, unreadable or n/a', function (array $sections) {
    expect(suCard($sections))->toBeNull();
})->with([
    'empty login items error' => [[SU_LOGIN => 'execution error: Not authorized to send Apple events to System Events. (-1743)']],
    'got an error' => [[SU_LOGIN => 'System Events got an error: nope']],
    'background with no records' => [[SU_BACKGROUND => "\n(capped at 30 seconds; if nothing is listed above, the command timed out or needs admin rights)"]],
    'background with only developers' => [[SU_BACKGROUND => suRecord('Dropbox, Inc.', 'developer (0x20)')]],
    'empty listing and no counts' => [[SU_LAUNCH => '']],
    'listing with only ls errors' => [[SU_LAUNCH => 'ls: /nope: No such file or directory']],
    'counts n/a' => [[SU_COUNTS => "LaunchAgents/Daemons:\nn/a"]],
    'windows count n/a' => [[SU_PROGRAMS => 'Count: n/a']],
    'windows count too many digits' => [[SU_PROGRAMS => 'Count: 123456']],
]);

it('treats a login items section with no items as zero', function () {
    $card = suCard([SU_LOGIN => '']);

    expect(suRows($card))->toBe(['Login items' => '0'])
        ->and($card['list'])->toBeNull()
        ->and($card['notes'])->toBe([]);
});

it('falls back to the launch count when the listing is missing', function () {
    $card = suCard([SU_COUNTS => "LaunchAgents/Daemons:\n12"]);

    expect(suRows($card))->toBe(['Launch agents and daemons' => '12'])
        ->and($card['list'])->toBeNull();
});

it('uses singular words for a single agent or daemon', function () {
    $card = suCard([SU_LAUNCH => "/Library/LaunchAgents:\ncom.example.one.plist\n\n/Library/LaunchDaemons:\ncom.example.two.plist"]);

    expect(suRows($card))->toBe(['Launch agents and daemons' => '2 (1 agent, 1 daemon)']);
});

it('counts only plist files from the listing', function () {
    $card = suCard([SU_LAUNCH => "/Library/LaunchAgents:\nnotes.txt\ncom.example.one.plist\nCOM.EXAMPLE.TWO.PLIST"]);

    expect(suRows($card))->toBe(['Launch agents and daemons' => '2 (2 agents, 0 daemons)']);
});

it('adds a plain note and a red tone when there are many login items', function () {
    $names = implode(', ', array_map(fn (int $i): string => "App{$i}", range(1, ReportStartup::LOGIN_ITEMS_HIGH + 1)));
    $card = suCard([SU_LOGIN => $names]);

    expect($card['rows'][0])->toBe(['label' => 'Login items', 'value' => '9', 'tone' => 'bad'])
        ->and($card['notes'][0])->toBe('A lot starts by itself on this Mac: 9 login items. Switch off what you do not need in System Settings, under General, then Login Items & Extensions.');
});

it('stays quiet at exactly the threshold', function () {
    $names = implode(', ', array_map(fn (int $i): string => "App{$i}", range(1, ReportStartup::LOGIN_ITEMS_HIGH)));
    $card = suCard([SU_LOGIN => $names]);

    expect($card['rows'][0]['tone'])->toBe('neutral')
        ->and($card['notes'])->not->toContain('A lot starts by itself on this Mac: 8 login items.');
});

it('names every Mac list that is over its threshold in one sentence', function () {
    $login = implode(', ', array_map(fn (int $i): string => "App{$i}", range(1, 9)));
    $background = '';

    foreach (range(1, ReportStartup::BACKGROUND_ITEMS_HIGH + 1) as $i) {
        $background .= suRecord("Item{$i}");
    }

    $plists = "/Library/LaunchAgents:\n".implode("\n", array_map(fn (int $i): string => "com.example.n{$i}.plist", range(1, ReportStartup::LAUNCH_ITEMS_HIGH + 1)));
    $card = suCard([SU_LOGIN => $login, SU_BACKGROUND => $background, SU_LAUNCH => $plists]);

    expect(array_column($card['rows'], 'tone'))->toBe(['bad', 'bad', 'bad'])
        ->and($card['notes'][0])->toStartWith('A lot starts by itself on this Mac: 9 login items, 26 background items and 31 launch agents and daemons. ');
});

it('words the Windows note around Task Manager and flags above the threshold', function () {
    $rows = array_map(fn (int $i): array => ["Program{$i}", "C:\\p\\p{$i}.exe", 'HKU\Run'], range(1, ReportStartup::WINDOWS_STARTUP_HIGH + 1));
    $card = suCard([SU_PROGRAMS => suTable($rows)]);

    expect($card['rows'][0])->toBe(['label' => 'Startup programs', 'value' => '16', 'tone' => 'bad'])
        ->and($card['notes'][0])->toBe('A lot starts by itself with Windows: 16 startup programs. Switch off what you do not need in Task Manager, on the Startup tab.');
});

it('shows a huge count with separators and does not break', function () {
    $card = suCard([SU_COUNTS => "LaunchAgents/Daemons:\n99999"]);

    expect(suRows($card))->toBe(['Launch agents and daemons' => '99,999'])
        ->and($card['notes'][0])->toContain('99,999 launch agents and daemons');
});

it('lists a duplicated name once but counts every copy', function () {
    $card = suCard([SU_LOGIN => 'Foo, foo, FOO', SU_LAUNCH => "/Library/LaunchAgents:\ncom.a.foo.plist\ncom.b.foo.plist"]);

    expect(suRows($card)['Login items'])->toBe('3')
        ->and(suRows($card)['Launch agents and daemons'])->toStartWith('2 ')
        ->and(suLabels($card))->toBe(['Foo']);
});

it('shows names only, never paths, arguments or user names', function () {
    $card = suCard([
        SU_LOGIN => '/Users/robbert/Applications/Secret Tool.app, C:\Users\robbert\AppData\Evil.exe, "Quoted"',
        SU_LAUNCH => "/Users/robbert/Library/LaunchAgents:\nnl.robbert.backup.plist",
    ]);
    $encoded = (string) json_encode($card);

    expect(suLabels($card))->toBe(['Secret Tool', 'Evil', 'Quoted', 'backup'])
        ->and($encoded)->not->toContain('robbert')->not->toContain('Users')->not->toContain('AppData')->not->toContain('.app')->not->toContain('.exe');
});

it('turns plist labels into the last part, and known vendors into a plain name', function (string $plist, string $label, string $value) {
    $card = suCard([SU_LAUNCH => "/Library/LaunchAgents:\n{$plist}"]);

    expect($card['list']['items'])->toBe([['label' => $label, 'value' => $value.'. From: system launch agent.', 'tone' => 'neutral']]);
})->with([
    'homebrew' => ['homebrew.mxcl.postgresql@14.plist', 'postgresql14', 'Not recognised'],
    'dropbox' => ['com.dropbox.client.plist', 'Dropbox', 'Syncs Dropbox files'],
    'google' => ['com.google.keystone.agent.plist', 'Google', 'Google updater or helper'],
    'adobe' => ['com.adobe.AdobeCreativeCloud.plist', 'Adobe', 'Adobe updater or helper'],
    'zoom' => ['us.zoom.ZoomDaemon.plist', 'Zoom', 'Video meetings'],
    'steam' => ['com.valvesoftware.steam.ipctool.plist', 'Steam', 'Game store'],
    'spotify' => ['com.spotify.webhelper.plist', 'Spotify', 'Music app'],
    'onedrive' => ['com.microsoft.OneDrive.plist', 'OneDrive', 'Syncs OneDrive files'],
]);

const SU_ORIGIN = "EA's game launcher, starts a helper at startup and can be switched off if the games are not used";

const SU_DAEMON = ' A launch daemon runs for the whole Mac, before anyone logs in.';

it('names every launch file from a real Mac report sensibly', function (string $header, string $file, ?string $label, ?string $value, string $from) {
    $card = suCard([SU_LAUNCH => "{$header}:\n{$file}"]);

    expect($card['list']['items'] ?? null)->toBe($label === null ? null : [['label' => $label, 'value' => "{$value}. From: {$from}.".(str_contains($from, 'daemon') ? SU_DAEMON : ''), 'tone' => 'neutral']]);
})->with([
    ['/Library/LaunchAgents', 'com.adobe.ARMDCHelper.cc24aef4a1b90ed56a725c38014c95072f92651fb65e1bf9c8e43c37a23d420d.plist', 'Adobe', 'Adobe updater or helper', 'system launch agent'],
    ['/Library/LaunchAgents', 'com.microsoft.OneDriveStandaloneUpdater.plist', 'OneDrive', 'Syncs OneDrive files', 'system launch agent'],
    ['/Library/LaunchAgents', 'com.microsoft.update.agent.plist', null, null, ''],
    ['/Library/LaunchAgents', 'helper.threeships.com.plist', 'threeships', 'Not recognised', 'system launch agent'],
    ['/Library/LaunchDaemons', 'PT.updd.plist', 'PT.updd', 'Not recognised', 'system launch daemon'],
    ['/Library/LaunchDaemons', 'com.adobe.ARMDC.Communicator.plist', 'Adobe', 'Adobe updater or helper', 'system launch daemon'],
    ['/Library/LaunchDaemons', 'com.adobe.ARMDC.SMJobBlessHelper.plist', 'Adobe', 'Adobe updater or helper', 'system launch daemon'],
    ['/Library/LaunchDaemons', 'com.apple.installer.osmessagetracing.plist', 'osmessagetracing', 'Apple installer item', 'system launch daemon'],
    ['/Library/LaunchDaemons', 'com.ea.origin.ESHelper.plist', 'EA Origin', SU_ORIGIN, 'system launch daemon'],
    ['/Library/LaunchDaemons', 'com.microsoft.OneDriveStandaloneUpdaterDaemon.plist', 'OneDrive', 'Syncs OneDrive files', 'system launch daemon'],
    ['/Library/LaunchDaemons', 'com.microsoft.OneDriveUpdaterDaemon.plist', 'OneDrive', 'Syncs OneDrive files', 'system launch daemon'],
    ['/Library/LaunchDaemons', 'com.microsoft.autoupdate.helper.plist', null, null, ''],
    ['/Library/LaunchDaemons', 'com.microsoft.office.licensingV2.helper.plist', null, null, ''],
    ['/Library/LaunchDaemons', 'com.microsoft.teams.TeamsUpdaterDaemon.plist', 'Teams', 'Chat and video meetings', 'system launch daemon'],
    ['/Users/someone/Library/LaunchAgents', 'com.ea.origin.WebHelper.plist', 'EA Origin', SU_ORIGIN, 'user launch agent'],
    ['/Users/someone/Library/LaunchAgents', 'com.google.keystone.agent.plist', 'Google', 'Google updater or helper', 'user launch agent'],
    ['/Users/someone/Library/LaunchAgents', 'com.google.keystone.xpcservice.plist', 'Google', 'Google updater or helper', 'user launch agent'],
    ['/Users/someone/Library/LaunchAgents', 'com.apple.installer.osmessagetracing.plist', 'osmessagetracing', 'Apple style name, unusual place', 'user launch agent'],
    ['/Library/LaunchAgents', 'com.apple.installer.osmessagetracing.plist', 'osmessagetracing', 'Apple style name, unusual place', 'system launch agent'],
]);

it('groups the real Mac dataset into one row per app, with every place it comes from', function () {
    $card = suCard([
        SU_LAUNCH => "/Library/LaunchAgents:\ncom.adobe.ARMDCHelper.cc24aef4a1b90ed56a725c38014c95072f92651fb65e1bf9c8e43c37a23d420d.plist\ncom.microsoft.OneDriveStandaloneUpdater.plist\ncom.microsoft.update.agent.plist\nhelper.threeships.com.plist\n\n"
            ."/Library/LaunchDaemons:\nPT.updd.plist\ncom.adobe.ARMDC.Communicator.plist\ncom.adobe.ARMDC.SMJobBlessHelper.plist\ncom.apple.installer.osmessagetracing.plist\ncom.ea.origin.ESHelper.plist\ncom.microsoft.OneDriveStandaloneUpdaterDaemon.plist\ncom.microsoft.OneDriveUpdaterDaemon.plist\ncom.microsoft.autoupdate.helper.plist\ncom.microsoft.office.licensingV2.helper.plist\ncom.microsoft.teams.TeamsUpdaterDaemon.plist\n\n"
            ."/Users/someone/Library/LaunchAgents:\ncom.ea.origin.WebHelper.plist\ncom.google.keystone.agent.plist\ncom.google.keystone.xpcservice.plist",
        SU_LOGIN => 'Origin, Loom',
    ]);

    expect(suRows($card)['Launch agents and daemons'])->toBe('17 (7 agents, 10 daemons)')
        ->and($card['list']['items'])->toBe([
            ['label' => 'threeships', 'value' => 'Not recognised. From: system launch agent.', 'tone' => 'neutral'],
            ['label' => 'PT.updd', 'value' => 'Not recognised. From: system launch daemon.'.SU_DAEMON, 'tone' => 'neutral'],
            ['label' => 'EA Origin', 'value' => SU_ORIGIN.'. From: login item, user launch agent, system launch daemon.'.SU_DAEMON, 'tone' => 'neutral'],
            ['label' => 'Loom', 'value' => 'Screen recording app, can be switched off. From: login item.', 'tone' => 'neutral'],
            ['label' => 'Adobe', 'value' => 'Adobe updater or helper. From: system launch agent, system launch daemon.'.SU_DAEMON, 'tone' => 'neutral'],
            ['label' => 'OneDrive', 'value' => 'Syncs OneDrive files. From: system launch agent, system launch daemon.'.SU_DAEMON, 'tone' => 'neutral'],
        ]);
});

it('ranks an Apple installer leftover below everything else but keeps it in the list', function () {
    $card = suCard([
        SU_LAUNCH => "/Library/LaunchDaemons:\ncom.apple.installer.osmessagetracing.plist\ncom.example.thing.plist\n\n/Library/LaunchAgents:\ncom.microsoft.OneDriveStandaloneUpdater.plist",
    ]);

    expect(suLabels($card))->toBe(['thing', 'OneDrive', 'osmessagetracing']);
});

it('says background items and a listing without folder headers in plain words too', function () {
    $background = suCard([SU_BACKGROUND => suRecord('Loom', 'app (0x2)', 'Loom, Inc.')]);
    $bare = suCard([SU_LAUNCH => "com.example.thing.plist\n"]);

    expect($background['list']['items'])->toBe([['label' => 'Loom', 'value' => 'Screen recording app, can be switched off. From: background item.', 'tone' => 'neutral']])
        ->and($bare['list']['items'])->toBe([['label' => 'thing', 'value' => 'Not recognised. From: launch agent.', 'tone' => 'neutral']]);
});

it('keeps names only in the origin text', function () {
    $card = suCard([SU_LAUNCH => "/Users/someone/Library/LaunchAgents:\ncom.ea.origin.WebHelper.plist"]);

    expect((string) json_encode($card))->not->toContain('someone')->not->toContain('/Users');
});

it('does not call a folder it does not know a user launch agent', function () {
    $card = suCard([SU_LAUNCH => "/System/Library/LaunchAgents:\ncom.example.one.plist\n\n/Users/someone/Library/LaunchAgents:\ncom.example.two.plist"]);

    expect(array_column($card['list']['items'], 'value', 'label'))->toBe([
        'one' => 'Not recognised. From: launch agent.',
        'two' => 'Not recognised. From: user launch agent.',
    ]);
});

it('keeps the more suspicious description when one name comes from two places', function () {
    $card = suCard([SU_LAUNCH => "/Library/LaunchDaemons:\ncom.apple.installer.osmessagetracing.plist\n\n/Library/LaunchAgents:\ncom.apple.installer.osmessagetracing.plist"]);

    expect($card['list']['items'])->toBe([['label' => 'osmessagetracing', 'value' => 'Apple style name, unusual place. From: system launch agent, system launch daemon.'.SU_DAEMON, 'tone' => 'neutral']]);
});

it('matches Origin and Loom by name only, not look-alikes', function (string $name, string $label, string $kind) {
    $card = suCard([SU_LOGIN => $name]);

    expect($card['list']['items'][0]['label'])->toBe($label)
        ->and($card['list']['items'][0]['value'])->toStartWith($kind);
})->with([
    'origin' => ['Origin', 'EA Origin', "EA's game launcher"],
    'ea origin' => ['EA Origin', 'EA Origin', "EA's game launcher"],
    'loom' => ['Loom', 'Loom', 'Screen recording app'],
    'loom desktop' => ['com.loom.desktop', 'Loom', 'Screen recording app'],
    'originlab' => ['OriginPro', 'OriginPro', 'Not recognised'],
    'loomis' => ['Loomis', 'Loomis', 'Not recognised'],
]);

it('reduces reverse-DNS labels to a meaningful name in either order', function (string $file, string $label) {
    $card = suCard([SU_LAUNCH => "/Library/LaunchAgents:\n{$file}"]);

    expect(suLabels($card))->toBe([$label]);
})->with([
    'vendor first' => ['com.acme.widget.plist', 'widget'],
    'vendor last' => ['widget.acme.com.plist', 'widget'],
    'bare tld never a name' => ['helper.threeships.com.plist', 'threeships'],
    'other tld last' => ['sync.acme.io.plist', 'sync'],
    'other tld first' => ['org.acme.widget.plist', 'widget'],
    'country tld' => ['tool.acme.nl.plist', 'tool'],
    'hash suffix dropped' => ['com.acme.widget.cc24aef4a1b90ed56a725c38014c95072f92651fb65e1bf9c8e43c37a23d420d.plist', 'widget'],
    'generic last falls back to product' => ['com.acme.widget.helper.plist', 'widget'],
    'generic last falls back to vendor' => ['com.acme.agent.plist', 'acme'],
    'several generics' => ['com.acme.daemon.updater.plist', 'acme'],
    'xpc service' => ['com.acme.widget.xpcservice.plist', 'widget'],
    'all generic keeps the last part' => ['com.helper.agent.plist', 'agent'],
    'short hex is a name, not a hash' => ['com.example.a1b2c3d4e5f6a7b8.plist', 'a1b2c3d4e5f6a7b8'],
    'only tlds and a hash keeps the vendor' => ['com.acme.cc24aef4a1b90ed56a725c38014c95072f92651fb65e1bf9c8e43c37a23d420d.plist', 'acme'],
    'upper case tld' => ['Widget.Acme.COM.plist', 'Widget'],
    'only hashes keeps the label' => [str_repeat('a', 32).'.'.str_repeat('b', 32).'.'.str_repeat('c', 32).'.plist', mb_substr(str_repeat('a', 32).'.'.str_repeat('b', 32).'.'.str_repeat('c', 32), 0, 40)],
    'only tlds keeps the last part' => ['com.org.net.plist', 'net'],
]);

it('describes the other well-known names', function (string $name, string $label, string $value) {
    $card = suCard([SU_LOGIN => $name]);

    expect($card['list']['items'])->toBe([['label' => $label, 'value' => $value.'. From: login item.', 'tone' => 'neutral']]);
})->with([
    'teams' => ['Microsoft Teams', 'Teams', 'Chat and video meetings'],
    'ms teams' => ['MSTeams', 'Teams', 'Chat and video meetings'],
    'slack' => ['Slack', 'Slack', 'Chat app'],
    'icloud' => ['iCloudDrive', 'iCloud', 'Syncs iCloud files'],
    'steam' => ['Steam', 'Steam', 'Game store'],
]);

it('does not mistake look-alike words for a known app', function (string $name) {
    $card = suCard([SU_LOGIN => $name]);

    expect($card['list']['items'][0]['value'])->toBe('Not recognised. From: login item.');
})->with(['steams', 'Zoomer', 'Teamsters']);

it('keeps Apple and Microsoft system items out of the list', function () {
    $background = suRecord('Apple Music Helper', 'login item (0x4)', 'Apple Inc.')
        .suRecord('com.apple.something', 'agent (0x8)', 'Apple Inc.')
        .suRecord('Something Odd', 'agent (0x8)', 'Nobody Ltd');
    $windows = suTable([['SecurityHealth', 'x', 'HKLM\Run'], ['MicrosoftEdgeAutoLaunch_AB12', 'x', 'HKU\Run'], ['Windows Security notification icon', 'x', 'HKU\Run'], ['ctfmon', 'x', 'HKU\Run'], ['Mystery', 'x', 'HKU\Run']]);

    expect(suLabels(suCard([SU_BACKGROUND => $background])))->toBe(['Something Odd'])
        ->and(suRows(suCard([SU_BACKGROUND => $background])))->toBe(['Background items' => '3'])
        ->and(suLabels(suCard([SU_PROGRAMS => $windows])))->toBe(['Mystery'])
        ->and(suRows(suCard([SU_PROGRAMS => $windows])))->toBe(['Startup programs' => '5']);
});

it('flags an Apple style name that sits outside a system folder as odd instead of trusting it', function () {
    $card = suCard([SU_LAUNCH => "/Library/LaunchDaemons:\ncom.apple.update.tracer.plist\ncom.example.thing.plist"]);

    expect($card['list']['items'])->toBe([
        ['label' => 'tracer', 'value' => 'Apple style name, unusual place. From: system launch daemon.'.SU_DAEMON, 'tone' => 'neutral'],
        ['label' => 'thing', 'value' => 'Not recognised. From: system launch daemon.'.SU_DAEMON, 'tone' => 'neutral'],
    ]);
});

it('puts random looking names first and shows at most six', function () {
    $plists = "/Library/LaunchAgents:\n".implode("\n", array_map(fn (int $i): string => "com.example.tool{$i}.plist", range(1, 8)))."\ncom.example.a1b2c3d4e5f6a7b8.plist\ncom.example.xkcdrtqz.plist";
    $card = suCard([SU_LAUNCH => $plists]);

    expect($card['list']['items'])->toHaveCount(6)
        ->and($card['list']['items'][0])->toBe(['label' => 'a1b2c3d4e5f6a7b8', 'value' => 'Odd looking name. From: system launch agent.', 'tone' => 'neutral'])
        ->and($card['list']['items'][1]['label'])->toBe('xkcdrtqz')
        ->and(array_slice(suLabels($card), 2))->toBe(['tool1', 'tool2', 'tool3', 'tool4']);
});

it('only treats long vowel-free names as random', function () {
    $card = suCard([SU_LOGIN => 'GTK, RTHDVCPL']);

    expect(array_column($card['list']['items'], 'value', 'label'))->toBe(['RTHDVCPL' => 'Odd looking name. From: login item.', 'GTK' => 'Not recognised. From: login item.']);
});

it('does not call a long non-Latin name random', function () {
    $card = suCard([SU_LOGIN => "\u{41f}\u{440}\u{438}\u{432}\u{435}\u{442}\u{43c}\u{438}\u{440}\u{43d}\u{44b}\u{439}, \u{30b7}\u{30b9}\u{30c6}\u{30e0}\u{30a2}\u{30d7}\u{30ea}\u{30b1}"]);

    expect(array_column($card['list']['items'], 'value'))->toBe(['Not recognised. From: login item.', 'Not recognised. From: login item.']);
});

it('does not rename or hide an item for merely resembling a known or system name', function (string $name) {
    $card = suCard([SU_LOGIN => $name]);

    expect($card['list']['items'])->toBe([['label' => $name, 'value' => 'Not recognised. From: login item.', 'tone' => 'neutral']]);
})->with(['slacker', 'MyDropbox', 'WindowsUpdateHelper', 'microsoftfoo', 'securityhealthfake', 'ctfmonster']);

it('counts a background item once when the dump lists it twice', function () {
    $card = suCard([SU_BACKGROUND => suRecord('Dropbox').suRecord('Dropbox').suRecord('Dropbox', 'agent (0x8)')]);

    expect(suRows($card))->toBe(['Background items' => '2']);
});

it('can reach the background threshold within the 150 lines the script keeps', function () {
    $background = '';

    foreach (range(1, 37) as $i) {
        $background .= suRecord("Item{$i}");
    }

    $card = suCard([SU_BACKGROUND => $background]);

    expect($card['rows'][0])->toBe(['label' => 'Background items', 'value' => '37', 'tone' => 'bad'])
        ->and($card['notes'][0])->toStartWith('A lot starts by itself on this Mac: 37 background items.');
});

it('cleans control characters, quotes, pipes, markup and over-long names', function () {
    $card = suCard([SU_LOGIN => "Foo\x07Bar, Pipe|Name, <script>alert1, \"Quote'd\", Caf\u{e9} Sync, \u{65e5}\u{672c}\u{8a9e}\u{30a2}\u{30d7}\u{30ea}"]);

    expect(suLabels($card))->toBe(['FooBar', 'PipeName', 'scriptalert1', 'Quoted', "Caf\u{e9} Sync", "\u{65e5}\u{672c}\u{8a9e}\u{30a2}\u{30d7}\u{30ea}"])
        ->and((string) json_encode($card))->not->toContain('<');
});

it('strips direction overrides and caps a very long name at forty characters', function () {
    $card = suCard([SU_LOGIN => "Rtl\u{202e}evil, ".str_repeat("Longname\u{e9}", 20)]);

    expect(suLabels($card)[0])->toBe('Rtlevil')
        ->and(mb_strlen(suLabels($card)[1]))->toBe(40);
});

it('skips names that clean down to nothing but still counts them', function () {
    $card = suCard([SU_LOGIN => "###, \u{202e}, Real"]);

    expect(suRows($card))->toBe(['Login items' => '3'])
        ->and(suLabels($card))->toBe(['Real']);
});

it('survives a name that is not valid UTF-8', function () {
    $card = suCard([SU_LOGIN => "Good, Bad\xC3\x28Name"]);

    expect(suRows($card))->toBe(['Login items' => '2'])
        ->and(suLabels($card))->toBe(['Good'])
        ->and(json_encode($card))->not->toBeFalse();
});

it('splits login items on commas, so a comma in a name counts as two', function () {
    $card = suCard([SU_LOGIN => 'Acme, Inc Helper']);

    expect(suRows($card))->toBe(['Login items' => '2']);
});

it('reads background items, skipping developers, disabled items and null names', function () {
    $background = suRecord('Dropbox, Inc.', 'developer (0x20)')
        .suRecord('Dropbox', 'app (0x2)', 'Dropbox, Inc.')
        .suRecord('com.example.sync', 'legacy daemon (0x10008)')
        .suRecord('Switched Off', 'login item (0x4)', 'X', '[disabled, allowed, visible, notified] (0x9)')
        .suRecord('(null)', 'agent (0x8)')
        .suRecord('No Type', '');
    $card = suCard([SU_BACKGROUND => $background."\n(capped at 30 seconds; if nothing is listed above, the command timed out or needs admin rights)"]);

    expect(suRows($card))->toBe(['Background items' => '3'])
        ->and(suLabels($card))->toBe(['sync', 'Dropbox'])
        ->and($card['notes'])->toHaveCount(1);
});

it('ignores lines before the first background record', function () {
    $card = suCard([SU_BACKGROUND => " Type: app (0x2)\n Developer Name: Orphan\n".suRecord('Dropbox')]);

    expect(suRows($card))->toBe(['Background items' => '1']);
});

it('says so when the background list hit its line cap', function () {
    $background = '';

    foreach (range(1, 38) as $i) {
        $background .= suRecord("Item{$i}");
    }

    $card = suCard([SU_BACKGROUND => $background]);

    expect($card['notes'])->toContain('The scan cut the background items list short, so there may be more than the count shows.');
});

it('does not claim a cut list when under the cap', function () {
    $card = suCard([SU_BACKGROUND => suRecord('Dropbox')]);

    expect($card['notes'])->not->toContain('The scan cut the background items list short, so there may be more than the count shows.');
});

it('skips wrapped continuation lines and handles Windows names with spaces and paths', function () {
    $table = suTable([['Microsoft OneDrive Sync', 'x', 'HKU\Run'], ['C:\Program Files\Foo Bar\foo.exe', 'x', 'Startup'], ['Mystery Tool', 'x', 'HKU\Run']]);
    $table .= "\n".str_repeat(' ', 40).'continued command text that wrapped';
    $card = suCard([SU_PROGRAMS => $table]);

    expect(suRows($card))->toBe(['Startup programs' => '3'])
        ->and(suLabels($card))->toBe(['foo', 'Mystery Tool', 'OneDrive'])
        ->and($card['notes'])->toBe(['The scan does not record how much each item slows startup, so this list puts unfamiliar names first instead of the heaviest. Names are matched by wording only: a familiar name is not proof an item is genuine.']);
});

it('does not read the tail of a wrapped long name as another program', function () {
    $card = suCard([SU_PROGRAMS => suTable([['A very long startup entry nam', 'x', 'HKU\Run'], ['Plain', 'x', 'HKU\Run']])."\ne that wrapped"]);

    expect(suLabels($card))->toBe(['A very long startup entry nam', 'Plain']);
});

it('reads Windows unicode names without misaligning the columns', function () {
    $card = suCard([SU_PROGRAMS => suTable([["Caf\u{e9}Tool", 'C:\x\a.exe', 'HKU\Run'], ["\u{65e5}\u{672c}\u{8a9e}", 'C:\x\b.exe', 'HKU\Run'], ['Plain', 'C:\x\c.exe', 'HKU\Run']])]);

    expect(suLabels($card))->toBe(["Caf\u{e9}Tool", "\u{65e5}\u{672c}\u{8a9e}", 'Plain']);
});

it('uses Windows Count when no table follows it', function () {
    $card = suCard([SU_PROGRAMS => "Count: 0\nCould not read startup programs: access denied"]);

    expect(suRows($card))->toBe(['Startup programs' => '0'])
        ->and($card['list'])->toBeNull()
        ->and($card['notes'])->toBe([]);
});

it('stops reading the Windows table at the first blank line', function () {
    $table = suTable([['First', 'x', 'HKU\Run']])."\n\nName   Other\n----   -----\nLater  thing";
    $card = suCard([SU_PROGRAMS => $table]);

    expect(suLabels($card))->toBe(['First']);
});

it('is not fooled by a command that imitates a known app, because commands are never read', function () {
    $card = suCard([SU_PROGRAMS => suTable([['Updater', 'C:\Users\bob\OneDrive\evil.exe --token=abc', 'HKU\Run']])]);

    expect($card['list']['items'][0])->toBe(['label' => 'Updater', 'value' => 'Not recognised', 'tone' => 'neutral'])
        ->and((string) json_encode($card))->not->toContain('bob')->not->toContain('token');
});

it('handles a very large listing', function () {
    $plists = "/Library/LaunchAgents:\n".implode("\n", array_map(fn (int $i): string => "com.example.item{$i}.plist", range(1, 5000)));
    $card = suCard([SU_LAUNCH => $plists]);

    expect(suRows($card))->toBe(['Launch agents and daemons' => '5,000 (5,000 agents, 0 daemons)'])
        ->and($card['list']['items'])->toHaveCount(6);
});

it('never prints placeholder values', function () {
    $card = suCard([SU_LOGIN => 'Dropbox', SU_COUNTS => "LaunchAgents/Daemons:\n3"]);
    $encoded = (string) json_encode($card);

    expect($encoded)->not->toContain('null,')->not->toContain('undefined')->not->toContain('NaN')->not->toContain('--');
});
