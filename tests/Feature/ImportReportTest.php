<?php

declare(strict_types=1);

use App\Actions\Machines\ImportReport;
use App\Models\Machine;
use App\Models\Report;
use App\Models\User;
use App\Services\Reports\ReportParser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseCount;

/**
 * @param  array<string, string>  $header
 * @param  list<string>  $flags
 */
function reportMarkdown(array $header = [], array $flags = ['Battery has 900 cycles (high).'], string $title = 'Mac checkup: Robbins-MacBook'): string
{
    $header = [
        'Format' => '1',
        'OS' => 'macOS',
        'Device type' => 'laptop',
        'Serial' => 'C02ABC123XYZ',
        'Hardware UUID' => '11111111-2222-3333-4444-555555555555',
        'Model' => 'MacBook Pro (MacBookPro18,3)',
        ...$header,
    ];

    $lines = ["# {$title}", ''];

    foreach ($header as $key => $value) {
        $lines[] = "- {$key}: {$value}";
    }

    $lines[] = '';
    $lines[] = '## Things to look at';
    $lines[] = '';

    foreach ($flags === [] ? ['Nothing flagged automatically.'] : $flags as $flag) {
        $lines[] = "- {$flag}";
    }

    $lines[] = '';
    $lines[] = '## Hardware overview';
    $lines[] = '';
    $lines[] = '```';
    $lines[] = '- Serial: SHOULD-NOT-BE-PARSED';
    $lines[] = '```';

    return implode("\n", $lines)."\n";
}

describe('ReportParser', function () {
    it('reads the header block and the flags', function () {
        $parsed = (new ReportParser)->parse(reportMarkdown());

        expect($parsed->name)->toBe('Robbins-MacBook')
            ->and($parsed->formatVersion)->toBe(1)
            ->and($parsed->os)->toBe('macOS')
            ->and($parsed->deviceType)->toBe('laptop')
            ->and($parsed->serial)->toBe('C02ABC123XYZ')
            ->and($parsed->hardwareUuid)->toBe('11111111-2222-3333-4444-555555555555')
            ->and($parsed->model)->toBe('MacBook Pro (MacBookPro18,3)')
            ->and($parsed->flags)->toBe(['Battery has 900 cycles (high).']);
    });

    it('ignores the nothing flagged line', function () {
        expect((new ReportParser)->parse(reportMarkdown(flags: []))->flags)->toBe([]);
    });

    it('only reads the header before the first section', function () {
        expect((new ReportParser)->parse(reportMarkdown())->serial)->toBe('C02ABC123XYZ');
    });

    it('copes with an old report that has no format, os or device type', function () {
        $markdown = "# Mac checkup: Old\n\n- Serial: ABC\n- Model: MacBook Air\n\n## Hardware overview\n";

        $parsed = (new ReportParser)->parse($markdown);

        expect($parsed->formatVersion)->toBeNull()
            ->and($parsed->os)->toBeNull()
            ->and($parsed->deviceType)->toBeNull()
            ->and($parsed->serial)->toBe('ABC')
            ->and($parsed->flags)->toBe([]);
    });

    it('treats a non numeric format as missing', function () {
        expect((new ReportParser)->parse(reportMarkdown(['Format' => 'two']))->formatVersion)->toBeNull();
    });

    it('treats a missing title as no name', function () {
        expect((new ReportParser)->parse("- Serial: ABC\n")->name)->toBeNull();
    });

    it('has no name when the title carries no host after the colon', function () {
        expect((new ReportParser)->parse("# Mac checkup\n- Serial: ABC\n")->name)->toBeNull();
    });

    it('has no name when nothing follows the colon', function () {
        expect((new ReportParser)->parse("# Mac checkup: \n- Serial: ABC\n")->name)->toBeNull();
    });

    it('does not read a name from a heading below the first section', function () {
        expect((new ReportParser)->parse("## Section\n# Mac checkup: Late\n")->name)->toBeNull();
    });

    it('caps very long descriptive values', function () {
        $parsed = (new ReportParser)->parse(reportMarkdown(['Model' => str_repeat('é', 400)]));

        expect(mb_strlen((string) $parsed->model))->toBe(255);
    });

    it('does not treat a header line without a value as a value', function () {
        expect((new ReportParser)->parse(reportMarkdown(['Model' => '   ']))->model)->toBeNull();
    });
});

describe('ParsedReport identity', function () {
    it('prefers the serial, compared case insensitively', function () {
        $a = (new ReportParser)->parse(reportMarkdown(['Serial' => ' c02abc123xyz ']));

        expect($a->identityKey())->toBe('serial:C02ABC123XYZ')
            ->and($a->serial)->toBe('c02abc123xyz');
    });

    it('falls back to the hardware uuid when the serial is a placeholder', function (string $placeholder) {
        $parsed = (new ReportParser)->parse(reportMarkdown(['Serial' => $placeholder]));

        expect($parsed->identityKey())->toBe('uuid:11111111-2222-3333-4444-555555555555');
    })->with([
        'oem' => 'To be filled by O.E.M.',
        'default string' => 'Default string',
        'system serial' => 'System Serial Number',
        'not specified' => 'Not Specified',
        'none' => 'None',
        'zero' => '0',
        'digits' => '123456789',
        'unknown' => 'unknown',
        'mixed case' => 'tO bE fIlLeD bY o.e.m.',
        'empty' => '',
    ]);

    it('falls back when the serial is implausibly long', function () {
        $parsed = (new ReportParser)->parse(reportMarkdown(['Serial' => str_repeat('A', 101)]));

        expect($parsed->identityKey())->toStartWith('uuid:');
    });

    it('has no identity when both serial and uuid are unusable', function () {
        $parsed = (new ReportParser)->parse(reportMarkdown(['Serial' => 'None', 'Hardware UUID' => '']));

        expect($parsed->identityKey())->toBeNull();
    });
});

describe('ImportReport', function () {
    it('creates a machine and a report', function () {
        $user = User::factory()->create();

        $report = (new ImportReport)->handle($user, reportMarkdown());

        $machine = Machine::query()->sole();
        expect($machine->user_id)->toBe($user->id)
            ->and($machine->identity_key)->toBe('serial:C02ABC123XYZ')
            ->and($machine->serial)->toBe('C02ABC123XYZ')
            ->and($machine->hardware_uuid)->toBe('11111111-2222-3333-4444-555555555555')
            ->and($machine->name)->toBe('Robbins-MacBook')
            ->and($machine->os)->toBe('macOS')
            ->and($machine->device_type)->toBe('laptop')
            ->and($machine->model)->toBe('MacBook Pro (MacBookPro18,3)')
            ->and($report->machine_id)->toBe($machine->id)
            ->and($report->format_version)->toBe(1)
            ->and($report->flags)->toBe(['Battery has 900 cycles (high).'])
            ->and($report->markdown)->toBe(reportMarkdown());
    });

    it('attaches a second report with the same serial to the same machine', function () {
        $user = User::factory()->create();

        (new ImportReport)->handle($user, reportMarkdown());
        (new ImportReport)->handle($user, reportMarkdown(flags: ['FileVault is OFF.']));

        assertDatabaseCount('machines', 1);
        assertDatabaseCount('reports', 2);
    });

    it('matches the same serial in a different case or with padding', function () {
        $user = User::factory()->create();

        (new ImportReport)->handle($user, reportMarkdown());
        (new ImportReport)->handle($user, reportMarkdown(['Serial' => '  c02abc123xyz  ']));

        assertDatabaseCount('machines', 1);
    });

    it('does not import the same report twice', function () {
        $user = User::factory()->create();

        $first = (new ImportReport)->handle($user, reportMarkdown());
        $second = (new ImportReport)->handle($user, reportMarkdown());

        expect($second->is($first))->toBeTrue();
        assertDatabaseCount('reports', 1);
    });

    it('treats windows line endings and a byte order mark as the same report', function () {
        $user = User::factory()->create();

        $first = (new ImportReport)->handle($user, reportMarkdown());
        $second = (new ImportReport)->handle($user, "\u{FEFF}".str_replace("\n", "\r\n", reportMarkdown()));

        expect($second->is($first))->toBeTrue()
            ->and($first->markdown)->not->toContain("\r")
            ->and($first->markdown)->not->toContain("\u{FEFF}");
    });

    it('keeps two machines with different serials apart', function () {
        $user = User::factory()->create();

        (new ImportReport)->handle($user, reportMarkdown());
        (new ImportReport)->handle($user, reportMarkdown(['Serial' => 'OTHER', 'Hardware UUID' => 'zzz']));

        assertDatabaseCount('machines', 2);
    });

    it('never merges two pcs that both report a placeholder serial but differ in uuid', function () {
        $user = User::factory()->create();

        (new ImportReport)->handle($user, reportMarkdown(['Serial' => 'To be filled by O.E.M.', 'Hardware UUID' => '4C4C4544-0035-5110-8052-C4C04F544E32']));
        (new ImportReport)->handle($user, reportMarkdown(['Serial' => 'To be filled by O.E.M.', 'Hardware UUID' => '4C4C4544-0042-5710-8030-B8C04F4C4E32']));

        assertDatabaseCount('machines', 2);
    });

    it('matches a uuid fallback case insensitively', function () {
        $user = User::factory()->create();

        (new ImportReport)->handle($user, reportMarkdown(['Serial' => 'None', 'Hardware UUID' => 'AAAA-bbbb']));
        (new ImportReport)->handle($user, reportMarkdown(['Serial' => 'None', 'Hardware UUID' => 'aaaa-BBBB'], flags: ['x']));

        assertDatabaseCount('machines', 1);
    });

    it('never attaches a report to another user’s machine', function () {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        (new ImportReport)->handle($owner, reportMarkdown());
        (new ImportReport)->handle($other, reportMarkdown(flags: ['different']));

        assertDatabaseCount('machines', 2);
        expect($owner->machines()->count())->toBe(1)
            ->and($other->machines()->count())->toBe(1);
    });

    it('brings a deleted machine back when its serial is imported again', function () {
        $user = User::factory()->create();
        (new ImportReport)->handle($user, reportMarkdown());
        Machine::query()->sole()->delete();

        (new ImportReport)->handle($user, reportMarkdown(flags: ['again']));

        assertDatabaseCount('machines', 1);
        expect(Machine::query()->sole()->trashed())->toBeFalse();
    });

    it('re-imports a report that was deleted instead of failing on the unique checksum', function () {
        $user = User::factory()->create();
        $first = (new ImportReport)->handle($user, reportMarkdown());
        $first->delete();

        $again = (new ImportReport)->handle($user, reportMarkdown());

        expect($again->trashed())->toBeFalse()
            ->and(Report::query()->count())->toBe(1);
    });

    it('updates the machine with what the newest report says', function () {
        $user = User::factory()->create();
        (new ImportReport)->handle($user, reportMarkdown(['Model' => 'Old model']));

        (new ImportReport)->handle($user, reportMarkdown(['Model' => 'New model'], flags: ['x']));

        expect(Machine::query()->sole()->model)->toBe('New model');
    });

    it('keeps what the machine already knows when a newer report lacks it', function () {
        $user = User::factory()->create();
        (new ImportReport)->handle($user, reportMarkdown());

        (new ImportReport)->handle($user, "# Mac checkup\n\n- Serial: C02ABC123XYZ\n\n## Things to look at\n\n- x\n");

        $machine = Machine::query()->sole();
        expect($machine->os)->toBe('macOS')
            ->and($machine->model)->toBe('MacBook Pro (MacBookPro18,3)')
            ->and($machine->name)->toBe('Robbins-MacBook');
    });

    it('rejects a report with no usable serial or hardware uuid', function () {
        $user = User::factory()->create();

        expect(fn () => (new ImportReport)->handle($user, reportMarkdown(['Serial' => 'None', 'Hardware UUID' => ''])))
            ->toThrow(ValidationException::class);

        assertDatabaseCount('machines', 0);
        assertDatabaseCount('reports', 0);
    });

    it('rejects text that is not valid utf-8', function () {
        $user = User::factory()->create();

        expect(fn () => (new ImportReport)->handle($user, "# x\n- Serial: \xFF\xFE\n"))
            ->toThrow(ValidationException::class);
    });

    it('rejects text that is only markup noise', function () {
        $user = User::factory()->create();

        expect(fn () => (new ImportReport)->handle($user, '#### ----- ****'))
            ->toThrow(ValidationException::class);
    });

    it('stores markup and html in a header value as plain text', function () {
        $user = User::factory()->create();

        (new ImportReport)->handle($user, reportMarkdown(['Model' => '<script>alert(1)</script>']));

        expect(Machine::query()->sole()->model)->toBe('<script>alert(1)</script>');
    });
});

describe('POST /imports', function () {
    it('requires authentication', function () {
        $this->post('/imports', ['text' => reportMarkdown()])->assertRedirect('/login');
    });

    it('renders the upload page', function () {
        actingAs(User::factory()->create())->get('/imports/create')
            ->assertInertia(fn ($page) => $page->component('imports/Create'));
    });

    it('imports an uploaded file', function () {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->createWithContent('report.md', reportMarkdown());

        actingAs($user)->post('/imports', ['file' => $file])->assertRedirect('/machines');

        expect($user->machines()->count())->toBe(1);
    });

    it('imports pasted text', function () {
        $user = User::factory()->create();

        actingAs($user)->post('/imports', ['text' => reportMarkdown()])->assertRedirect('/machines');

        expect($user->machines()->count())->toBe(1);
    });

    it('prefers the file when both a file and text are sent', function () {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->createWithContent('report.md', reportMarkdown(['Serial' => 'FROMFILE']));

        actingAs($user)->post('/imports', ['file' => $file, 'text' => reportMarkdown(['Serial' => 'FROMTEXT'])])
            ->assertRedirect('/machines');

        expect($user->machines()->sole()->serial)->toBe('FROMFILE');
    });

    it('requires a file or text', function () {
        actingAs(User::factory()->create())->post('/imports', [])
            ->assertSessionHasErrors(['file', 'text']);
    });

    it('treats whitespace only text as nothing', function () {
        actingAs(User::factory()->create())->post('/imports', ['text' => "   \n\t "])
            ->assertSessionHasErrors(['file', 'text']);
    });

    it('rejects a file that is not markdown or text', function () {
        $file = UploadedFile::fake()->createWithContent('report.exe', reportMarkdown());

        actingAs(User::factory()->create())->post('/imports', ['file' => $file])
            ->assertSessionHasErrors('file');

        assertDatabaseCount('machines', 0);
    });

    it('rejects a file over two megabytes', function () {
        $file = UploadedFile::fake()->create('report.md', 2049);

        actingAs(User::factory()->create())->post('/imports', ['file' => $file])
            ->assertSessionHasErrors('file');
    });

    it('rejects pasted text over the size limit', function () {
        actingAs(User::factory()->create())->post('/imports', ['text' => str_repeat('a', 2_000_001)])
            ->assertSessionHasErrors('text');
    });

    it('reports a report without a usable identity as an error on the form', function () {
        actingAs(User::factory()->create())
            ->post('/imports', ['text' => reportMarkdown(['Serial' => 'None', 'Hardware UUID' => ''])])
            ->assertSessionHasErrors('report');
    });

    it('is not reachable with a wrong field type', function () {
        actingAs(User::factory()->create())->post('/imports', ['text' => ['nested'], 'file' => 'not a file'])
            ->assertSessionHasErrors(['text', 'file']);
    });

    it('does not import the same report twice when it is uploaded and then pasted', function () {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->createWithContent('report.md', reportMarkdown());

        actingAs($user)->post('/imports', ['file' => $file])->assertRedirect('/machines');
        actingAs($user)->post('/imports', ['text' => reportMarkdown()])->assertRedirect('/machines');

        assertDatabaseCount('reports', 1);
    });
});

describe('ImportReport edge cases', function () {
    afterEach(function () {
        Machine::flushEventListeners();
        Report::flushEventListeners();
    });

    it('does not roll a machine back when an older report is imported again', function () {
        $user = User::factory()->create();
        (new ImportReport)->handle($user, reportMarkdown(['Model' => 'Old model']));
        (new ImportReport)->handle($user, reportMarkdown(['Model' => 'New model'], flags: ['x']));

        (new ImportReport)->handle($user, reportMarkdown(['Model' => 'Old model']));

        expect(Machine::query()->sole()->model)->toBe('New model');
    });

    it('keeps a leading character that shares bytes with a byte order mark', function () {
        $user = User::factory()->create();

        $report = (new ImportReport)->handle($user, "\u{FF61}".reportMarkdown());

        expect($report->markdown)->toStartWith("\u{FF61}");
    });

    it('stores at most one hundred flags', function () {
        $user = User::factory()->create();

        $report = (new ImportReport)->handle($user, reportMarkdown(flags: array_map(fn (int $i): string => "flag {$i}", range(1, 150))));

        expect($report->flags)->toHaveCount(100)->and($report->flags[0])->toBe('flag 1');
    });

    it('rejects a placeholder hardware uuid', function (string $uuid) {
        $user = User::factory()->create();

        expect(fn () => (new ImportReport)->handle($user, reportMarkdown(['Serial' => 'None', 'Hardware UUID' => $uuid])))
            ->toThrow(ValidationException::class);
    })->with([
        'zeros' => '00000000-0000-0000-0000-000000000000',
        'effs' => 'FFFFFFFF-FFFF-FFFF-FFFF-FFFFFFFFFFFF',
        'too long' => str_repeat('a1', 60),
    ]);

    it('does not store a placeholder uuid on a machine identified by its serial', function () {
        $user = User::factory()->create();

        (new ImportReport)->handle($user, reportMarkdown(['Hardware UUID' => '00000000-0000-0000-0000-000000000000']));

        expect(Machine::query()->sole()->hardware_uuid)->toBeNull();
    });

    it('leaves another user’s deleted machine alone', function () {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        (new ImportReport)->handle($owner, reportMarkdown());
        Machine::query()->sole()->delete();

        (new ImportReport)->handle($other, reportMarkdown());

        expect(Machine::withTrashed()->where('user_id', $owner->id)->sole()->trashed())->toBeTrue()
            ->and($other->machines()->count())->toBe(1);
    });

    it('survives a concurrent import creating the same machine', function () {
        $user = User::factory()->create();
        $raced = false;

        Machine::creating(function () use ($user, &$raced): void {
            if ($raced) {
                return;
            }

            $raced = true;
            DB::table('machines')->insert([
                'user_id' => $user->id,
                'identity_key' => 'serial:C02ABC123XYZ',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        (new ImportReport)->handle($user, reportMarkdown());

        expect($raced)->toBeTrue();
        assertDatabaseCount('machines', 1);
        assertDatabaseCount('reports', 1);
    });

    it('survives a concurrent import creating the same report', function () {
        $user = User::factory()->create();
        (new ImportReport)->handle($user, reportMarkdown(flags: ['first']));
        $machine = Machine::query()->sole();
        $raced = false;

        Report::creating(function () use ($machine, &$raced): void {
            if ($raced) {
                return;
            }

            $raced = true;
            DB::table('reports')->insert([
                'machine_id' => $machine->id,
                'markdown' => 'x',
                'checksum' => hash('sha256', reportMarkdown(flags: ['second'])),
                'flags' => '[]',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        (new ImportReport)->handle($user, reportMarkdown(flags: ['second']));

        expect($raced)->toBeTrue();
        assertDatabaseCount('reports', 2);
    });
});
