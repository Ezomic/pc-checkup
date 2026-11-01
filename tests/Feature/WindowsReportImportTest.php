<?php

declare(strict_types=1);

use App\Actions\Machines\ImportReport;
use App\Models\Machine;
use App\Models\User;
use App\Services\Checklist\ChecklistResolver;
use App\Services\Reports\ReportParser;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\assertDatabaseCount;

function windowsReport(string $search = '', string $replace = ''): string
{
    $markdown = (string) file_get_contents(base_path('tests/Fixtures/windows-checkup-desktop.md'));

    return $search === '' ? $markdown : str_replace($search, $replace, $markdown);
}

describe('Windows checkup report', function () {
    it('is read like a Mac report', function () {
        $parsed = (new ReportParser)->parse(windowsReport());

        expect($parsed->name)->toBe('TEST-DESKTOP')
            ->and($parsed->formatVersion)->toBe(1)
            ->and($parsed->os)->toBe('Windows')
            ->and($parsed->deviceType)->toBe('desktop')
            ->and($parsed->serial)->toBeNull()
            ->and($parsed->hardwareUuid)->toBe('4C4C4544-0053-5410-8058-B7C04F4B4E31')
            ->and($parsed->model)->toBe('Gigabyte Technology Co., Ltd. Z790 GAMING X AX')
            ->and($parsed->flags)->toHaveCount(6)
            ->and($parsed->flags[0])->toBe('Secure Boot is OFF.')
            ->and($parsed->flags[1])->toBe('BitLocker is OFF on C:.');
    });

    it('imports as a machine identified by its uuid when the firmware serial was a placeholder', function () {
        $user = User::factory()->create();

        $report = (new ImportReport)->handle($user, windowsReport());

        $machine = Machine::query()->sole();
        expect($machine->identity_key)->toBe('uuid:4c4c4544-0053-5410-8058-b7c04f4b4e31')
            ->and($machine->serial)->toBeNull()
            ->and($machine->os)->toBe('Windows')
            ->and($machine->device_type)->toBe('desktop')
            ->and($machine->name)->toBe('TEST-DESKTOP')
            ->and($report->format_version)->toBe(1)
            ->and($report->flags)->toContain('Secure Boot is OFF.', 'BitLocker is OFF on C:.');
    });

    it('identifies a laptop with a real serial by the serial', function () {
        $user = User::factory()->create();
        $markdown = windowsReport('- Serial:', '- Serial: PF2ABCDE');
        $markdown = str_replace('- Device type: desktop', '- Device type: laptop', $markdown);

        (new ImportReport)->handle($user, $markdown);

        $machine = Machine::query()->sole();
        expect($machine->identity_key)->toBe('serial:PF2ABCDE')
            ->and($machine->device_type)->toBe('laptop');
    });

    it('gets the windows checklist group', function () {
        $user = User::factory()->create();
        (new ImportReport)->handle($user, windowsReport());

        $groups = collect((new ChecklistResolver)->for(Machine::query()->sole()))->pluck('group')->unique()->values()->all();

        expect($groups)->toBe(['common', 'desktop', 'windows']);
    });

    it('reads a report with windows line endings and a byte order mark', function () {
        $user = User::factory()->create();

        $first = (new ImportReport)->handle($user, windowsReport());
        $second = (new ImportReport)->handle($user, "\u{FEFF}".str_replace("\n", "\r\n", windowsReport()));

        expect($second->is($first))->toBeTrue();
    });

    it('rejects a report where firmware gave placeholders for both serial and uuid', function (string $serial, string $uuid) {
        $user = User::factory()->create();
        $markdown = str_replace(
            ['- Serial:', '4C4C4544-0053-5410-8058-B7C04F4B4E31'],
            ["- Serial: {$serial}", $uuid],
            windowsReport(),
        );

        expect(fn () => (new ImportReport)->handle($user, $markdown))->toThrow(ValidationException::class);

        assertDatabaseCount('machines', 0);
    })->with([
        'oem and zero uuid' => ['To Be Filled By O.E.M.', '00000000-0000-0000-0000-000000000000'],
        'default string and f uuid' => ['Default string', 'FFFFFFFF-FFFF-FFFF-FFFF-FFFFFFFFFFFF'],
        'empty and shared board uuid' => ['', '03000200-0400-0500-0006-000700080009'],
        'empty and other shared board uuid' => ['', '00020003-0004-0005-0006-000700080009'],
        'not defined and empty' => ['Not Defined', ''],
        'all zeros serial and empty' => ['0000000000', ''],
        'serialnumber and empty' => ['SerialNumber', ''],
    ]);

    it('falls back to the uuid for every windows serial placeholder', function (string $serial) {
        $user = User::factory()->create();

        (new ImportReport)->handle($user, windowsReport('- Serial:', "- Serial: {$serial}"));

        expect(Machine::query()->sole()->identity_key)->toStartWith('uuid:');
    })->with([
        'oem' => 'To Be Filled By O.E.M.',
        'default string' => 'Default string',
        'o.e.m.' => 'O.E.M.',
        'invalid' => 'INVALID',
        'zeros' => '0000000000',
        'chassis' => 'Chassis Serial Number',
        'not defined' => 'Not Defined',
    ]);
});
