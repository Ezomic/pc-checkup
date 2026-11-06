<?php

declare(strict_types=1);

use App\Actions\Machines\ImportReport;
use App\Models\Client;
use App\Models\Machine;
use App\Models\Report;
use App\Models\User;
use App\Services\NeedsAttention;
use App\Services\Reports\ReportDetails;

use function Pest\Laravel\actingAs;

function hraSection(string $title, string $body): string
{
    return "\n## {$title}\n\n```\n{$body}\n```\n";
}

function hraCard(string $markdown, string $id): ?array
{
    return array_column(app(ReportDetails::class)->cards($markdown, [], 'laptop'), null, 'id')[$id] ?? null;
}

function hraRemote(string $body): ?array
{
    return hraCard(windowsLaptopFixture().hraSection('Remote access tools', $body), 'remote-access');
}

function hraNetwork(string $body): ?array
{
    return hraCard(windowsLaptopFixture().hraSection('Network settings', $body), 'network-settings');
}

function hraAntivirus(string $body): ?array
{
    return hraCard(windowsLaptopFixture().hraSection('Antivirus products', $body), 'antivirus-products');
}

function hraNetworkBody(string $extra = '', string $hosts = "hosts: complete\nhosts-entries: 0", string $proxy = 'proxy: off', string $dns = "dns: complete\ndns-server: 192.168.1.1"): string
{
    return trim("format: 1\n{$hosts}\n{$proxy}\n{$dns}\n{$extra}");
}

/**
 * @return list<array{level: string, text: string}>
 */
function hraReasons(string $markdown): array
{
    $machine = Machine::factory()->for(User::factory()->create())->create(['device_type' => 'laptop']);
    Report::factory()->for($machine)->create(['markdown' => $markdown]);

    $all = [];

    actingAs($machine->user)->get('/machines?attention=all')->assertOk()->assertInertia(function ($page) use (&$all, $machine): void {
        $row = collect($page->toArray()['props']['needsAttention']['rows'])->firstWhere('id', $machine->id);
        $all = $row['reasons'] ?? [];
    });

    return $all;
}

function hraReasonTexts(string $markdown): array
{
    $machine = Machine::factory()->for(User::factory()->create())->create(['device_type' => 'laptop']);
    Report::factory()->for($machine)->create(['markdown' => $markdown]);
    $method = new ReflectionMethod(NeedsAttention::class, 'analyse');

    return array_map(fn (array $reason): string => "{$reason['level']}: {$reason['text']}", $method->invoke(app(NeedsAttention::class), $machine, $machine->newestReport)['reasons']);
}

describe('the remote-access tools card', function () {
    it('lists the known tools found with how they were found', function () {
        $card = hraRemote("format: 1\nscan: complete\ntool|AnyDesk|installed,running\ntool|TeamViewer|installed\ntool|UltraViewer|running");

        expect($card['title'])->toBe('Remote-access tools')
            ->and($card['summary'])->toBe('3 remote-access tools found. Check that you recognise them.')
            ->and($card['rows'])->toBe([
                ['label' => 'AnyDesk', 'value' => 'Installed and running', 'tone' => 'neutral'],
                ['label' => 'TeamViewer', 'value' => 'Installed', 'tone' => 'neutral'],
                ['label' => 'UltraViewer', 'value' => 'Running, not installed (a downloaded copy)', 'tone' => 'neutral'],
            ])
            ->and($card['list'])->toBeNull();
    });

    it('says it once for a single tool and words the notes as a check, not as malware', function () {
        $card = hraRemote("format: 1\nscan: complete\ntool|TeamViewer|installed");
        $notes = implode(' ', $card['notes']);

        expect($card['summary'])->toBe('1 remote-access tool found. Check that you recognise it.')
            ->and($notes)->toContain('Check that you or the client set these up on purpose')
            ->toContain('fixed list of known tools')->toContain('Only you see this')
            ->not->toContain('malware')->not->toContain('virus');
    });

    it('says when none of the known tools were found', function () {
        $card = hraRemote("format: 1\nscan: complete");

        expect($card['summary'])->toBe('None of the known remote-access tools were found.')
            ->and($card['rows'])->toBe([]);
    });

    it('maps names to the known spelling, merges duplicates and both ways of being found', function () {
        $card = hraRemote("format: 1\nscan: complete\ntool|anydesk|installed\ntool|ANYDESK|running\ntool|teamviewer|installed");

        expect($card['rows'])->toBe([
            ['label' => 'AnyDesk', 'value' => 'Installed and running', 'tone' => 'neutral'],
            ['label' => 'TeamViewer', 'value' => 'Installed', 'tone' => 'neutral'],
        ]);
    });

    it('never shows a name that is not on the known list', function (string $line) {
        $card = hraRemote("format: 1\nscan: complete\ntool|AnyDesk|installed\n{$line}");

        expect(array_column($card['rows'], 'label'))->toBe(['AnyDesk']);
    })->with([
        'unknown app' => ['tool|Secret Banking App|installed'],
        'user data' => ['tool|C:\\Users\\Jan\\evil.exe|running'],
        'control characters' => ["tool|Team\x07Viewer|installed"],
        'unknown state' => ['tool|TeamViewer|flying'],
        'empty state' => ['tool|TeamViewer|'],
        'extra field' => ['tool|TeamViewer|installed|x'],
        'no state' => ['tool|TeamViewer'],
        'other kind' => ['app|TeamViewer|installed'],
        'invalid utf-8' => ["tool|Team\xC3\x28|installed"],
        'unicode lookalike' => ["tool|T\u{0435}amViewer|installed"],
    ]);

    it('keeps a partial scan only when it found something, and says so', function () {
        $notes = implode(' ', hraRemote("format: 1\nscan: partial (time limit reached)\ntool|AnyDesk|running")['notes']);

        expect($notes)->toContain('time limit')
            ->and(hraRemote("format: 1\nscan: partial (time limit reached)"))->toBeNull();
    });

    it('is hidden when the tools could not be checked', function (string $body) {
        expect(hraRemote($body))->toBeNull();
    })->with([
        'could not check' => ["format: 1\nscan: could not check"],
        'no scan line' => ["format: 1\ntool|AnyDesk|installed"],
        'unknown scan' => ["format: 1\nscan: maybe\ntool|AnyDesk|installed"],
        'empty' => [''],
    ]);

    it('is hidden on an old report without the section', function () {
        expect(hraCard(windowsLaptopFixture(), 'remote-access'))->toBeNull()
            ->and(hraCard(macReportFixture(), 'remote-access'))->toBeNull();
    });

    it('lists every known tool at most once, however long the list', function () {
        $lines = implode("\n", array_fill(0, 500, 'tool|AnyDesk|installed'));

        expect(hraRemote("format: 1\nscan: complete\n{$lines}")['rows'])->toHaveCount(1);
    });
});

describe('the network settings card', function () {
    it('shows a clean hosts file, no proxy and the router as DNS', function () {
        $card = hraNetwork(hraNetworkBody());

        expect($card['title'])->toBe('Network settings')
            ->and($card['rows'])->toBe([
                ['label' => 'Hosts file', 'value' => 'No extra entries', 'tone' => 'good'],
                ['label' => 'Proxy', 'value' => 'Off', 'tone' => 'good'],
                ['label' => 'DNS servers', 'value' => '192.168.1.1 (home network or router)', 'tone' => 'neutral'],
            ]);
    });

    it('flags common sites the hosts file sends elsewhere', function () {
        $card = hraNetwork(hraNetworkBody(hosts: "hosts: complete\nhosts-entries: 4\nhosts-redirected: google.com, ing.nl"));

        expect($card['rows'][0])->toBe([
            'label' => 'Hosts file',
            'value' => '4 extra entries. Sends google.com and ing.nl to another address: check the hosts file.',
            'tone' => 'bad',
        ]);
    });

    it('counts extra entries and names the common sites it only blocks', function () {
        expect(hraNetwork(hraNetworkBody(hosts: "hosts: complete\nhosts-entries: 1"))['rows'][0]['value'])->toBe('1 extra entry')
            ->and(hraNetwork(hraNetworkBody(hosts: "hosts: complete\nhosts-entries: 12\nhosts-blocked: microsoft.com"))['rows'][0])
            ->toBe(['label' => 'Hosts file', 'value' => '12 extra entries, blocking microsoft.com', 'tone' => 'neutral']);
    });

    it('never shows a site that is not on the watched list', function () {
        $card = hraNetwork(hraNetworkBody(hosts: "hosts: complete\nhosts-entries: 3\nhosts-redirected: secret-intranet.example, GOOGLE.COM, <user>.nl\nhosts-blocked: tracker.example"));

        expect($card['rows'][0]['value'])->toBe('3 extra entries. Sends google.com to another address: check the hosts file.')
            ->and(json_encode($card))->not->toContain('secret-intranet')->not->toContain('tracker.example');
    });

    it('reads entry counts the way the locale prints them and refuses nonsense', function (string $count, ?string $value) {
        $card = hraNetwork(hraNetworkBody(hosts: "hosts: complete\nhosts-entries: {$count}"));
        $row = collect($card['rows'])->firstWhere('label', 'Hosts file');

        expect($row['value'] ?? null)->toBe($value);
    })->with([
        ['1.234', '1,234 extra entries'],
        ['1,234', '1,234 extra entries'],
        ['-3', null],
        ['abc', null],
        ['', null],
        ['99999999999', null],
        ['2.5', null],
    ]);

    it('shows a proxy and where it comes from', function (string $proxy, string $value) {
        expect(collect(hraNetwork(hraNetworkBody(proxy: $proxy))['rows'])->firstWhere('label', 'Proxy'))
            ->toBe(['label' => 'Proxy', 'value' => $value, 'tone' => 'bad']);
    })->with([
        'host' => ["proxy: on\nproxy-host: proxy.example.com:8080", 'On, through proxy.example.com:8080'],
        'script' => ["proxy: on\nproxy-script-host: wpad.example.net", 'On, set up by a script from wpad.example.net'],
        'both' => ["proxy: on\nproxy-host: 10.0.0.5:3128\nproxy-script-host: wpad.example.net", 'On, through 10.0.0.5:3128 (set up by a script from wpad.example.net)'],
        'no host' => ['proxy: on', 'On'],
        'ipv6' => ["proxy: on\nproxy-host: [::1]:8080", 'On, through [::1]:8080'],
    ]);

    it('drops a proxy host it cannot trust', function (string $host) {
        expect(collect(hraNetwork(hraNetworkBody(proxy: "proxy: on\nproxy-host: {$host}"))['rows'])->firstWhere('label', 'Proxy')['value'])->toBe('On');
    })->with([
        'credentials' => ['user:secret@proxy.example.com:8080'],
        'path' => ['proxy.example.com/path?token=1'],
        'spaces' => ['proxy example com'],
        'masked' => ['<user>-pc:8080'],
        'too long' => [str_repeat('a', 101)],
        'control' => ["prox\x07y:80"],
        'unicode' => ["pr\u{f6}xy.example:80"],
    ]);

    it('explains a proxy without calling it malware outright', function () {
        $notes = implode(' ', hraNetwork(hraNetworkBody(proxy: "proxy: on\nproxy-host: p.example:80"))['notes']);

        expect($notes)->toContain('company or school')->toContain('Only you see this');
    });

    it('names known public DNS services and the private ranges', function (string $ip, string $value) {
        expect(hraNetwork(hraNetworkBody(dns: "dns: complete\ndns-server: {$ip}"))['rows'][2]['value'])->toBe($value);
    })->with([
        ['8.8.8.8', '8.8.8.8 (Google)'],
        ['8.8.4.4', '8.8.4.4 (Google)'],
        ['2001:4860:4860::8888', '2001:4860:4860::8888 (Google)'],
        ['1.1.1.1', '1.1.1.1 (Cloudflare)'],
        ['1.0.0.3', '1.0.0.3 (Cloudflare)'],
        ['2606:4700:4700::1111', '2606:4700:4700::1111 (Cloudflare)'],
        ['9.9.9.9', '9.9.9.9 (Quad9)'],
        ['149.112.112.112', '149.112.112.112 (Quad9)'],
        ['2620:fe::fe', '2620:fe::fe (Quad9)'],
        ['208.67.222.222', '208.67.222.222 (OpenDNS)'],
        ['208.67.220.220', '208.67.220.220 (OpenDNS)'],
        ['10.0.0.1', '10.0.0.1 (home network or router)'],
        ['172.16.0.1', '172.16.0.1 (home network or router)'],
        ['172.31.255.254', '172.31.255.254 (home network or router)'],
        ['192.168.178.1', '192.168.178.1 (home network or router)'],
        ['127.0.0.1', '127.0.0.1 (this computer)'],
        ['::1', '::1 (this computer)'],
        ['169.254.1.1', '169.254.1.1 (home network or router)'],
        ['100.100.100.100', '100.100.100.100 (home network or router)'],
        ['fe80::1', 'fe80::1 (home network or router)'],
        ['fe80::1%en0', 'fe80::1 (home network or router)'],
        ['fd00::1', 'fd00::1 (home network or router)'],
        ['FE80::ABCD', 'fe80::abcd (home network or router)'],
        ['172.32.0.1', '172.32.0.1 (not recognised)'],
        ['195.121.1.34', '195.121.1.34 (not recognised)'],
    ]);

    it('explains an unknown DNS server gently', function () {
        $card = hraNetwork(hraNetworkBody(dns: "dns: complete\ndns-server: 195.121.1.34"));
        $notes = implode(' ', $card['notes']);

        expect($card['rows'][2]['tone'])->toBe('neutral')
            ->and($notes)->toContain('internet provider')->not->toContain('malware');
    });

    it('lists DNS servers once each, at most four, and skips what is not an address', function () {
        $dns = "dns: complete\ndns-server: 8.8.8.8\ndns-server: 8.8.8.8\ndns-server: not-an-ip\ndns-server: 999.1.1.1\ndns-server:\ndns-server: 1.1.1.1\ndns-server: 9.9.9.9\ndns-server: 10.0.0.1\ndns-server: 10.0.0.2";
        $row = collect(hraNetwork(hraNetworkBody(dns: $dns))['rows'])->firstWhere('label', 'DNS servers');

        expect($row['value'])->toBe('8.8.8.8 (Google), 1.1.1.1 (Cloudflare), 9.9.9.9 (Quad9), 10.0.0.1 (home network or router)');
    });

    it('gives every row its own label, because the page keys rows by label', function () {
        $card = hraNetwork(hraNetworkBody(hosts: "hosts: complete\nhosts-entries: 2", proxy: "proxy: on\nproxy-host: p.example:80", dns: "dns: complete\ndns-server: 8.8.8.8\ndns-server: 1.1.1.1"));
        $labels = array_column($card['rows'], 'label');

        expect($labels)->toBe(array_values(array_unique($labels)))->toBe(['Hosts file', 'Proxy', 'DNS servers']);
    });

    it('skips the site-local placeholders Windows lists, as a real Windows scan printed them', function () {
        $dns = "dns: complete\ndns-server: fec0:0:0:ffff::1\ndns-server: fec0:0:0:ffff::2\ndns-server: fec0:0:0:ffff::3\ndns-server: 1.1.1.1\ndns-server: 1.0.0.1";
        $card = hraNetwork(hraNetworkBody(hosts: "hosts: complete\nhosts-entries: 3", dns: $dns));

        expect($card['rows'])->toBe([
            ['label' => 'Hosts file', 'value' => '3 extra entries', 'tone' => 'neutral'],
            ['label' => 'Proxy', 'value' => 'Off', 'tone' => 'good'],
            ['label' => 'DNS servers', 'value' => '1.1.1.1 (Cloudflare), 1.0.0.1 (Cloudflare)', 'tone' => 'neutral'],
        ]);
    });

    it('says a count from a hosts file that was too long to read whole is a minimum', function () {
        $partial = 'hosts: partial (only the first 50000 lines were read)';

        expect(hraNetwork(hraNetworkBody(hosts: "{$partial}\nhosts-entries: 50000\nhosts-blocked: microsoft.com"))['rows'][0])
            ->toBe(['label' => 'Hosts file', 'value' => 'At least 50,000 extra entries, blocking microsoft.com', 'tone' => 'neutral'])
            ->and(hraNetwork(hraNetworkBody(hosts: "{$partial}\nhosts-entries: 0"))['rows'][0])
            ->toBe(['label' => 'Hosts file', 'value' => 'No extra entries in the part that was read', 'tone' => 'neutral'])
            ->and(hraNetwork(hraNetworkBody(hosts: "{$partial}\nhosts-entries: 7\nhosts-redirected: ing.nl"))['rows'][0]['value'])
            ->toBe('At least 7 extra entries. Sends ing.nl to another address: check the hosts file.')
            ->and(implode(' ', hraNetwork(hraNetworkBody(hosts: "{$partial}\nhosts-entries: 7"))['notes']))->toContain('too long to read whole');
    });

    it('leaves out each part that could not be checked', function () {
        $card = hraNetwork(hraNetworkBody(hosts: 'hosts: could not check', proxy: 'proxy: could not check'));

        expect(array_column($card['rows'], 'label'))->toBe(['DNS servers']);
    });

    it('leaves out the hosts row when the count is missing and DNS when nothing usable was read', function () {
        $card = hraNetwork(hraNetworkBody(hosts: 'hosts: complete', dns: "dns: complete\ndns-server: nope"));

        expect(array_column($card['rows'], 'label'))->toBe(['Proxy']);
    });

    it('is hidden when nothing could be checked', function (string $body) {
        expect(hraNetwork($body))->toBeNull();
    })->with([
        'all failed' => ["format: 1\nhosts: could not check\nproxy: could not check\ndns: could not check"],
        'only format' => ['format: 1'],
        'empty' => [''],
        'garbage' => ["format: 1\nhosts: complete\nhosts-entries: lots\nproxy: maybe\ndns: complete\ndns-server: x"],
    ]);

    it('is hidden on an old report without the section', function () {
        expect(hraCard(windowsLaptopFixture(), 'network-settings'))->toBeNull()
            ->and(hraCard(macReportFixture(), 'network-settings'))->toBeNull();
    });
});

describe('the antivirus programs card', function () {
    it('flags two antivirus programs that are active at the same time', function () {
        $card = hraAntivirus("format: 1\nstatus: complete\nproduct|Norton 360|on\nproduct|McAfee LiveSafe|on\nproduct|Windows Defender|off");

        expect($card['title'])->toBe('Antivirus programs')
            ->and($card['summary'])->toBe('2 antivirus programs are active at the same time')
            ->and($card['rows'])->toBe([
                ['label' => 'Norton 360', 'value' => 'Active', 'tone' => 'bad'],
                ['label' => 'McAfee LiveSafe', 'value' => 'Active', 'tone' => 'bad'],
                ['label' => 'Windows Defender', 'value' => 'Not active', 'tone' => 'neutral'],
            ])
            ->and(implode(' ', $card['notes']))->toContain('Keep one');
    });

    it('is calm with one active program', function () {
        $card = hraAntivirus("format: 1\nstatus: complete\nproduct|Windows Defender|on");

        expect($card['summary'])->toBe('1 antivirus program is active')
            ->and($card['rows'])->toBe([['label' => 'Windows Defender', 'value' => 'Active', 'tone' => 'good']])
            ->and(implode(' ', $card['notes']))->not->toContain('Keep one')->toContain('Only you see this');
    });

    it('says when none is active', function () {
        expect(hraAntivirus("format: 1\nstatus: complete\nproduct|Windows Defender|off")['summary'])->toBe('No antivirus program is active');
    });

    it('counts a product listed twice once', function () {
        $card = hraAntivirus("format: 1\nstatus: complete\nproduct|Norton 360|on\nproduct|norton 360|off\nproduct|Windows Defender|off");

        expect($card['summary'])->toBe('1 antivirus program is active')
            ->and(array_column($card['rows'], 'value'))->toBe(['Active', 'Not active']);
    });

    it('cleans names and drops lines it cannot read', function () {
        $card = hraAntivirus("format: 1\nstatus: complete\nproduct|Good\tAV\x07|on\nproduct||on\nproduct|Weird|maybe\nproduct|Extra|on|x\nother|Thing|on\nproduct|Caf\u{e9} AV|off");

        expect(array_column($card['rows'], 'label'))->toBe(['Good AV', "Caf\u{e9} AV"]);
    });

    it('lists at most six programs', function () {
        $lines = implode("\n", array_map(fn (int $i): string => "product|AV {$i}|off", range(1, 20)));

        expect(hraAntivirus("format: 1\nstatus: complete\n{$lines}")['rows'])->toHaveCount(6);
    });

    it('is hidden when the list is not available or could not be read', function (string $body) {
        expect(hraAntivirus($body))->toBeNull();
    })->with([
        'server' => ["format: 1\nstatus: not available\nproduct|X|on"],
        'failed' => ["format: 1\nstatus: could not check"],
        'no products' => ["format: 1\nstatus: complete"],
        'no status' => ["format: 1\nproduct|X|on"],
        'empty' => [''],
    ]);

    it('is hidden on an old report and on a Mac', function () {
        expect(hraCard(windowsLaptopFixture(), 'antivirus-products'))->toBeNull()
            ->and(hraCard(macReportFixture(), 'antivirus-products'))->toBeNull();
    });
});

describe('needs attention', function () {
    it('is urgent when the hosts file sends a common site elsewhere', function () {
        $texts = hraReasonTexts(windowsLaptopFixture().hraSection('Network settings', hraNetworkBody(hosts: "hosts: complete\nhosts-entries: 2\nhosts-redirected: paypal.com")));

        expect($texts)->toContain('urgent: The hosts file sends paypal.com to another address');
    });

    it('asks to check a proxy, two antivirus programs and an unexpected remote-access tool', function () {
        $markdown = windowsLaptopFixture()
            .hraSection('Network settings', hraNetworkBody(proxy: "proxy: on\nproxy-host: p.example:80"))
            .hraSection('Antivirus products', "format: 1\nstatus: complete\nproduct|Norton|on\nproduct|Avast|on")
            .hraSection('Remote access tools', "format: 1\nscan: complete\ntool|AnyDesk|installed\ntool|UltraViewer|running\ntool|RustDesk|installed");

        expect(hraReasonTexts($markdown))
            ->toContain('soon: A proxy server is set: check that it is wanted')
            ->toContain('soon: 2 antivirus programs are active at the same time')
            ->toContain('soon: Remote-access tools found (AnyDesk, UltraViewer): check they are recognised');
    });

    it('does not flag RustDesk alone, blocked sites, unknown DNS or one antivirus', function () {
        $markdown = windowsLaptopFixture()
            .hraSection('Network settings', hraNetworkBody(hosts: "hosts: complete\nhosts-entries: 9\nhosts-blocked: microsoft.com", dns: "dns: complete\ndns-server: 195.121.1.34"))
            .hraSection('Antivirus products', "format: 1\nstatus: complete\nproduct|Norton|on\nproduct|Windows Defender|off")
            .hraSection('Remote access tools', "format: 1\nscan: complete\ntool|RustDesk|installed,running");

        $texts = implode("\n", hraReasonTexts($markdown));

        expect($texts)->not->toContain('hosts')->not->toContain('proxy')->not->toContain('antivirus')->not->toContain('Remote-access')->not->toContain('DNS');
    });

    it('adds nothing for an old report without the sections', function () {
        $texts = implode("\n", hraReasonTexts(windowsLaptopFixture()));

        expect($texts)->not->toContain('Remote-access')->not->toContain('hosts file')->not->toContain('proxy');
    });

    it('shows the reasons on the owner list', function () {
        $reasons = hraReasons(windowsLaptopFixture().hraSection('Network settings', hraNetworkBody(hosts: "hosts: complete\nhosts-entries: 2\nhosts-redirected: ing.nl")));

        expect(array_column($reasons, 'text'))->toContain('The hosts file sends ing.nl to another address');
    });
});

describe('who sees the cards', function () {
    function hraMarkdown(): string
    {
        return windowsLaptopFixture()
            .hraSection('Remote access tools', "format: 1\nscan: complete\ntool|AnyDesk|installed,running")
            .hraSection('Network settings', hraNetworkBody(hosts: "hosts: complete\nhosts-entries: 2\nhosts-redirected: ing.nl", proxy: "proxy: on\nproxy-host: evilproxy.example:8080"))
            .hraSection('Antivirus products', "format: 1\nstatus: complete\nproduct|SecretShield AV|on\nproduct|Windows Defender|on");
    }

    it('gives the owner the three cards in the camelCase detailCards prop', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create(['device_type' => 'laptop']);
        Report::factory()->for($machine)->create(['markdown' => hraMarkdown()]);
        $ids = [];

        actingAs($user)->get("/machines/{$machine->id}")->assertOk()->assertInertia(function ($page) use (&$ids): void {
            $ids = array_column($page->toArray()['props']['detailCards'], 'id');
        });

        expect($ids)->toContain('remote-access')->toContain('network-settings')->toContain('antivirus-products')
            ->and(array_search('antivirus-products', $ids, true))->toBeLessThan(array_search('verdict', $ids, true));
    });

    it('never gives the cards, the names or the raw sections to a share page', function () {
        $client = Client::factory()->withShareLink()->create();
        $machine = Machine::factory()->for($client->user)->create(['client_id' => $client->id, 'device_type' => 'laptop']);
        Report::factory()->for($machine)->create(['markdown' => hraMarkdown(), 'flags' => ['FileVault is OFF.']]);

        foreach (["/c/{$client->share_token}", "/c/{$client->share_token}/machines/{$machine->id}"] as $path) {
            $response = $this->get($path)->assertOk();
            $props = '';
            $response->assertInertia(function ($page) use (&$props): void {
                $props = (string) json_encode($page->toArray()['props']);
            });

            foreach ([$props, (string) $response->getContent()] as $body) {
                foreach (['AnyDesk', 'evilproxy', 'ing.nl', 'SecretShield', 'Remote-access', 'remote-access', 'Network settings', 'network-settings', 'Antivirus programs', 'antivirus-products', 'hosts file', 'proxy server', 'detailCards'] as $hidden) {
                    expect($body)->not->toContain($hidden);
                }
            }
        }
    });

    it('does not show one account the cards of another', function () {
        $mine = User::factory()->create();
        $theirs = Machine::factory()->for(User::factory()->create())->create(['device_type' => 'laptop']);
        Report::factory()->for($theirs)->create(['markdown' => hraMarkdown()]);

        actingAs($mine)->get("/machines/{$theirs->id}")->assertForbidden();
        actingAs($mine)->get('/machines?attention=all')->assertOk()->assertInertia(function ($page) use ($theirs): void {
            expect(collect($page->toArray()['props']['needsAttention']['rows'])->pluck('id'))->not->toContain($theirs->id);
        });
    });
});

describe('masking in the new sections', function () {
    it('still replaces the account name in the new sections when a report is imported', function () {
        $text = "# Windows checkup: TEST-PC\n\n- Format: 1\n- OS: Windows\n- Device type: desktop\n- Serial: HRAMASK0001\n- Model: Test\n\n"
            .hraSection('Startup programs', 'C:\\Users\\Johannes\\AppData\\x.exe')
            .hraSection('Network settings', hraNetworkBody(proxy: "proxy: on\nproxy-host: johannes-pc:8080"))
            .hraSection('Antivirus products', "format: 1\nstatus: complete\nproduct|Johannes Shield|on");

        $markdown = (new ImportReport)->handle(User::factory()->create(), $text)->markdown;
        $cards = array_column(app(ReportDetails::class)->cards($markdown, [], 'desktop'), null, 'id');

        expect($markdown)->not->toContain('Johannes')->not->toContain('johannes')
            ->and(collect($cards['network-settings']['rows'])->firstWhere('label', 'Proxy')['value'])->toBe('On')
            ->and($cards['antivirus-products']['rows'][0]['label'])->toBe('<user> Shield');
    });
});
