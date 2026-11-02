<?php

declare(strict_types=1);

use App\Actions\Machines\ImportReport;
use App\Models\Client;
use App\Models\Machine;
use App\Models\ScanCode;
use App\Models\User;
use App\Services\Reports\ReportParser;
use App\Services\RustDesk\RustDeskId;
use Illuminate\Support\Facades\Process;

use function Pest\Laravel\actingAs;

const ODD_RUSTDESK_IDS = [
    'one space inside' => '123 456 789',
    'too short' => 'abc',
    'too long' => 'a23456789012345678901234567890123456789012345678901234567890123456',
    'unicode' => 'idé12345',
    'url' => 'https://example.test/123456789',
    'rustdesk link' => 'rustdesk://123456789',
    'html' => '<b>123456789</b>',
    'script' => '<script>alert(1)</script>',
    'quote' => "123456789'",
    'semicolon' => '123456789; rm -rf',
    'newline inside' => "1234\n5678",
    'array' => [['123456789']],
];

function rustDeskReport(string $id = '123456789', string $serial = 'C02RUSTDESK01'): string
{
    return reportMarkdown(['Serial' => $serial, 'RustDesk ID' => $id]);
}

function macRustDesk(string $config): string
{
    $dir = sys_get_temp_dir().'/pcc-rd-'.bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);
    $path = $dir.'/RustDesk.toml';
    file_put_contents($path, $config);

    $result = Process::path(base_path())
        ->env(['PCC_RUSTDESK_CONFIG' => $path])
        ->run(['bash', '-c', 'source scripts/mac-checkup.sh; pcc_rustdesk_check']);

    expect($result->errorOutput())->toBe('');

    return trim($result->output());
}

describe('RustDeskId', function () {
    it('accepts the ids RustDesk and self-hosted servers hand out', function (string $id) {
        expect(RustDeskId::isValid($id))->toBeTrue();
    })->with(['123456789', 'abcd', 'Office_PC-01', str_repeat('a', 64)]);

    it('rejects anything else', function (mixed $id) {
        expect(RustDeskId::isValid($id))->toBeFalse();
    })->with([...ODD_RUSTDESK_IDS, 'empty' => '', 'blank' => '   ', 'null' => null, 'number' => 123456789]);
});

describe('ReportParser', function () {
    it('reads the id from the header', function () {
        expect((new ReportParser)->parse(rustDeskReport())->rustdeskId)->toBe('123456789');
    });

    it('has no id for a report from an older script', function () {
        expect((new ReportParser)->parse(reportMarkdown())->rustdeskId)->toBeNull();
    });

    it('has no id when the scan said it found none', function () {
        $report = rustDeskReport('not found (RustDesk is not installed, or its settings file holds no readable ID)');

        expect((new ReportParser)->parse($report)->rustdeskId)->toBeNull();
    });

    it('ignores an invalid id', function (string $id) {
        expect((new ReportParser)->parse(rustDeskReport($id))->rustdeskId)->toBeNull();
    })->with(array_filter(ODD_RUSTDESK_IDS, fn (mixed $id, string $label) => is_string($id) && $label !== 'newline inside', ARRAY_FILTER_USE_BOTH));

    it('ignores an id quoted below the first section', function () {
        $report = reportMarkdown()."\n- RustDesk ID: 999999999\n";

        expect((new ReportParser)->parse($report)->rustdeskId)->toBeNull();
    });

    it('uses the last of several id lines', function () {
        $report = str_replace("\n\n## Things", "\n- RustDesk ID: 222222222\n\n## Things", rustDeskReport('111111111'));

        expect((new ReportParser)->parse($report)->rustdeskId)->toBe('222222222');
    });

    it('ignores several id lines when the last one is invalid', function () {
        $report = str_replace("\n\n## Things", "\n- RustDesk ID: nope nope\n\n## Things", rustDeskReport('111111111'));

        expect((new ReportParser)->parse($report)->rustdeskId)->toBeNull();
    });
});

describe('importing a report', function () {
    it('stores the id on a new machine', function () {
        $user = User::factory()->create();

        (new ImportReport)->handle($user, rustDeskReport());

        expect(Machine::query()->sole()->rustdesk_id)->toBe('123456789');
    });

    it('overwrites the stored id when a later scan reports another', function () {
        $user = User::factory()->create();
        (new ImportReport)->handle($user, rustDeskReport('111111111'));

        (new ImportReport)->handle($user, rustDeskReport('222222222'));

        expect(Machine::query()->sole()->rustdesk_id)->toBe('222222222');
    });

    it('keeps the stored id when a report carries none', function () {
        $user = User::factory()->create();
        (new ImportReport)->handle($user, rustDeskReport());

        (new ImportReport)->handle($user, reportMarkdown(['Serial' => 'C02RUSTDESK01', 'Model' => 'Newer model']));

        $machine = Machine::query()->sole();
        expect($machine->rustdesk_id)->toBe('123456789')
            ->and($machine->model)->toBe('Newer model');
    });

    it('keeps the stored id when a report carries an invalid one', function () {
        $user = User::factory()->create();
        (new ImportReport)->handle($user, rustDeskReport());

        (new ImportReport)->handle($user, rustDeskReport('rustdesk://evil', 'C02RUSTDESK01'));

        expect(Machine::query()->sole()->rustdesk_id)->toBe('123456789');
    });

    it('does not let a report change another user\'s machine', function () {
        $other = Machine::factory()->create(['identity_key' => 'serial:C02RUSTDESK01', 'rustdesk_id' => 'theirs-1']);

        (new ImportReport)->handle(User::factory()->create(), rustDeskReport());

        expect($other->fresh()?->rustdesk_id)->toBe('theirs-1')
            ->and(Machine::query()->count())->toBe(2);
    });

    it('is picked up through the import page', function () {
        $user = User::factory()->create();

        actingAs($user)->post('/imports', ['text' => rustDeskReport()])->assertRedirect();

        expect($user->machines()->sole()->rustdesk_id)->toBe('123456789');
    });

    it('is picked up through a scan code', function () {
        $user = User::factory()->create();
        ScanCode::factory()->for($user)->withCode('123456')->create();

        $this->postJson('/api/scans', ['code' => '123456', 'markdown' => rustDeskReport()])->assertCreated();

        expect($user->machines()->sole()->rustdesk_id)->toBe('123456789');
    });
});

describe('PUT /machines/{machine}/rustdesk', function () {
    it('requires a login', function () {
        $machine = Machine::factory()->create();

        $this->put("/machines/{$machine->id}/rustdesk", ['rustdesk_id' => '123456789'])->assertRedirect('/login');
    });

    it('saves a valid id', function (string $id) {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();

        actingAs($user)->put("/machines/{$machine->id}/rustdesk", ['rustdesk_id' => $id])
            ->assertRedirect("/machines/{$machine->id}")
            ->assertSessionHasNoErrors();

        expect($machine->fresh()?->rustdesk_id)->toBe($id);
    })->with(['123456789', 'Office_PC-01']);

    it('trims the id', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();

        actingAs($user)->put("/machines/{$machine->id}/rustdesk", ['rustdesk_id' => "  123456789 \n"]);

        expect($machine->fresh()?->rustdesk_id)->toBe('123456789');
    });

    it('clears the id when sent empty or blank', function (mixed $value) {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create(['rustdesk_id' => '123456789']);

        actingAs($user)->put("/machines/{$machine->id}/rustdesk", ['rustdesk_id' => $value])->assertSessionHasNoErrors();

        expect($machine->fresh()?->rustdesk_id)->toBeNull();
    })->with(['', '   ', null]);

    it('clears the id when the field is missing', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create(['rustdesk_id' => '123456789']);

        actingAs($user)->put("/machines/{$machine->id}/rustdesk", [])->assertSessionHasNoErrors();

        expect($machine->fresh()?->rustdesk_id)->toBeNull();
    });

    it('rejects an odd id and keeps the old one', function (mixed $id) {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create(['rustdesk_id' => '123456789']);

        actingAs($user)->put("/machines/{$machine->id}/rustdesk", ['rustdesk_id' => $id])
            ->assertSessionHasErrors('rustdesk_id');

        expect($machine->fresh()?->rustdesk_id)->toBe('123456789');
    })->with(ODD_RUSTDESK_IDS);

    it('forbids another user\'s machine', function () {
        $machine = Machine::factory()->create(['rustdesk_id' => 'theirs-1']);

        actingAs(User::factory()->create())->put("/machines/{$machine->id}/rustdesk", ['rustdesk_id' => '123456789'])
            ->assertForbidden();
        actingAs(User::factory()->create())->put("/machines/{$machine->id}/rustdesk", ['rustdesk_id' => null])
            ->assertForbidden();

        expect($machine->fresh()?->rustdesk_id)->toBe('theirs-1');
    });
});

describe('the owner pages', function () {
    it('gives the machine page the id', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create(['rustdesk_id' => '123456789']);

        actingAs($user)->get("/machines/{$machine->id}")
            ->assertInertia(fn ($page) => $page->where('machine.rustdesk_id', '123456789'));
    });

    it('gives the machine page null when there is no id', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();

        actingAs($user)->get("/machines/{$machine->id}")
            ->assertInertia(fn ($page) => $page->where('machine.rustdesk_id', null));
    });

    it('leaves the id out of the printable summary', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create(['rustdesk_id' => 'secret-id-77']);

        actingAs($user)->get("/machines/{$machine->id}/summary")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->missing('machine.rustdesk_id'))
            ->assertDontSee('secret-id-77');
    });
});

describe('the public share pages', function () {
    it('never carry the id, in props or in the page', function () {
        $client = Client::factory()->withShareLink()->create();
        $user = $client->user;
        (new ImportReport)->handle($user, rustDeskReport('leaky-id-4242', 'C02LEAKYAAA1'));
        $machine = $user->machines()->sole();
        $machine->forceFill(['client_id' => $client->id, 'name' => 'Front desk'])->save();

        $paths = ["/c/{$client->share_token}", "/c/{$client->share_token}/machines/{$machine->id}"];

        foreach ($paths as $path) {
            $this->get($path)->assertOk()->assertDontSee('leaky-id-4242')->assertDontSee('rustdesk', false);
            $this->get($path, ['X-Inertia' => 'true', 'X-Inertia-Version' => ''])->assertDontSee('leaky-id-4242');
        }

        expect($machine->fresh()?->rustdesk_id)->toBe('leaky-id-4242');
    });
});

describe('the Mac scan', function () {
    it('reads the id line of the settings file', function () {
        expect(macRustDesk("id = '123456789'\npassword = 'hunter2'\n"))->toBe('RustDesk ID: 123456789');
    });

    it('accepts double quotes and custom ids', function () {
        expect(macRustDesk("id = \"Office_PC-01\"\n"))->toBe('RustDesk ID: Office_PC-01');
    });

    it('never prints any other field', function () {
        $output = macRustDesk("enc_id = 'secretenc'\npassword = 'hunter2'\nsalt = 'saltysalt'\nid = '123456789'\nkey_pair = 'PAIRPAIR'\n");

        expect($output)->toBe('RustDesk ID: 123456789');
    });

    it('ignores an id inside a table', function () {
        expect(macRustDesk("[options]\nid = '999999999'\n"))->toStartWith('RustDesk ID: not found');
    });

    it('says so when the file holds no readable id', function (string $config) {
        expect(macRustDesk($config))->toStartWith('RustDesk ID: not found');
    })->with([
        'no id line' => "enc_id = 'x'\n",
        'empty file' => '',
        'too short' => "id = 'abc'\n",
        'spaces' => "id = '123 456 789'\n",
        'url' => "id = 'rustdesk://123456789'\n",
        'script' => "id = '<script>'\n",
    ]);

    it('says so when RustDesk is not installed', function () {
        $result = Process::path(base_path())
            ->env(['PCC_RUSTDESK_CONFIG' => '/nonexistent/RustDesk.toml'])
            ->run(['bash', '-c', 'source scripts/mac-checkup.sh; pcc_rustdesk_check']);

        expect(trim($result->output()))->toStartWith('RustDesk ID: not found')
            ->and($result->errorOutput())->toBe('');
    });

    it('parses back out of a report', function () {
        $line = macRustDesk("id = '123456789'\n");

        $report = str_replace('- RustDesk ID: 123456789', '- '.$line, rustDeskReport());

        expect((new ReportParser)->parse($report)->rustdeskId)->toBe('123456789');
    });
});

describe('what the scans do with RustDesk', function () {
    it('only read its settings file and never run it', function (string $file) {
        $script = (string) file_get_contents(base_path($file));

        expect($script)->not->toMatch('/--get-id|--password|rustdesk(?:\.exe)?["\']?\s+--|Start-Process[^\n]*rustdesk|&\s*["\'][^\n]*rustdesk[^\n]*\.exe|open\s+-a\s+RustDesk/i');
    })->with(['scripts/windows-checkup.ps1', 'scripts/mac-checkup.sh']);

    it('write the id line in a form the importer reads', function () {
        $script = (string) file_get_contents(base_path('scripts/windows-checkup.ps1'));

        expect($script)->toContain('$lines.Add("- RustDesk ID: $rustDeskText")')
            ->and((string) file_get_contents(base_path('scripts/mac-checkup.sh')))->toContain('echo "- $(pcc_rustdesk_check)"');
    });

    it('only look for the user settings file, and the service one when elevated', function () {
        $script = (string) file_get_contents(base_path('scripts/windows-checkup.ps1'));

        expect($script)->toContain('RustDesk\config\RustDesk.toml')
            ->and($script)->toContain('if ($IsAdmin) { $paths +=');
    });
});
