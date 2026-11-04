<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Machine;
use App\Models\Report;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * @param  list<string>  $flags
 * @return list<array{id: string, title: string, rows: list<array{label: string, value: string, tone: string}>, list: array<string, mixed>|null}>
 */
function ownerDetails(string $markdown, array $flags = [], string $deviceType = 'laptop'): array
{
    $user = User::factory()->create();
    $machine = Machine::factory()->for($user)->create(['device_type' => $deviceType]);
    Report::factory()->for($machine)->create(['markdown' => $markdown, 'flags' => $flags]);

    $details = [];

    actingAs($user)->get("/machines/{$machine->id}")
        ->assertOk()
        ->assertInertia(function ($page) use (&$details): void {
            $details = $page->toArray()['props']['detailCards'];
        });

    return $details;
}

it('gives the owner the three cards from a real Mac report', function () {
    $details = ownerDetails(macReportFixture(), ['macOS 10.14.6 is old and likely out of security support.']);

    expect(array_column($details, 'id'))->toBe(['age', 'storage', 'battery', 'security', 'stability', 'slowness', 'startup', 'verdict'])
        ->and($details[0]['rows'][3])->toBe(['label' => 'Security support', 'value' => 'Out of security support', 'tone' => 'bad'])
        ->and($details[1]['rows'][0])->toBe(['label' => 'Free', 'value' => '14 GB (12%)', 'tone' => 'neutral'])
        ->and($details[1]['list']['items'][0])->toBe(['label' => 'Library', 'value' => '38 GB', 'tone' => 'neutral'])
        ->and($details[2]['rows'][1])->toBe(['label' => 'Condition', 'value' => 'Normal', 'tone' => 'good']);
});

it('gives the owner the security and stability cards from a real Mac report', function () {
    $details = ownerDetails(macReportFixture());

    expect($details[3]['title'])->toBe('Security summary')
        ->and($details[3]['rows'][0])->toBe(['label' => 'FileVault', 'value' => 'Off', 'tone' => 'bad'])
        ->and($details[3]['rows'][2])->toBe(['label' => 'System Integrity Protection', 'value' => 'On', 'tone' => 'good'])
        ->and($details[3]['rows'])->toHaveCount(9)
        ->and($details[4]['title'])->toBe('Stability')
        ->and($details[4]['rows'][0])->toBe(['label' => 'Kernel panics', 'value' => '2', 'tone' => 'bad'])
        ->and($details[3]['list'])->toBeNull()
        ->and($details[4]['list'])->toBeNull();
});

it('gives the owner the security and stability cards from a Windows report', function () {
    $details = ownerDetails(windowsLaptopFixture());

    expect(array_column($details[3]['rows'], 'label'))->toBe(['Disk encryption', 'Windows Firewall', 'Defender real-time protection', 'Automatic sign-in', 'Remote Desktop', 'SMBv1', 'Backup'])
        ->and(array_column($details[4]['rows'], 'label'))->toBe(['Blue screen crashes', 'Unexpected shutdowns', 'Startup programs']);
});

it('words the firewall for every state through the owner page', function (string $line, string $value) {
    $markdown = str_replace('Firewall is disabled. (State = 0)', $line, macReportFixture());
    $rows = array_column(ownerDetails($markdown)[3]['rows'], 'value', 'label');

    expect($rows['Application firewall'])->toBe($value);
})->with([
    'state 1' => ['Firewall is enabled. (State = 1)', 'On'],
    'state 2' => ['Firewall is enabled. (State = 2)', 'On'],
    'state 0' => ['Firewall is disabled. (State = 0)', 'Off'],
    'nonsense' => ['Firewall is purple.', 'Not checked'],
]);

it('never shows a green tick for a fact that was not checked', function () {
    $markdown = str_replace(
        ['FileVault is Off.', 'Time Machine: not set up', 'Find My Mac: on', 'Remote Login (SSH): off', 'Screen Sharing: off', 'Automatic login: on (the account name is not recorded)'],
        ['FileVault: could not check', 'Time Machine: could not check (tmutil did not answer)', 'Find My Mac: could not check', 'Remote Login (SSH): could not check (netstat did not answer)', 'Screen Sharing: could not check (netstat did not answer)', 'Automatic login: off'],
        macReportFixture(),
    );
    $rows = array_column(ownerDetails($markdown)[3]['rows'], null, 'label');

    foreach (['FileVault', 'Backup', 'Find My Mac', 'Remote Login', 'Screen Sharing'] as $label) {
        expect($rows[$label])->toBe(['label' => $label, 'value' => 'Not checked', 'tone' => 'neutral']);
    }
});

it('survives huge counts and markup in the new sections', function () {
    $markdown = str_replace(
        ["LaunchAgents/Daemons:\n4", 'Dropbox, Slack'],
        ["LaunchAgents/Daemons:\n99999999999999999999999", '<img src=x onerror=alert(1)>, {{ 7*7 }}'],
        macReportFixture(),
    );
    $details = ownerDetails($markdown);
    $rows = array_column($details[4]['rows'], 'value', 'label');
    $encoded = (string) json_encode($details);

    expect($rows['Login items'])->toBe('2')
        ->and($rows['Background startup items'])->toBe('4')
        ->and($encoded)->not->toContain('<img')->not->toContain('undefined')->not->toContain('NaN');
});

it('agrees with the free disk fact tile', function () {
    $user = User::factory()->create();
    $machine = Machine::factory()->for($user)->create();
    Report::factory()->for($machine)->create(['markdown' => macReportFixture(), 'flags' => []]);

    actingAs($user)->get("/machines/{$machine->id}")
        ->assertInertia(fn ($page) => $page
            ->where('report.facts.free_disk', '12%')
            ->where('detailCards.1.rows.0.value', '14 GB (12%)'));
});

it('gives the owner the cards from a Windows report', function () {
    $details = ownerDetails(windowsLaptopFixture(), ['Windows 10 Pro reached end of support on 14 October 2025, so it only gets updates with a paid extension.']);

    expect(array_column($details, 'id'))->toBe(['age', 'storage', 'battery', 'security', 'stability', 'slowness', 'startup', 'verdict'])
        ->and($details[0]['rows'][2]['value'])->toBe('Out of security support')
        ->and($details[1]['rows'][0]['value'])->toBe('52 GB (22%)');
});

it('sends no cards for a machine without a report', function () {
    $user = User::factory()->create();
    $machine = Machine::factory()->for($user)->create();

    actingAs($user)->get("/machines/{$machine->id}")->assertInertia(fn ($page) => $page->where('detailCards', []));
});

it('sends only the cards whose data exists', function (string $markdown, array $ids) {
    expect(array_column(ownerDetails($markdown, [], 'desktop'), 'id'))->toBe($ids);
})->with([
    'a report from an old script version' => ["# Mac checkup: Old\n\n- Format: 1\n- OS: macOS\n- Free disk: 30%\n\n## Things to look at\n\n- Nothing flagged automatically.\n", ['storage', 'slowness', 'verdict']],
    'a title and nothing else' => ["# Report\n", []],
    'an empty report' => ['', []],
    'sections that exist but are empty' => ["# Report\n\n## Disk usage\n\n```\n```\n\n## Software update age\n\n```\n```\n", []],
]);

it('reads a report with Windows line endings', function () {
    $lf = ownerDetails(macReportFixture());
    $crlf = ownerDetails(str_replace("\n", "\r\n", macReportFixture()));

    expect($crlf)->toBe($lf);
});

it('is not fooled by a section heading inside a code fence', function () {
    $markdown = str_replace("Volume Name:               Macintosh HD\n", "Volume Name:               Macintosh HD\n## Disk usage\n", macReportFixture());
    $details = ownerDetails($markdown);

    expect($details[1]['rows'][0]['value'])->toBe('14 GB (12%)');
});

it('uses the first of two sections with the same heading', function () {
    $details = ownerDetails(macReportFixture()."\n## Disk usage\n\n```\n/dev/other 1Gi 1Gi 0B 100% /\n```\n");

    expect($details[1]['rows'][1]['value'])->toBe('99 GB of 113 GB');
});

it('reads df output with terabytes and decimals', function () {
    $markdown = str_replace('/dev/disk1s1   113Gi   97Gi   14Gi    88%', '/dev/disk1s1   1.8Ti   1.2Ti   600Gi    67%', macReportFixture());
    $details = ownerDetails($markdown);

    expect($details[1]['rows'][0]['value'])->toBe('600 GB (12%)')
        ->and($details[1]['rows'][1]['value'])->toBe('1.2 TB of 1.8 TB');
});

it('handles a very long report', function () {
    $filler = implode("\n", array_fill(0, 20000, 'filler line'));
    $details = ownerDetails(macReportFixture()."\n## Installed apps\n\n```\n{$filler}\n```\n");

    expect(array_column($details, 'id'))->toBe(['age', 'storage', 'battery', 'security', 'stability', 'slowness', 'startup', 'verdict']);
});

it('never lets report text become markup or a placeholder value', function () {
    $markdown = str_replace('MacBook Air (MacBookAir7,2)', '<img src=x onerror=alert(1)> (MacBookAir99,9)', macReportFixture());
    $details = ownerDetails($markdown);
    $encoded = (string) json_encode($details);

    expect($details[0]['rows'][0]['value'])->toBe('<img src=x onerror=alert(1)>')
        ->and($encoded)->not->toContain('undefined')->not->toContain('NaN')->not->toContain('null,');
});

it('keeps all of it off the share pages', function () {
    $client = Client::factory()->withShareLink()->create();
    $machine = Machine::factory()->for($client->user)->create(['client_id' => $client->id, 'device_type' => 'laptop']);
    Report::factory()->for($machine)->create(['markdown' => macReportFixture(), 'flags' => ['macOS 10.14.6 is old and likely out of security support.']]);

    foreach (["/c/{$client->share_token}", "/c/{$client->share_token}/machines/{$machine->id}"] as $path) {
        $this->get($path)
            ->assertOk()
            ->assertInertia(function ($page): void {
                $props = $page->toArray()['props'];
                $encoded = (string) json_encode($props);

                expect($props)->not->toHaveKey('detailCards')->not->toHaveKey('details')
                    ->and($encoded)->not->toContain('Library/Caches')
                    ->and($encoded)->not->toContain('Solid state')
                    ->and($encoded)->not->toContain('Security summary')
                    ->and($encoded)->not->toContain('FileVault')
                    ->and($encoded)->not->toContain('Application firewall')
                    ->and($encoded)->not->toContain('Kernel panic')
                    ->and($encoded)->not->toContain('Blue screen')
                    ->and($encoded)->not->toContain('Login items')
                    ->and($encoded)->not->toContain('First released')
                    ->and($encoded)->not->toContain('Charge cycles')
                    ->and($encoded)->not->toContain('Why is it slow')
                    ->and($encoded)->not->toContain('slowness')
                    ->and($encoded)->not->toContain('Nothing obviously slowing it down');
            });
    }
});

it('keeps the slowness card off the share pages of a slow machine too', function () {
    $client = Client::factory()->withShareLink()->create();
    $machine = Machine::factory()->for($client->user)->create(['client_id' => $client->id, 'device_type' => 'laptop']);
    Report::factory()->for($machine)->create(['markdown' => str_replace('- Free disk: 12%', '- Free disk: 4%', macReportFixture())]);

    foreach (["/c/{$client->share_token}", "/c/{$client->share_token}/machines/{$machine->id}"] as $path) {
        $this->get($path)
            ->assertOk()
            ->assertInertia(function ($page): void {
                $encoded = (string) json_encode($page->toArray()['props']);

                expect($encoded)->not->toContain('Probably slow because')->not->toContain('The drive is almost full');
            });
    }
});

it('gives the owner the slowness card with a verdict and reasons from a slow scan', function () {
    $markdown = str_replace('- Free disk: 12%', '- Free disk: 4%', macReportFixture())
        ."\n## Memory pressure\n\n```\nSystem-wide memory free percentage: 5%\n```\n";

    $details = ownerDetails($markdown);
    $card = $details[array_search('slowness', array_column($details, 'id'), true)];

    expect($card['id'])->toBe('slowness')
        ->and($card['title'])->toBe('Why is it slow?')
        ->and($card['rows'][0])->toBe(['label' => 'Verdict', 'value' => 'Probably slow because of 2 things', 'tone' => 'bad'])
        ->and(array_column($card['rows'], 'label'))->toBe(['Verdict', 'Memory is almost full', 'The drive is almost full']);
});

function storageBreakdownMarkdown(): string
{
    return macReportFixture()."\n## Storage breakdown\n\n```\nscan: partial (time limit of 90 seconds reached)\nseconds: 90\nlimit: 90\nfolder|Downloads|1572864\nroot|Users|31457280\ntype|Photos|5242880|1200\next|jpg|4194304|1000\n```\n";
}

it('gives the owner the storage breakdown card with the camelCase detailCards prop', function () {
    $details = ownerDetails(storageBreakdownMarkdown());
    $card = collect($details)->firstWhere('id', 'storage-breakdown');

    expect(array_column($details, 'id'))->toContain('storage-breakdown')
        ->and($card['title'])->toBe('Storage breakdown')
        ->and($card['bars'][0]['items'][0]['label'])->toBe('Downloads')
        ->and($card['notes'][0])->toContain('time limit');
});

it('shows no storage breakdown card for an older report without the section', function () {
    expect(array_column(ownerDetails(macReportFixture()), 'id'))->not->toContain('storage-breakdown');
});

it('never gives the storage breakdown to a share page', function () {
    $client = Client::factory()->withShareLink()->create();
    $machine = Machine::factory()->for($client->user)->create(['client_id' => $client->id]);
    Report::factory()->for($machine)->create(['markdown' => storageBreakdownMarkdown()]);

    foreach (["/c/{$client->share_token}", "/c/{$client->share_token}/machines/{$machine->id}"] as $path) {
        $this->get($path)
            ->assertOk()
            ->assertDontSee('Storage breakdown')
            ->assertInertia(function ($page): void {
                $props = $page->toArray()['props'];

                expect($props)->not->toHaveKey('detailCards')
                    ->and((string) json_encode($props))->not->toContain('Storage breakdown')->not->toContain('storage-breakdown');
            });
    }
});

it('gives the owner the worth keeping card last, with a verdict and the rule of thumb note', function () {
    $details = ownerDetails(macReportFixture(), ['macOS 10.14.6 is old and likely out of security support.']);
    $card = end($details);

    expect($card['id'])->toBe('verdict')
        ->and($card['title'])->toBe('Worth keeping?')
        ->and($card['rows'][0])->toBe(['label' => 'Verdict', 'value' => 'Replace', 'tone' => 'bad'])
        ->and(array_column($card['rows'], 'label'))->toBe(['Verdict', 'The operating system no longer gets security updates', 'About 11 years old', 'Good to know'])
        ->and($card['list'])->toBeNull();
});

it('keeps the verdict off the share pages', function () {
    $client = Client::factory()->withShareLink()->create();
    $machine = Machine::factory()->for($client->user)->create(['client_id' => $client->id, 'device_type' => 'laptop']);
    Report::factory()->for($machine)->create(['markdown' => macReportFixture(), 'flags' => ['macOS 10.14.6 is old and likely out of security support.']]);

    foreach (["/c/{$client->share_token}", "/c/{$client->share_token}/machines/{$machine->id}"] as $path) {
        $this->get($path)
            ->assertOk()
            ->assertInertia(function ($page): void {
                $encoded = (string) json_encode($page->toArray()['props']);

                expect($encoded)->not->toContain('Worth keeping')
                    ->and($encoded)->not->toContain('rule of thumb')
                    ->and($encoded)->not->toContain('no longer gets security updates');
            });
    }
});

function startupCard(string $markdown): array
{
    return collect(ownerDetails($markdown))->firstWhere('id', 'startup');
}

it('gives the owner the startup items card from a real Mac report under the camelCase detailCards prop', function () {
    $card = startupCard(macReportFixture());

    expect($card['id'])->toBe('startup')
        ->and($card['title'])->toBe('Startup items')
        ->and(array_column($card['rows'], 'value', 'label'))->toBe(['Login items' => '2', 'Launch agents and daemons' => '4 (2 agents, 2 daemons)'])
        ->and(array_column($card['list']['items'], 'label'))->toBe(['updater', 'menubar', 'helper', 'sync', 'Dropbox', 'Slack']);
});

it('gives the owner the startup items card from a Windows report', function () {
    $card = startupCard(windowsLaptopFixture());

    expect($card['id'])->toBe('startup')
        ->and(array_column($card['rows'], 'value', 'label'))->toBe(['Startup programs' => '3'])
        ->and($card['list']['items'])->toBe([['label' => 'OneDrive', 'value' => 'Syncs OneDrive files', 'tone' => 'neutral']])
        ->and($card['notes'][0])->toBe('The scan named 1 of the 3 startup programs.');
});

it('shows no startup items card for an older report without the sections', function () {
    $markdown = (string) preg_replace('/## (?:Login items|Performance: login item and startup counts|Startup items: LaunchAgents and LaunchDaemons)\n.*?```\n.*?```\n/s', '', macReportFixture());

    expect($markdown)->not->toContain('## Login items')->not->toContain('## Startup items')
        ->and(array_column(ownerDetails($markdown), 'id'))->not->toContain('startup');
});

it('never gives the startup items card to a share page', function () {
    $client = Client::factory()->withShareLink()->create();
    $machine = Machine::factory()->for($client->user)->create(['client_id' => $client->id, 'device_type' => 'laptop']);
    Report::factory()->for($machine)->create(['markdown' => macReportFixture()]);

    foreach (["/c/{$client->share_token}", "/c/{$client->share_token}/machines/{$machine->id}"] as $path) {
        $this->get($path)
            ->assertOk()
            ->assertInertia(function ($page): void {
                $props = $page->toArray()['props'];
                $encoded = (string) json_encode($props);

                expect($props)->not->toHaveKey('detailCards')
                    ->and($encoded)->not->toContain('Startup items')->not->toContain('Worth a look')->not->toContain('Syncs Dropbox')->not->toContain('menubar');
            });
    }
});
