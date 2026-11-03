<?php

declare(strict_types=1);

use App\Actions\Machines\ImportReport;
use App\Models\Client;
use App\Models\Machine;
use App\Models\Report;
use App\Models\ScanCode;
use App\Models\User;
use App\Services\Reports\ReportParser;
use Illuminate\Support\Facades\Process;

use function Pest\Laravel\actingAs;

function rightsReport(string $value, string $serial = 'C02RIGHTS0001'): string
{
    return reportMarkdown(['Serial' => $serial, 'Run as administrator' => $value]);
}

function macAdminLine(bool $root): string
{
    $override = $root ? 'id() { echo 0; }; ' : 'id() { echo 501; }; ';

    $result = Process::path(base_path())->run(['bash', '-c', $override.'source scripts/mac-checkup.sh; pcc_admin_check']);

    expect($result->errorOutput())->toBe('');

    return trim($result->output());
}

describe('ReportParser', function () {
    it('reads yes and no, whatever the case, with or without an explanation', function (string $value, bool $expected) {
        expect((new ReportParser)->parse(rightsReport($value))->ranAsAdmin)->toBe($expected);
    })->with([
        'yes' => ['yes', true],
        'no' => ['no', false],
        'upper case yes' => ['YES', true],
        'mixed case no' => ['No', false],
        'padded' => ['   yes   ', true],
        'yes with a note' => ['yes, some sections need it', true],
        'no with the Windows note' => ['no, some sections say unavailable', false],
        'a very long note' => ['no, '.str_repeat('x', 100000), false],
    ]);

    it('knows nothing for a report from an older script', function () {
        expect((new ReportParser)->parse(reportMarkdown())->ranAsAdmin)->toBeNull();
    });

    it('knows nothing for a value it does not understand', function (string $value) {
        expect((new ReportParser)->parse(rightsReport($value))->ranAsAdmin)->toBeNull();
    })->with([
        'maybe' => 'maybe',
        'true' => 'true',
        'one' => '1',
        'zero' => '0',
        'y' => 'y',
        'nope' => 'nope',
        'yes glued to a word' => 'yesterday',
        'yes then a word' => 'yes please',
        'fullwidth' => 'ｙｅｓ',
        'markup' => '<script>yes</script>',
        'yes after a prefix' => 'not yes',
        'empty' => '',
        'blank' => '   ',
        'very long without a separator' => str_repeat('yes', 50000),
        'comma first' => ', yes',
    ]);

    it('uses the last of several rights lines', function () {
        $report = str_replace("\n\n## Things", "\n- Run as administrator: no\n\n## Things", rightsReport('yes'));

        expect((new ReportParser)->parse($report)->ranAsAdmin)->toBeFalse();
    });

    it('knows nothing when the last of several rights lines is invalid', function () {
        $report = str_replace("\n\n## Things", "\n- Run as administrator: maybe\n\n## Things", rightsReport('yes'));

        expect((new ReportParser)->parse($report)->ranAsAdmin)->toBeNull();
    });

    it('ignores a rights line quoted below the first section', function () {
        $report = reportMarkdown()."\n- Run as administrator: yes\n";

        expect((new ReportParser)->parse($report)->ranAsAdmin)->toBeNull();
    });

    it('ignores a rights line in the body of the scan', function () {
        $report = reportMarkdown()."\n## Account\n\nRun as administrator: True\n- Run as administrator: yes\n";

        expect((new ReportParser)->parse($report)->ranAsAdmin)->toBeNull();
    });
});

describe('importing a report', function () {
    it('stores the rights on the report', function (string $value, ?bool $expected) {
        $user = User::factory()->create();

        $report = (new ImportReport)->handle($user, rightsReport($value));

        expect($report->fresh()?->ran_as_admin)->toBe($expected);
    })->with([
        'yes' => ['yes', true],
        'no' => ['no, some sections say unavailable', false],
        'upper case' => ['NO', false],
        'maybe' => ['maybe', null],
        'long junk' => [str_repeat('z', 100000), null],
    ]);

    it('stores nothing for a report without the line', function () {
        $report = (new ImportReport)->handle(User::factory()->create(), reportMarkdown());

        expect($report->fresh()?->ran_as_admin)->toBeNull();
    });

    it('survives Windows line endings and a byte order mark', function () {
        $text = "\u{FEFF}".str_replace("\n", "\r\n", rightsReport('yes'));

        $report = (new ImportReport)->handle(User::factory()->create(), $text);

        expect($report->fresh()?->ran_as_admin)->toBeTrue();
    });

    it('keeps each scan with its own rights', function () {
        $user = User::factory()->create();

        (new ImportReport)->handle($user, rightsReport('yes'));
        (new ImportReport)->handle($user, rightsReport('no').'<!-- later -->');

        expect(Report::query()->orderBy('id')->pluck('ran_as_admin')->all())->toBe([true, false]);
    });

    it('is picked up through the import page', function () {
        $user = User::factory()->create();

        actingAs($user)->post('/imports', ['text' => rightsReport('no')])->assertRedirect();

        expect(Report::query()->sole()->ran_as_admin)->toBeFalse();
    });

    it('is picked up through a scan code', function () {
        $user = User::factory()->create();
        ScanCode::factory()->for($user)->withCode('123456')->create();

        $this->postJson('/api/scans', ['code' => '123456', 'markdown' => rightsReport('yes')])->assertCreated();

        expect(Report::query()->sole()->ran_as_admin)->toBeTrue();
    });
});

describe('the owner and share pages end to end', function () {
    it('only counts fixed items when two imported scans had the same rights', function (string $older, string $newer, ?int $shared, bool $same) {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->withShareLink()->create();

        (new ImportReport)->handle($user, reportMarkdown(['Serial' => 'C02RIGHTS0002', 'Run as administrator' => $older], ['FileVault is OFF.']));
        $machine = Machine::query()->sole();
        $machine->forceFill(['client_id' => $client->id])->save();
        Report::query()->update(['created_at' => '2026-10-12 10:00:00']);
        (new ImportReport)->handle($user, reportMarkdown(['Serial' => 'C02RIGHTS0002', 'Run as administrator' => $newer], ['A restart is pending.']));

        actingAs($user)->get("/machines/{$machine->id}")
            ->assertInertia(fn ($page) => $page->where('changes.same_rights', $same)->where('changes.fixed', ['Disk encryption is off']));

        $this->get("/c/{$client->share_token}/machines/{$machine->id}")
            ->assertInertia(fn ($page) => $page->where('report.fixed_count', $shared));
    })->with([
        'both admin' => ['yes', 'yes', 1, true],
        'both standard' => ['no', 'no', 1, true],
        'dropped rights' => ['yes', 'no', null, false],
        'junk then admin' => ['maybe', 'yes', null, false],
    ]);
});

describe('the Mac scan', function () {
    it('says yes when it runs as root', function () {
        expect(macAdminLine(true))->toBe('Run as administrator: yes');
    });

    it('says no, and what that means, when it does not', function () {
        expect(macAdminLine(false))->toStartWith('Run as administrator: no, ');
    });

    it('writes a line the importer reads', function (bool $root, bool $expected) {
        $report = str_replace('- Run as administrator: yes', '- '.macAdminLine($root), rightsReport('yes'));

        expect((new ReportParser)->parse($report)->ranAsAdmin)->toBe($expected);
    })->with([[true, true], [false, false]]);

    it('prints the line in the report header', function () {
        expect((string) file_get_contents(base_path('scripts/mac-checkup.sh')))->toContain('echo "- $(pcc_admin_check)"');
    });
});

describe('the Windows scan', function () {
    it('already writes a line the importer reads', function () {
        $script = (string) file_get_contents(base_path('scripts/windows-checkup.ps1'));

        expect($script)->toContain('$lines.Add("- Run as administrator: $(if ($IsAdmin) { \'yes\' } else { \'no, some sections say unavailable\' })")');
    });
});

describe('what the scans do with rights', function () {
    it('only read who is running them and never ask for more', function (string $file) {
        $script = (string) file_get_contents(base_path($file));

        expect($script)->not->toMatch('/Start-Process[^\n]*-Verb\s+RunAs|runas\b|sudo\s+-v|osascript[^\n]*administrator privileges/i');
    })->with(['scripts/windows-checkup.ps1', 'scripts/mac-checkup.sh']);
});
