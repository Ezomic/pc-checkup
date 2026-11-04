<?php

declare(strict_types=1);

use App\Actions\Machines\ImportReport;
use App\Enums\CheckupScript;
use App\Models\Client;
use App\Models\Machine;
use App\Models\Report;
use App\Models\User;
use App\Services\Reports\ReportParser;
use App\Services\Reports\ReportScriptVersion;
use Illuminate\Support\Facades\Process;

use function Pest\Laravel\actingAs;

function scriptSource(CheckupScript $script): string
{
    return str_replace("\r\n", "\n", (string) file_get_contents($script->path()));
}

function versionedReport(string $os, ?string $versionLine): string
{
    return "# Test checkup: Box\n\n- Format: 1\n- OS: {$os}\n- Serial: ABC123\n".($versionLine ?? '')."\n## Things to look at\n\n- Nothing flagged automatically.\n";
}

function scannedMachine(User $user, string $os, ?string $versionLine): Machine
{
    $machine = Machine::factory()->for($user)->create(['os' => $os]);
    $markdown = versionedReport($os, $versionLine);
    Report::factory()->for($machine)->create(['markdown' => $markdown, 'checksum' => hash('sha256', $markdown)]);

    return $machine;
}

describe('the version of a script', function () {
    it('is the first eight characters of the sha256 of the script text', function (CheckupScript $script) {
        expect($script->version())->toBe(substr(hash('sha256', scriptSource($script)), 0, 8))
            ->and($script->version())->toMatch('/\A[0-9a-f]{8}\z/');
    })->with([CheckupScript::Windows, CheckupScript::Mac]);

    it('differs between the two scripts', function () {
        expect(CheckupScript::Mac->version())->not->toBe(CheckupScript::Windows->version());
    });

    it('marks the one place a script prints its version, exactly once', function (CheckupScript $script) {
        expect(substr_count(scriptSource($script), CheckupScript::VERSION_TOKEN))->toBe(1);
    })->with([CheckupScript::Windows, CheckupScript::Mac]);

    it('is the same whether the file has Windows or Unix line endings', function () {
        $path = tempnam(sys_get_temp_dir(), 'pcc');
        file_put_contents($path, str_replace("\n", "\r\n", scriptSource(CheckupScript::Mac)));

        expect(CheckupScript::versionOf(file_get_contents($path)))->toBe(CheckupScript::Mac->version());

        unlink($path);
    });

    it('changes when one character of the script changes', function () {
        expect(CheckupScript::versionOf(scriptSource(CheckupScript::Mac).'#'))->not->toBe(CheckupScript::Mac->version());
    });

    it('is named in the file the user saves', function () {
        expect(CheckupScript::Mac->downloadName())->toBe('mac-checkup-'.CheckupScript::Mac->version().'.sh')
            ->and(CheckupScript::Windows->downloadName())->toBe('windows-checkup-'.CheckupScript::Windows->version().'.ps1');
    });

    it('picks the script that matches a report os', function (?string $os, ?CheckupScript $script) {
        expect(CheckupScript::forOs($os))->toBe($script);
    })->with([
        ['macOS', CheckupScript::Mac],
        ['Windows', CheckupScript::Windows],
        ['Microsoft Windows 11 Pro', CheckupScript::Windows],
        ['Darwin 24.1', CheckupScript::Mac],
        ['Macrium Reflect', null],
        ['Linux', null],
        ['', null],
        [null, null],
    ]);
});

describe('the download', function () {
    it('serves the script with its version in place of the marker and in the file name', function (CheckupScript $script) {
        $response = $this->get(route('scripts.download', $script))->assertOk();
        $body = (string) $response->getContent();

        expect($response->headers->get('content-disposition'))->toContain($script->downloadName())
            ->and($body)->not->toContain(CheckupScript::VERSION_TOKEN)
            ->and($body)->toContain($script->version())
            ->and($body)->toBe(str_replace(CheckupScript::VERSION_TOKEN, $script->version(), (string) file_get_contents($script->path())))
            ->and($response->headers->get('etag'))->toContain($script->version())
            ->and($response->headers->get('last-modified'))->not->toBeNull();
    })->with([CheckupScript::Windows, CheckupScript::Mac]);

    it('answers 304 to a browser that already holds this version and the full file once it changed', function () {
        $etag = (string) $this->get(route('scripts.download', CheckupScript::Mac))->headers->get('etag');

        $this->get(route('scripts.download', CheckupScript::Mac), ['If-None-Match' => $etag])->assertStatus(304);
        $this->get(route('scripts.download', CheckupScript::Mac), ['If-None-Match' => '"00000000"'])->assertOk();
    });

    it('shares the current versions with every page for the run instructions', function () {
        $this->get('/')->assertInertia(fn ($page) => $page
            ->where('scriptVersions.mac', CheckupScript::Mac->version())
            ->where('scriptVersions.windows', CheckupScript::Windows->version()));
    });
});

describe('reading the version from a report', function () {
    it('reads it from the header', function (string $line, ?string $expected) {
        $parsed = (new ReportParser)->parse(versionedReport('macOS', $line));

        expect($parsed->scriptVersion)->toBe($expected);
    })->with([
        'plain' => ['- Script version: 3f2a9c1d', '3f2a9c1d'],
        'padded' => ['- Script version:    3f2a9c1d   ', '3f2a9c1d'],
        'any case in the label' => ['- script VERSION: 3f2a9c1d', '3f2a9c1d'],
        'carriage return' => ["- Script version: 3f2a9c1d\r", '3f2a9c1d'],
        'tab padding' => ["- Script version:\t3f2a9c1d\t", '3f2a9c1d'],
        'a newer scheme with dots and dashes' => ['- Script version: 2027-01-02.3-rc1', '2027-01-02.3-rc1'],
        'empty' => ['- Script version:', null],
        'the dev marker an unreplaced repo copy prints' => ['- Script version: __PCC_SCRIPT_VERSION__', null],
        'spaces inside' => ['- Script version: 3f2a 9c1d', null],
        'a shell fragment' => ['- Script version: $(whoami)', null],
        'html' => ['- Script version: <b>x</b>', null],
        'unicode' => ['- Script version: v3f2a9c1d✓', null],
        'accented' => ['- Script version: é3f2a9c1', null],
        'control character' => ["- Script version: 3f2a\x07c1d", null],
        'null byte' => ["- Script version: 3f2a\x009c1d", null],
        'longer than any version we issue' => ['- Script version: '.str_repeat('a', 33), null],
        'very long' => ['- Script version: '.str_repeat('a', 100000), null],
        'exactly the longest accepted' => ['- Script version: '.str_repeat('a', 32), str_repeat('a', 32)],
    ]);

    it('has no version when the line is missing', function () {
        expect((new ReportParser)->parse(versionedReport('macOS', null))->scriptVersion)->toBeNull();
    });

    it('takes the last of two version lines, like every other header line', function () {
        $parsed = (new ReportParser)->parse(versionedReport('macOS', "- Script version: aaaaaaaa\n- Script version: bbbbbbbb"));

        expect($parsed->scriptVersion)->toBe('bbbbbbbb');
    });

    it('ignores a version line quoted further down in a section', function () {
        $markdown = versionedReport('macOS', null)."\n```\n- Script version: cccccccc\n```\n";

        expect((new ReportParser)->parse($markdown)->scriptVersion)->toBeNull();
    });
});

describe('comparing a report with the current script', function () {
    it('is current when the versions match', function () {
        $current = CheckupScript::Mac->version();

        expect((new ReportScriptVersion)->for(versionedReport('macOS', "- Script version: {$current}")))
            ->toBe(['version' => $current, 'current' => $current, 'outdated' => false]);
    });

    it('is outdated when the report came from another version, older or newer', function (string $theirs) {
        $current = CheckupScript::Windows->version();

        expect((new ReportScriptVersion)->for(versionedReport('Windows', "- Script version: {$theirs}")))
            ->toBe(['version' => $theirs, 'current' => $current, 'outdated' => true]);
    })->with(['00000000', '2099-01-01.9', 'ffffffff']);

    it('is outdated and unknown for a report without a usable version', function () {
        expect((new ReportScriptVersion)->for(versionedReport('Windows', null)))
            ->toBe(['version' => null, 'current' => CheckupScript::Windows->version(), 'outdated' => true])
            ->and((new ReportScriptVersion)->for(versionedReport('Windows', '- Script version: not a version')))
            ->toBe(['version' => null, 'current' => CheckupScript::Windows->version(), 'outdated' => true]);
    });

    it('says nothing for a report from an operating system we have no script for', function () {
        expect((new ReportScriptVersion)->for(versionedReport('Linux', '- Script version: 3f2a9c1d')))->toBeNull()
            ->and((new ReportScriptVersion)->for("# A report\n\nNo header at all.\n"))->toBeNull();
    });
});

describe('the owner machine page', function () {
    it('hands the vue page a camelCase script object for the latest report', function () {
        $user = User::factory()->create();
        $current = CheckupScript::Mac->version();
        $machine = scannedMachine($user, 'macOS', "- Script version: {$current}");

        actingAs($user)->get("/machines/{$machine->id}")->assertInertia(fn ($page) => $page
            ->where('report.script.version', $current)
            ->where('report.script.current', $current)
            ->where('report.script.outdated', false));
    });

    it('flags an old version and an unknown one', function (?string $line, ?string $version) {
        $user = User::factory()->create();
        $machine = scannedMachine($user, 'Windows', $line);

        actingAs($user)->get("/machines/{$machine->id}")->assertInertia(fn ($page) => $page
            ->where('report.script.version', $version)
            ->where('report.script.current', CheckupScript::Windows->version())
            ->where('report.script.outdated', true));
    })->with([
        'old' => ['- Script version: 00000000', '00000000'],
        'newer than the server' => ['- Script version: 2099-01-01.9', '2099-01-01.9'],
        'unknown' => [null, null],
        'garbled' => ["- Script version: \x07\x07", null],
    ]);

    it('has no script object for an operating system without a script', function () {
        $user = User::factory()->create();
        $machine = scannedMachine($user, 'Linux', null);

        actingAs($user)->get("/machines/{$machine->id}")->assertInertia(fn ($page) => $page->where('report.script', null));
    });

    it('never puts the script version on a client share page', function () {
        $client = Client::factory()->withShareLink()->create();
        $machine = scannedMachine($client->user, 'Windows', '- Script version: 00000000');
        $machine->forceFill(['client_id' => $client->id])->save();

        $this->get("/c/{$client->share_token}/machines/{$machine->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->missing('report.script')->missing('script'));

        $props = json_encode($this->get("/c/{$client->share_token}/machines/{$machine->id}")->viewData('page')['props']);

        expect($props)->not->toContain('outdated')->and($props)->not->toContain('00000000');
    });
});

describe('the identity of a report', function () {
    it('still treats the same text as the same report once it carries a version line', function () {
        $user = User::factory()->create();
        $markdown = versionedReport('macOS', '- Script version: 3f2a9c1d');

        $first = app(ImportReport::class)->handle($user, $markdown);
        $second = app(ImportReport::class)->handle($user, str_replace("\n", "\r\n", $markdown));

        expect($second->id)->toBe($first->id)
            ->and(Report::query()->count())->toBe(1);
    });

    it('imports a report from before the version existed unchanged', function () {
        $user = User::factory()->create();

        $report = app(ImportReport::class)->handle($user, versionedReport('macOS', null));

        expect($report->format_version)->toBe(1)
            ->and($report->checksum)->toBe(hash('sha256', versionedReport('macOS', null)));
    });
});

describe('the scripts print their version', function () {
    it('puts the Mac version in the banner and in the report header', function () {
        $run = fn (string $code): string => Process::path(base_path())->run(['bash', '-c', 'source scripts/mac-checkup.sh; PCC_SCRIPT_VERSION=3f2a9c1d; '.$code])->output();

        expect($run('pcc_banner'))->toContain('3f2a9c1d')
            ->and($run('pcc_version_line'))->toBe("- Script version: 3f2a9c1d\n");
    });

    it('names itself in its hints by the file it was saved as, and falls back when run from a pipe', function () {
        $dir = sys_get_temp_dir().'/pcc-name-'.bin2hex(random_bytes(4));
        mkdir($dir);
        copy(base_path('scripts/mac-checkup.sh'), $dir.'/mac-checkup-3f2a9c1d.sh');

        $named = Process::run(['bash', '-c', 'source "$1"; echo "$PCC_SCRIPT_NAME"', 'bash', $dir.'/mac-checkup-3f2a9c1d.sh'])->output();
        $piped = Process::path(base_path())->run(['bash', '-c', 'source <(cat scripts/mac-checkup.sh); echo "$PCC_SCRIPT_NAME"'])->output();

        expect($named)->toBe("mac-checkup-3f2a9c1d.sh\n")
            ->and($piped)->toBe("mac-checkup.sh\n");

        unlink($dir.'/mac-checkup-3f2a9c1d.sh');
        rmdir($dir);
    });

    it('puts the Windows version in the banner and in the report header', function () {
        $pwsh = trim((string) shell_exec('command -v pwsh 2>/dev/null'));
        $windows = trim((string) shell_exec('command -v powershell.exe 2>/dev/null'));

        if ($pwsh === '' && $windows === '') {
            $this->markTestSkipped('No PowerShell on this machine.');
        }

        $script = scriptSource(CheckupScript::Windows);
        preg_match('/^function Get-ScriptBanner .*$/m', $script, $banner);
        preg_match('/^function Get-ScriptVersionLine .*$/m', $script, $line);

        expect($banner)->not->toBeEmpty()->and($line)->not->toBeEmpty();

        $dir = sys_get_temp_dir().'/pcc-ver-'.bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        file_put_contents($dir.'/step.ps1', "\$ScriptVersion = '3f2a9c1d'\n".$banner[0]."\n".$line[0]."\nGet-ScriptBanner\nGet-ScriptVersionLine\n");

        $path = $pwsh !== '' ? $dir.'/step.ps1' : trim((string) shell_exec('wslpath -w '.escapeshellarg($dir.'/step.ps1')));
        $command = $pwsh !== '' ? [$pwsh, '-NoProfile', '-File'] : [$windows, '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File'];
        $output = Process::timeout(120)->run([...$command, $path])->output();

        expect($output)->toContain('3f2a9c1d')->and($output)->toContain('- Script version: 3f2a9c1d');
    });
});
