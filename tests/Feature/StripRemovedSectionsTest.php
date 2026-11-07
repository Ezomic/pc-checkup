<?php

declare(strict_types=1);

use App\Actions\Machines\ImportReport;
use App\Models\Client;
use App\Models\Machine;
use App\Models\Report;
use App\Services\Reports\RemovedSectionStripper;
use App\Services\Reports\ReportDetails;
use App\Services\Reports\ReportParser;
use App\Services\Reports\ReportRedactor;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

const SRS_MAC_REMOVED = [
    'Wi-Fi',
    'DNS and proxy',
    'Hosts file (non-default lines)',
    'Privacy permissions (TCC), needs Full Disk Access for Terminal',
    'Trusted certificates: custom trust settings',
    'Certificates in System keychain',
    'Saved Wi-Fi networks and VPNs',
];

const SRS_WINDOWS_REMOVED = ['Hosts file (non-default lines)'];

const SRS_OLD_TRUST = "-- admin\nCert 0: Contoso Inspection Root CA\n-- system\nSecTrustSettingsCopyCertificates: No Trust Settings were found.\n-- user\nSecTrustSettingsCopyCertificates: No Trust Settings were found.";

/**
 * A stored report from the scripts as they were before PCC-73: Mac with the Wi-Fi signal (PCC-66)
 * and network settings (PCC-74) steps next to the old raw sections, Windows with the raw hosts file.
 */
function srsFixture(string $os): string
{
    return (string) file_get_contents(base_path($os === 'mac' ? 'tests/Fixtures/mac-checkup-old-privacy.md' : 'tests/Fixtures/windows-checkup-old-hosts.md'));
}

/**
 * @return list<string>
 */
function srsRemoved(string $os): array
{
    return $os === 'mac' ? SRS_MAC_REMOVED : SRS_WINDOWS_REMOVED;
}

/**
 * @return list<string>
 */
function srsPrivate(string $os): array
{
    return $os === 'mac'
        ? ['Ziggo4471920', 'Neighbour-Bakker', 'fritz.box', 'nas.bakker-thuis.lan', 'activate.example-vendor.com', 'kTCCService', 'us.zoom.xos', 'Contoso Inspection Root CA', 'com.apple.kerberos.kdc', 'Bakker-Gasten', 'Hotel-Amsterdam-Guest', 'Contoso Work VPN']
        : ['activate.adobe.com', 'printer.devries-thuis.lan'];
}

/**
 * The report with the named sections cut out the way a person would do it by hand: from the
 * heading through the closing fence and the blank line after it.
 *
 * @param  list<string>  $titles
 */
function srsByHand(string $markdown, array $titles): string
{
    $pattern = '/^## (?:'.implode('|', array_map(fn (string $title): string => preg_quote($title, '/'), $titles)).')\n\n```\n(?:(?!```)[^\n]*\n)*```\n\n/m';
    $cut = preg_replace($pattern, '', $markdown, -1, $count);

    expect($count)->toBe(count($titles));

    return (string) $cut;
}

/**
 * The same Mac as a scan from today's script would store it: the trust section holds counts only.
 */
function srsTodayMac(): string
{
    $markdown = str_replace(SRS_OLD_TRUST, "format: 1\nadmin: 1\nsystem: 0\nuser: 0", srsFixture('mac'));

    expect($markdown)->not->toBe(srsFixture('mac'));

    return srsByHand($markdown, array_values(array_diff(SRS_MAC_REMOVED, ['Trusted certificates: custom trust settings'])));
}

/**
 * @param  list<string>  $titles
 */
function srsLine(int $id, string $verb, array $titles): string
{
    return "Report {$id}: {$verb} \"".implode('", "', $titles).'"';
}

function srsImport(Client $client, string $markdown): Report
{
    $report = app(ImportReport::class)->handle($client->user, $markdown);
    $report->machine?->forceFill(['client_id' => $client->id])->save();

    return $report;
}

/**
 * @return array<string, mixed>
 */
function srsProps(string $url): array
{
    $props = [];
    // A share page switches the locale of the app the whole test shares; a real request starts fresh.
    app()->setLocale((string) config('app.fallback_locale'));

    test()->get($url)->assertOk()->assertInertia(function ($page) use (&$props): void {
        $props = $page->toArray()['props'];
    });

    return $props;
}

/**
 * Everything the owner page and both share pages hand to the browser, but the raw report text.
 *
 * @return array<string, array<string, mixed>>
 */
function srsPages(Machine $machine, Client $client): array
{
    actingAs($client->user);

    return [
        'owner' => Arr::except(srsProps("/machines/{$machine->id}"), ['report.markdown']),
        'share' => srsProps("/c/{$client->share_token}"),
        'share machine' => srsProps("/c/{$client->share_token}/machines/{$machine->id}"),
    ];
}

function srsReportWrites(Closure $run): int
{
    $writes = 0;

    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        $writes += preg_match('/^\s*update\s+["`]?reports["`]?\s/i', $query->sql);
    });

    $run();

    return $writes;
}

describe('old reports', function () {
    it('stand for stored reports, which the import already redacted', function (string $os) {
        expect((new ReportRedactor)->redact(srsFixture($os)))->toBe(srsFixture($os));
    })->with(['mac', 'windows']);

    it('lose exactly the old sections and keep every other byte', function (string $os) {
        $result = (new RemovedSectionStripper)->strip(srsFixture($os));

        expect($result)->toBe(['markdown' => srsByHand(srsFixture($os), srsRemoved($os)), 'removed' => srsRemoved($os), 'kept' => []]);

        foreach (srsPrivate($os) as $word) {
            expect(srsFixture($os))->toContain($word)
                ->and($result['markdown'])->not->toContain($word);
        }
    })->with(['mac', 'windows']);

    it('keep the newer sections that share words with the old ones', function (string $os, string $section) {
        expect(srsFixture($os))->toContain($section)
            ->and((new RemovedSectionStripper)->strip(srsFixture($os))['markdown'])->toContain($section);
    })->with([
        'Mac Wi-Fi signal' => ['mac', "## Wi-Fi signal\n\n```\nformat: 1\nsignal-dbm: -58\nband: 5 GHz\n```\n"],
        'Mac network settings' => ['mac', "## Network settings\n\n```\nformat: 1\nhosts: complete\nhosts-entries: 2\nproxy: off\ndns: complete\ndns-server: 192.168.178.1\n```\n"],
        'Mac remote access tools' => ['mac', "## Remote access tools\n\n```\nformat: 1\nscan: complete\ntool|TeamViewer|installed,running\n```\n"],
        'Mac network interfaces' => ['mac', "## Network interfaces\n\n```\n\nHardware Port: Wi-Fi\n"],
        'Windows Wi-Fi signal' => ['windows', "## Wi-Fi signal\n\n```\nformat: 1\nsignal-percent: 82\nband: 5 GHz\n```\n"],
        'Windows network settings' => ['windows', "## Network settings\n\n```\nformat: 1\nhosts: complete\nhosts-entries: 3\nproxy: off\n"],
    ]);

    it('give the same cards, flags and header after stripping', function (string $os) {
        $before = srsFixture($os);
        $after = (new RemovedSectionStripper)->strip($before)['markdown'];
        $flags = (new ReportParser)->parse($before)->flags;
        $cards = app(ReportDetails::class)->cards($before, $flags, 'laptop');

        expect($flags)->not->toBe([])
            ->and(array_column($cards, 'id'))->toContain('wifi', 'network-settings', 'remote-access', 'crashing-apps', 'security', 'reclaimable')
            ->and(app(ReportDetails::class)->cards($after, $flags, 'laptop'))->toBe($cards)
            ->and((new ReportParser)->parse($after))->toEqual((new ReportParser)->parse($before));
    })->with(['mac', 'windows']);

    it('are cut by titles the scripts of today no longer write, except the trust section told apart by its content', function () {
        preg_match_all('/^\s*(?:sh_)?section "([^"]+)"/m', (string) file_get_contents(base_path('scripts/mac-checkup.sh')), $mac);
        preg_match_all("/^Add-Section '([^']+)'/m", (string) file_get_contents(base_path('scripts/windows-checkup.ps1')), $windows);
        $today = [...$mac[1], ...$windows[1]];

        expect(count($today))->toBeGreaterThan(80)
            ->and(array_values(array_intersect(RemovedSectionStripper::TITLES, $today)))->toBe([])
            ->and($today)->toContain('Trusted certificates: custom trust settings', 'Wi-Fi signal', 'Network settings', 'Remote access tools')
            ->and([...RemovedSectionStripper::TITLES, 'Trusted certificates: custom trust settings'])->toEqualCanonicalizing(SRS_MAC_REMOVED);
    });
});

describe('reports:redact --strip-removed-sections', function () {
    it('strips old Mac and Windows reports, soft deleted ones and other accounts included, and every page shows the same', function () {
        $this->freezeTime();
        $client = Client::factory()->withShareLink()->create();
        $mac = srsImport($client, srsFixture('mac'));
        $windows = srsImport($client, srsFixture('windows'));
        $gone = Report::factory()->create(['markdown' => srsFixture('mac')]);
        $gone->delete();
        $pages = fn (): array => [srsPages($mac->machine, $client), srsPages($windows->machine, $client)];
        $before = $pages();
        $kept = Report::withTrashed()->orderBy('id')->get(['id', 'checksum', 'flags', 'updated_at', 'deleted_at'])->toArray();

        expect($mac->markdown)->toBe(srsFixture('mac'))
            ->and($windows->markdown)->toBe(srsFixture('windows'))
            ->and($gone->machine?->user_id)->not->toBe($client->user_id)
            ->and(array_column($before[0]['owner']['detailCards'], 'id'))->toContain('wifi', 'network-settings');

        $this->travel(5)->minutes();

        artisan('reports:redact', ['--strip-removed-sections' => true])
            ->expectsOutputToContain(srsLine($mac->id, 'removed', SRS_MAC_REMOVED))
            ->expectsOutputToContain(srsLine($windows->id, 'removed', SRS_WINDOWS_REMOVED))
            ->expectsOutputToContain(srsLine($gone->id, 'removed', SRS_MAC_REMOVED))
            ->expectsOutputToContain('Redacted 0 of 3 reports.')
            ->expectsOutputToContain('Removed old sections from 3 of 3 reports.')
            ->doesntExpectOutputToContain('kept')
            ->assertSuccessful();

        $this->travel(-5)->minutes();

        expect($mac->fresh()?->markdown)->toBe(srsByHand(srsFixture('mac'), SRS_MAC_REMOVED))
            ->and($windows->fresh()?->markdown)->toBe(srsByHand(srsFixture('windows'), SRS_WINDOWS_REMOVED))
            ->and(Report::withTrashed()->find($gone->id)?->markdown)->toBe(srsByHand(srsFixture('mac'), SRS_MAC_REMOVED))
            ->and(Report::withTrashed()->orderBy('id')->get(['id', 'checksum', 'flags', 'updated_at', 'deleted_at'])->toArray())->toBe($kept)
            ->and($pages())->toBe($before);
    });

    it('finds nothing to do the second time', function () {
        $report = Report::factory()->create(['markdown' => srsFixture('mac')]);

        artisan('reports:redact', ['--strip-removed-sections' => true])->assertSuccessful();
        $once = $report->fresh()?->markdown;

        $writes = srsReportWrites(fn () => artisan('reports:redact', ['--strip-removed-sections' => true])
            ->expectsOutputToContain('Redacted 0 of 1 reports.')
            ->expectsOutputToContain('Removed old sections from 0 of 1 reports.')
            ->doesntExpectOutputToContain('Report ')
            ->assertSuccessful());

        expect($writes)->toBe(0)->and($report->fresh()?->markdown)->toBe($once);
    });

    it('does not rewrite reports without these sections', function () {
        $markdowns = [srsTodayMac(), srsByHand(srsFixture('windows'), SRS_WINDOWS_REMOVED), windowsLaptopFixture()];
        $reports = array_map(fn (string $markdown): Report => Report::factory()->create(['markdown' => $markdown]), $markdowns);
        $updated = array_map(fn (Report $report): string => (string) $report->updated_at, $reports);
        $this->travel(5)->minutes();

        foreach ($markdowns as $markdown) {
            expect((new ReportRedactor)->redact($markdown))->toBe($markdown);
        }

        $writes = srsReportWrites(fn () => artisan('reports:redact', ['--strip-removed-sections' => true])
            ->expectsOutputToContain('Removed old sections from 0 of 3 reports.')
            ->doesntExpectOutputToContain('Report ')
            ->assertSuccessful());

        expect($writes)->toBe(0)
            ->and(array_map(fn (Report $report): ?string => $report->fresh()?->markdown, $reports))->toBe($markdowns)
            ->and(array_map(fn (Report $report): string => (string) $report->fresh()?->updated_at, $reports))->toBe($updated);
    });

    it('lists per report which sections would go with --dry-run and writes nothing', function () {
        $mac = Report::factory()->create(['markdown' => srsFixture('mac')]);
        $windows = Report::factory()->create(['markdown' => srsFixture('windows')]);
        $clean = Report::factory()->create(['markdown' => srsTodayMac()]);

        $writes = srsReportWrites(fn () => artisan('reports:redact', ['--dry-run' => true, '--strip-removed-sections' => true])
            ->expectsOutputToContain(srsLine($mac->id, 'would remove', SRS_MAC_REMOVED))
            ->expectsOutputToContain(srsLine($windows->id, 'would remove', SRS_WINDOWS_REMOVED))
            ->doesntExpectOutputToContain("Report {$clean->id}:")
            ->expectsOutputToContain('0 of 3 reports contain secrets or a user name.')
            ->expectsOutputToContain('2 of 3 reports hold sections the scans no longer collect.')
            ->doesntExpectOutputToContain('kept')
            ->assertSuccessful());

        expect($writes)->toBe(0)
            ->and($mac->fresh()?->markdown)->toBe(srsFixture('mac'))
            ->and($windows->fresh()?->markdown)->toBe(srsFixture('windows'));
    });

    it('redacts and strips in one pass, masking a user name only an old section showed', function () {
        $markdown = str_replace(
            ['/Users/<user>/Library/Application Support/com.apple.TCC', "TeamViewer, Spotify\n", "auto-boot: true\n"],
            ['/Users/janssen/Library/Application Support/com.apple.TCC', "TeamViewer, Spotify, janssen-sync\n", "auto-boot: true\nauthToken = abc123\n"],
            srsFixture('mac'),
        );
        $report = Report::factory()->create(['markdown' => $markdown]);

        artisan('reports:redact', ['--strip-removed-sections' => true])
            ->expectsOutputToContain('Redacted 1 of 1 reports.')
            ->expectsOutputToContain('Removed old sections from 1 of 1 reports.')
            ->assertSuccessful();

        $expected = str_replace(["TeamViewer, Spotify\n", "auto-boot: true\n"], ["TeamViewer, Spotify, <user>-sync\n", "auto-boot: true\n[removed]\n"], srsFixture('mac'));

        expect($report->fresh()?->markdown)->toBe(srsByHand($expected, SRS_MAC_REMOVED))->not->toContain('janssen')->not->toContain('abc123');
    });

    it('warns about a section it cannot cut cleanly, keeps it and cuts the rest', function () {
        $markdown = str_replace("\tBakker-Gasten\n", "\t```\n\tBakker-Gasten\n", srsFixture('mac'));
        $report = Report::factory()->create(['markdown' => $markdown]);
        $others = array_values(array_diff(SRS_MAC_REMOVED, ['Saved Wi-Fi networks and VPNs']));

        artisan('reports:redact', ['--strip-removed-sections' => true])
            ->expectsOutputToContain("Report {$report->id}: kept \"Saved Wi-Fi networks and VPNs\" because where it ends is unclear. Check it by hand.")
            ->expectsOutputToContain(srsLine($report->id, 'removed', $others))
            ->expectsOutputToContain('Removed old sections from 1 of 1 reports.')
            ->expectsOutputToContain('Old sections kept to check by hand: 1.')
            ->assertSuccessful();

        $after = (string) $report->fresh()?->markdown;
        $details = app(ReportDetails::class);

        expect($after)->toBe(srsByHand($markdown, $others))
            ->and($details->cards($after, [], 'laptop'))->toBe($details->cards($markdown, [], 'laptop'));
    });

    it('leaves old sections in place without the flag, as before', function () {
        $report = Report::factory()->create(['markdown' => srsFixture('mac')]);

        artisan('reports:redact')
            ->expectsOutputToContain('Redacted 0 of 1 reports.')
            ->doesntExpectOutputToContain('Report ')
            ->doesntExpectOutputToContain('old sections')
            ->assertSuccessful();

        artisan('reports:redact', ['--dry-run' => true])
            ->expectsOutputToContain('0 of 1 reports contain secrets or a user name.')
            ->doesntExpectOutputToContain('Report ')
            ->doesntExpectOutputToContain('no longer collect')
            ->assertSuccessful();

        expect($report->fresh()?->markdown)->toBe(srsFixture('mac'));
    });

    it('copes with no reports', function () {
        artisan('reports:redact', ['--strip-removed-sections' => true])
            ->expectsOutputToContain('Redacted 0 of 0 reports.')
            ->expectsOutputToContain('Removed old sections from 0 of 0 reports.')
            ->assertSuccessful();
    });
});
