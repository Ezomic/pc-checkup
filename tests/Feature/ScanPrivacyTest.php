<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Machine;
use App\Models\Report;
use App\Models\User;
use App\Services\Reports\ReportDetails;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Tests\Support\ScriptTemp;

use function Pest\Laravel\actingAs;

function spsMissing(): bool
{
    return PHP_OS_FAMILY === 'Windows' || trim((string) shell_exec('command -v perl 2>/dev/null')) === '';
}

function spsMac(): string
{
    return (string) file_get_contents(base_path('scripts/mac-checkup.sh'));
}

function spsWindows(): string
{
    return (string) file_get_contents(base_path('scripts/windows-checkup.ps1'));
}

function spsMacFunction(string $name): string
{
    preg_match('/^'.preg_quote($name, '/').'\(\) \{.*?^\}$/ms', spsMac(), $match);

    expect($match)->not->toBeEmpty();

    return $match[0];
}

function spsWindowsSection(string $title): string
{
    preg_match('/^Add-Section \''.preg_quote($title, '/').'\' \{.*?^\}$/ms', spsWindows(), $match);

    expect($match)->not->toBeEmpty();

    return $match[0];
}

/**
 * @param  array{0: string, 1: int}  $admin
 * @param  array{0: string, 1: int}  $system
 * @param  array{0: string, 1: int}  $user
 * @param  array<string, string>  $env
 */
function spsTrust(array $admin, array $system, array $user, array $env = []): ProcessResult
{
    $dir = ScriptTemp::dir('pcc-sps-out');
    $body = "case \"\${2:-}\" in\n";

    foreach (['-d' => $admin, '-s' => $system, '*' => $user] as $flag => [$text, $exit]) {
        $file = $dir.'/'.md5($flag).'.txt';
        file_put_contents($file, $text);
        $body .= "{$flag}) cat ".escapeshellarg($file)."; exit {$exit} ;;\n";
    }

    $bin = ScriptTemp::bin('pcc-sps-bin', ['security' => $body.'esac']);

    return Process::path(base_path())->timeout(60)->env(array_merge(['PATH' => $bin.':'.getenv('PATH')], $env))
        ->run(['bash', '-c', 'source scripts/mac-checkup.sh; pcc_trust_report']);
}

/**
 * @return list<string>
 */
function spsLines(ProcessResult $result): array
{
    return array_values(array_filter(explode("\n", trim($result->throw()->output())), fn (string $line): bool => $line !== ''));
}

function spsTrusted(string ...$names): string
{
    $lines = ['Number of trusted certs = '.count($names)];

    foreach ($names as $index => $name) {
        $lines[] = "Cert {$index}: {$name}";
        $lines[] = '   Number of trust settings : 1';
        $lines[] = '   Trust Setting 0:';
        $lines[] = '      Result Type           : kSecTrustSettingsResultTrustRoot';
    }

    return implode("\n", $lines)."\n";
}

function spsNoTrust(): string
{
    return "SecTrustSettingsCopyCertificates: No Trust Settings were found.\n";
}

function spsSection(string $title, string $body): string
{
    return "\n## {$title}\n\n```\n{$body}\n```\n";
}

/**
 * The sections older scripts stored, each holding something only an old report can contain.
 */
function spsOldSections(): string
{
    return spsSection('Wi-Fi', "Wi-Fi:\n      Current Network Information:\n        HomeNet-Secret:\n          Signal / Noise: -55 dBm / -92 dBm\n      Other Local Wi-Fi Networks:\n        Neighbour-Net:\n          Channel: 6")
        .spsSection('DNS and proxy', "DNS configuration\nresolver #1\n  search domain[0] : secret-corp.lan\n  nameserver[0] : 10.9.9.9\n<dictionary> {\n  HTTPProxy : secretproxy.lan\n}")
        .spsSection('Hosts file (non-default lines)', "6.6.6.6 secret-hosts-line.example\n10.0.0.5 nas.secret-home")
        .spsSection('Privacy permissions (TCC), needs Full Disk Access for Terminal', "== /Users/x/Library/Application Support/com.apple.TCC/TCC.db\nkTCCServiceScreenCapture|com.secret.spyapp|allowed")
        .spsSection('Trusted certificates: custom trust settings', "-- admin\nCert 0: Secret MITM Root CA\n-- system\nNo Trust Settings were found.\n-- user\nNo Trust Settings were found.")
        .spsSection('Certificates in System keychain', '    "labl"<blob>="Secret Corp Device CA"')
        .spsSection('Saved Wi-Fi networks and VPNs', "Preferred networks on en0:\n\tSecret-Saved-Net\n\nAvailable network connection services in the current set (*=enabled):\n* (Disconnected) 1A2B PPP --> L2TP \"Secret Work VPN\"");
}

/**
 * @return list<string>
 */
function spsOldSecrets(): array
{
    return ['HomeNet-Secret', 'Neighbour-Net', 'secret-corp.lan', 'secretproxy', 'secret-hosts-line', 'nas.secret-home', 'com.secret.spyapp', 'Secret MITM Root CA', 'Secret Corp Device CA', 'Secret-Saved-Net', 'Secret Work VPN'];
}

describe('the Mac script', function () {
    it('no longer has the sections that listed networks, certificates, permissions or hosts file lines', function (string $gone) {
        expect(spsMac())->not->toContain($gone);
    })->with([
        'nearby networks' => 'sh_section "Wi-Fi" ',
        'raw airport dump' => 'SPAirPortDataType | head',
        'saved networks section' => 'Saved Wi-Fi networks and VPNs',
        'saved networks command' => 'listpreferredwirelessnetworks',
        'VPN names' => 'scutil --nc',
        'keychain section' => 'Certificates in System keychain',
        'keychain command' => 'find-certificate',
        'privacy permissions section' => 'Privacy permissions (TCC)',
        'privacy permissions database' => 'TCC.db',
        'privacy permission services' => 'kTCCService',
        'raw hosts file section' => 'Hosts file (non-default lines)',
        'raw dns and proxy section' => 'sh_section "DNS and proxy"',
        'raw dns dump' => 'scutil --dns | head',
    ]);

    it('reads Wi-Fi, DNS, proxy and the hosts file only inside the summary steps', function () {
        $rest = str_replace([
            spsMacFunction('pcc_wifi_report'),
            spsMacFunction('pcc_proxy_summary'),
            spsMacFunction('pcc_dns_summary'),
        ], '', spsMac());

        expect($rest)->not->toContain('SPAirPortDataType')->not->toContain('scutil --dns')->not->toContain('scutil --proxy')
            ->and(substr_count(spsMac(), '/etc/hosts'))->toBe(1)
            ->and(spsMac())->toContain('PCC_HOSTS_FILE="${PCC_HOSTS_FILE:-/etc/hosts}"');
    });

    it('keeps the Wi-Fi signal and network settings steps, the update check and the privacy notice', function () {
        expect(spsMac())->toContain('section "Wi-Fi signal" pcc_wifi_report')
            ->toContain('section "Network settings" pcc_network_report')
            ->toContain("\npcc_update_check || exit 1")
            ->and(substr_count(spsMac(), 'EN: This scan only reads'))->toBe(1)
            ->and(spsMacFunction('pcc_wifi_report'))->toContain('pcc_capped "$PCC_WIFI_CAP" system_profiler SPAirPortDataType');
    });

    it('records only how many certificates have custom trust settings, read once and capped in time', function () {
        expect(spsMac())->toContain("\nTRUST_REPORT=\$(pcc_trust_report)\n")
            ->toContain('section "Trusted certificates: custom trust settings" echo "$TRUST_REPORT"')
            ->and(substr_count(spsMac(), 'security dump-trust-settings'))->toBe(1)
            ->and(spsMacFunction('pcc_trust_report'))->toContain('pcc_capped "$PCC_TRUST_CAP" security dump-trust-settings')
            ->not->toContain('find-certificate');
    });

    it('flags admin-trusted certificates from that count only', function (string $report, bool $flagged) {
        preg_match('/^.*"\$TRUST_REPORT".*&& flag .*$/m', spsMac(), $line);

        expect($line)->not->toBeEmpty();

        $output = Process::run(['bash', '-c', 'flag() { echo "FLAG: $1"; }; TRUST_REPORT=$1; '.$line[0], 'pcc', $report])->output();

        expect($output)->toBe($flagged ? "FLAG: Custom admin-trusted certificates are installed (see Trusted certificates section).\n" : '');
    })->with([
        'some' => ["format: 1\nadmin: 2\nsystem: 0\nuser: 0", true],
        'ten' => ["format: 1\nadmin: 10\nsystem: 0\nuser: 0", true],
        'none' => ["format: 1\nadmin: 0\nsystem: 4\nuser: 1", false],
        'could not check' => ["format: 1\nadmin: could not check\nsystem: 0\nuser: 0", false],
        'prefixed' => ["format: 1\nxadmin: 5", false],
        'empty' => ['', false],
    ]);
});

describe('the Mac trusted certificates step', function () {
    it('prints a count per trust domain and never a certificate name', function () {
        $result = spsTrust([spsTrusted('Secret MITM Root CA', 'Corp Proxy CA'), 0], [spsNoTrust(), 1], [spsTrusted('Charles Proxy CA'), 0]);

        expect(spsLines($result))->toBe(['format: 1', 'admin: 2', 'system: 0', 'user: 1'])
            ->and($result->output())->not->toContain('Secret')->not->toContain('Corp')->not->toContain('Charles')->not->toContain('Cert ');
    })->skip(spsMissing(), 'perl is not installed here');

    it('counts nothing when no trust settings exist, whatever the exit code', function (int $exit) {
        expect(spsLines(spsTrust([spsNoTrust(), $exit], [spsNoTrust(), $exit], ['', 0])))->toBe(['format: 1', 'admin: 0', 'system: 0', 'user: 0']);
    })->with([0, 1])->skip(spsMissing(), 'perl is not installed here');

    it('says it could not check when the command fails', function () {
        $result = spsTrust(['security: SecTrustSettingsCopyCertificates: Secret error text', 1], ['', 127], [spsTrusted('Secret CA'), 1]);

        expect(spsLines($result))->toBe(['format: 1', 'admin: could not check', 'system: could not check', 'user: could not check'])
            ->and($result->output())->not->toContain('Secret');
    })->skip(spsMissing(), 'perl is not installed here');

    it('counts a certificate whose name looks like a message or another line once', function () {
        expect(spsLines(spsTrust([spsTrusted('No Trust Settings were found.', 'Cert 7: x'), 0], [spsNoTrust(), 1], [spsNoTrust(), 1])))
            ->toBe(['format: 1', 'admin: 2', 'system: 0', 'user: 0']);
    })->skip(spsMissing(), 'perl is not installed here');

    it('ignores lines that only look like a certificate', function () {
        $text = "Number of trusted certs = 1\nCert 0: Real\n   Cert 1: indented\nCert x: not a number\nXCert 2: prefixed\n";

        expect(spsLines(spsTrust([$text, 0], [spsNoTrust(), 1], [spsNoTrust(), 1])))->toBe(['format: 1', 'admin: 1', 'system: 0', 'user: 0']);
    })->skip(spsMissing(), 'perl is not installed here');

    it('stops at the time cap and leaves no process behind', function () {
        $bin = ScriptTemp::bin('pcc-sps-slow', ['security' => 'sleep 30']);
        $started = microtime(true);
        $result = Process::path(base_path())->timeout(60)->env(['PATH' => $bin.':'.getenv('PATH'), 'PCC_TRUST_CAP' => '1'])
            ->run(['bash', '-c', 'source scripts/mac-checkup.sh; pcc_trust_report']);

        expect(spsLines($result))->toBe(['format: 1', 'admin: could not check', 'system: could not check', 'user: could not check'])
            ->and(microtime(true) - $started)->toBeLessThan(15.0);
    })->skip(spsMissing(), 'perl is not installed here');
});

describe('the Windows script', function () {
    it('no longer prints the lines of the hosts file', function () {
        expect(spsWindows())->not->toContain('Hosts file (non-default lines)')
            ->and(substr_count(spsWindows(), 'drivers\etc\hosts'))->toBe(1)
            ->and(spsWindowsSection('Network settings'))->toContain('drivers\etc\hosts')->toContain('Get-HostsSummaryLines');
    });

    it('reads no saved networks, VPNs or certificate stores', function (string $gone) {
        expect(strtolower(spsWindows()))->not->toContain(strtolower($gone));
    })->with(['wlan show profile', 'wlan show networks', 'Cert:\\', 'certutil', 'Get-VpnConnection', 'rasphone', 'Get-NetConnectionProfile', 'Get-NetNeighbor', 'NetworkList\\Profiles']);

    it('keeps the Wi-Fi signal and network settings sections', function () {
        expect(spsWindows())->toContain("Add-Section 'Wi-Fi signal'")->toContain("Add-Section 'Network settings'");
    });
});

describe('reports from older scripts', function () {
    it('give the same cards with or without the removed sections', function (string $fixture) {
        $base = $fixture === 'mac' ? macReportFixture() : windowsLaptopFixture();
        $details = app(ReportDetails::class);

        expect($details->cards($base.spsOldSections(), [], 'laptop'))->toBe($details->cards($base, [], 'laptop'));
    })->with(['mac', 'windows']);

    it('still open for the owner, with the stored report unchanged', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create(['device_type' => 'laptop']);
        $markdown = macReportFixture().spsOldSections();
        Report::factory()->for($machine)->create(['markdown' => $markdown]);

        actingAs($user)->get("/machines/{$machine->id}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('report.markdown', $markdown)
            ->has('detailCards'));
    });

    it('never show the removed sections on a share page', function () {
        $client = Client::factory()->withShareLink()->create();
        $machine = Machine::factory()->for($client->user)->create(['client_id' => $client->id, 'device_type' => 'laptop']);
        Report::factory()->for($machine)->create(['markdown' => macReportFixture().spsOldSections()]);

        foreach (["/c/{$client->share_token}", "/c/{$client->share_token}/machines/{$machine->id}"] as $path) {
            $body = (string) $this->get($path)->assertOk()->getContent();

            foreach (spsOldSecrets() as $secret) {
                expect($body)->not->toContain($secret);
            }
        }
    });

    it('are not shown to another account', function () {
        $theirs = Machine::factory()->for(User::factory()->create())->create(['device_type' => 'laptop']);
        Report::factory()->for($theirs)->create(['markdown' => macReportFixture().spsOldSections()]);

        actingAs(User::factory()->create())->get("/machines/{$theirs->id}")->assertForbidden();
    });
});

describe('reports from the new scripts', function () {
    it('show no Wi-Fi card instead of an empty or negative one when there is no signal', function (string $body) {
        $cards = app(ReportDetails::class)->cards(macReportFixture().spsSection('Wi-Fi signal', $body), [], 'laptop');

        expect(array_column($cards, 'id'))->not->toContain('wifi')
            ->and((string) json_encode($cards))->not->toContain('n/a');
    })->with([
        'not connected' => ["format: 1\nstatus: not connected or could not check"],
        'could not check' => ["format: 1\nstatus: could not check"],
        'empty' => [''],
        'positive dBm' => ["format: 1\nsignal-dbm: 55"],
    ]);

    it('show the trusted certificates counts nowhere on a share page', function () {
        $client = Client::factory()->withShareLink()->create();
        $machine = Machine::factory()->for($client->user)->create(['client_id' => $client->id, 'device_type' => 'laptop']);
        Report::factory()->for($machine)->create(['markdown' => macReportFixture().spsSection('Trusted certificates: custom trust settings', "format: 1\nadmin: 3\nsystem: 0\nuser: 0")]);

        $body = (string) $this->get("/c/{$client->share_token}/machines/{$machine->id}")->assertOk()->getContent();

        expect($body)->not->toContain('Trusted certificates')->not->toContain('admin: 3');
    });
});
