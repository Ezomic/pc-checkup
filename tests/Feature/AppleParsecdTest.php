<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Machine;
use App\Models\Report;
use App\Models\User;
use App\Services\NeedsAttention;
use App\Services\Reports\ReportDetails;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\ScriptTemp;

use function Pest\Laravel\actingAs;

function apdNoPerl(): bool
{
    return PHP_OS_FAMILY === 'Windows' || trim((string) shell_exec('command -v perl 2>/dev/null')) === '';
}

/**
 * Runs the Mac remote-access step against a fake ps that answers only when asked for full paths.
 *
 * @param  list<string>  $running
 * @param  list<string>  $apps
 */
function apdScan(array $running, array $apps = ['Safari.app']): ProcessResult
{
    $out = ScriptTemp::dir('pcc-apd-out').'/ps.txt';
    file_put_contents($out, implode("\n", $running)."\n");
    $bin = ScriptTemp::bin('pcc-apd-bin', ['ps' => "[ \"\$*\" = '-axo comm=' ] || exit 2\ncat ".escapeshellarg($out)]);
    $dir = ScriptTemp::dir('pcc-apd-apps');

    foreach ($apps as $app) {
        File::ensureDirectoryExists("{$dir}/{$app}");
    }

    return Process::path(base_path())->timeout(60)->env(['PATH' => $bin.':'.getenv('PATH'), 'PCC_REMOTE_DIRS' => $dir])
        ->run(['bash', '-c', 'source scripts/mac-checkup.sh; pcc_remote_report']);
}

/**
 * @return list<string>
 */
function apdToolLines(ProcessResult $result): array
{
    $lines = explode("\n", trim($result->throw()->output()));

    expect(array_slice($lines, 0, 2))->toBe(['format: 1', 'scan: complete']);

    return array_slice($lines, 2);
}

function apdSection(string $body): string
{
    return "\n## Remote access tools\n\n```\nformat: 1\n{$body}\n```\n";
}

function apdMac(string $body): string
{
    return macReportFixture().apdSection($body);
}

function apdWindows(string $body): string
{
    return windowsLaptopFixture().apdSection($body);
}

/**
 * @return array<string, mixed>|null
 */
function apdCard(string $markdown): ?array
{
    return array_column(app(ReportDetails::class)->cards($markdown, [], 'laptop'), null, 'id')['remote-access'] ?? null;
}

/**
 * @return list<string>
 */
function apdReasons(string $markdown): array
{
    $machine = Machine::factory()->for(User::factory()->create())->create(['device_type' => 'laptop']);
    Report::factory()->for($machine)->create(['markdown' => $markdown]);
    $method = new ReflectionMethod(NeedsAttention::class, 'analyse');

    return array_column($method->invoke(app(NeedsAttention::class), $machine, $machine->newestReport)['reasons'], 'text');
}

describe('the Mac scan of running remote-access tools', function () {
    it("ignores Apple's own parsecd and other programs in system folders", function () {
        $result = apdScan([
            '/sbin/launchd',
            '/usr/libexec/logd',
            '/System/Library/PrivateFrameworks/CoreParsec.framework/parsecd',
            '/System/Library/PrivateFrameworks/CoreParsec.framework/Versions/A/parsecd',
            '/usr/libexec/teamviewerd',
            '/bin/anydesk',
            '/sbin/rustdesk',
            '/Library/Apple/System/Library/CoreServices/parsecd',
            '/System/Library/CoreServices/Finder.app/Contents/MacOS/Finder',
            '  /System/Library/PrivateFrameworks/CoreParsec.framework/parsecd  ',
            '(/System/Library/PrivateFrameworks/CoreParsec.framework/parsecd)',
            "/System/Library/PrivateFrameworks/Caf\xE9.framework/parsecd",
        ]);

        expect(apdToolLines($result))->toBe([]);
    })->skip(apdNoPerl(), 'perl is not installed here');

    it('still finds the real Parsec, installed or as a downloaded copy', function () {
        $installed = apdScan(['/System/Library/PrivateFrameworks/CoreParsec.framework/parsecd', '/Applications/Parsec.app/Contents/MacOS/parsecd'], ['Parsec.app']);
        $downloaded = apdScan(['/System/Library/PrivateFrameworks/CoreParsec.framework/parsecd', '/Users/test/Downloads/Parsec.app/Contents/MacOS/parsecd']);
        $notRunning = apdScan(['/System/Library/PrivateFrameworks/CoreParsec.framework/parsecd'], ['Parsec.app']);

        expect(apdToolLines($installed))->toBe(['tool|Parsec|installed,running'])
            ->and(apdToolLines($downloaded))->toBe(['tool|Parsec|running'])
            ->and(apdToolLines($notRunning))->toBe(['tool|Parsec|installed']);
    })->skip(apdNoPerl(), 'perl is not installed here');

    it('falls back to the name when ps shows no path', function (string $line) {
        expect(apdToolLines(apdScan(['-bash', $line])))->toBe(['tool|Parsec|running']);
    })->with([
        'bare name' => ['parsecd'],
        'name in brackets' => ['(parsecd)'],
    ])->skip(apdNoPerl(), 'perl is not installed here');

    it('keeps programs outside the system folders, also in /usr/local and /Library', function () {
        $result = apdScan([
            '/usr/local/bin/rustdesk',
            '/Library/PrivilegedHelperTools/ChromeRemoteDesktopHost.app/Contents/MacOS/remoting_me2me_host',
            '/Applications/AnyDesk.app/Contents/MacOS/AnyDesk',
        ]);

        expect(apdToolLines($result))->toBe(['tool|AnyDesk|running', 'tool|RustDesk|running', 'tool|Chrome Remote Desktop|running']);
    })->skip(apdNoPerl(), 'perl is not installed here');

    it('matches the program name, not the folder it is in, and never prints a path', function () {
        $result = apdScan(['/Applications/Parsec Tools/helper', '/Users/test/parsecd/run', '/', '', '   ', "/Applications/Te\tamViewer/x"]);

        expect(apdToolLines($result))->toBe([])
            ->and($result->output())->not->toContain('/')->not->toContain('helper')->not->toContain('test');
    })->skip(apdNoPerl(), 'perl is not installed here');

    it('finds a tool among thousands of system processes', function () {
        $running = array_fill(0, 5000, '/System/Library/PrivateFrameworks/CoreParsec.framework/parsecd');
        $running[] = '/Applications/TeamViewer.app/Contents/MacOS/TeamViewer';

        expect(apdToolLines(apdScan($running)))->toBe(['tool|TeamViewer|running']);
    })->skip(apdNoPerl(), 'perl is not installed here');
});

describe('Parsec on the remote-access card', function () {
    it('drops a Mac Parsec that was only seen running, so a complete scan reads as none found', function (string $name) {
        $card = apdCard(apdMac("scan: complete\ntool|{$name}|running"));

        expect($card['summary'])->toBe('None of the known remote-access tools were found.')
            ->and($card['rows'])->toBe([])
            ->and(implode(' ', $card['notes']))->not->toContain('These let someone else');
    })->with(['Parsec', 'PARSEC', 'parsec']);

    it('stays hidden for a partial Mac scan that only saw Parsec running', function () {
        expect(apdCard(apdMac("scan: partial (time limit of 15 seconds reached)\ntool|Parsec|running")))->toBeNull();
    });

    it('keeps the other tools of a Mac when it drops Parsec', function () {
        $card = apdCard(apdMac("scan: complete\ntool|Parsec|running\ntool|AnyDesk|installed"));

        expect($card['summary'])->toBe('1 remote-access tool found. Check that you recognise it.')
            ->and(array_column($card['rows'], 'label'))->toBe(['AnyDesk']);
    });

    it('shows Parsec on a Mac where it is installed', function (string $body, string $how) {
        expect(apdCard(apdMac("scan: complete\n{$body}"))['rows'])->toBe([['label' => 'Parsec', 'value' => $how, 'tone' => 'neutral']]);
    })->with([
        'installed and running' => ['tool|Parsec|installed,running', 'Installed and running'],
        'installed' => ['tool|Parsec|installed', 'Installed'],
        'on two lines' => ["tool|Parsec|running\ntool|parsec|installed", 'Installed and running'],
    ]);

    it('keeps a running-only Parsec on Windows and on a report that does not say it is a Mac', function (string $markdown) {
        expect(apdCard($markdown)['rows'])->toBe([['label' => 'Parsec', 'value' => 'Running, not installed (a downloaded copy)', 'tone' => 'neutral']]);
    })->with([
        'Windows' => [apdWindows("scan: complete\ntool|Parsec|running")],
        'no OS line' => [str_replace("- OS: macOS\n", '', apdMac("scan: complete\ntool|Parsec|running"))],
    ]);

    it('leaves Parsec out of the needs-attention list only where the card leaves it out', function () {
        expect(implode("\n", apdReasons(apdMac("scan: complete\ntool|Parsec|running"))))->not->toContain('Remote-access')
            ->and(apdReasons(apdMac("scan: complete\ntool|Parsec|installed,running")))->toContain('Remote-access tools found (Parsec): check they are recognised')
            ->and(apdReasons(apdWindows("scan: complete\ntool|Parsec|running")))->toContain('Remote-access tools found (Parsec): check they are recognised');
    });

    it('shows a Mac Parsec to the owner and never on a share page', function () {
        $client = Client::factory()->withShareLink()->create();
        $machine = Machine::factory()->for($client->user)->create(['client_id' => $client->id, 'device_type' => 'laptop']);
        Report::factory()->for($machine)->create(['markdown' => apdMac("scan: complete\ntool|Parsec|installed,running")]);
        $owner = [];

        foreach (["/c/{$client->share_token}", "/c/{$client->share_token}/machines/{$machine->id}"] as $path) {
            $response = $this->get($path)->assertOk();

            expect((string) $response->getContent())->not->toContain('Parsec')->not->toContain('remote-access')->not->toContain('Remote-access');
        }

        actingAs($client->user)->get("/machines/{$machine->id}")->assertOk()->assertInertia(function ($page) use (&$owner): void {
            $owner = array_column($page->toArray()['props']['detailCards'], null, 'id')['remote-access']['rows'];
        });

        expect(array_column($owner, 'label'))->toBe(['Parsec']);
    });
});
