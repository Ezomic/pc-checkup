<?php

declare(strict_types=1);

use App\Actions\Machines\CreateManualMachine;
use App\Enums\MachineStatus;
use App\Models\Machine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseCount;

describe('GET /machines/create', function () {
    it('requires authentication', function () {
        $this->get('/machines/create')->assertRedirect('/login');
    });

    it('renders the form and is not mistaken for a machine', function () {
        actingAs(User::factory()->create())->get('/machines/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('machines/Create'));
    });
});

describe('POST /machines', function () {
    it('requires authentication', function () {
        $this->post('/machines', ['serial' => 'C02ABC123XYZ'])->assertRedirect('/login');

        assertDatabaseCount('machines', 0);
    });

    it('creates a machine for the signed-in user and opens it', function () {
        $user = User::factory()->create();

        $response = actingAs($user)->post('/machines', [
            'serial' => 'C02ABC123XYZ',
            'hardware_uuid' => '11111111-2222-3333-4444-555555555555',
            'name' => 'Front desk',
            'os' => 'Windows 11',
            'device_type' => 'desktop',
            'model' => 'OptiPlex 7090',
        ]);

        $machine = Machine::query()->sole();
        $response->assertRedirect("/machines/{$machine->id}")
            ->assertSessionHas('inertia.flash_data.toast.type', 'success');
        expect($machine->user_id)->toBe($user->id)
            ->and($machine->identity_key)->toBe('serial:C02ABC123XYZ')
            ->and($machine->serial)->toBe('C02ABC123XYZ')
            ->and($machine->hardware_uuid)->toBe('11111111-2222-3333-4444-555555555555')
            ->and($machine->name)->toBe('Front desk')
            ->and($machine->os)->toBe('Windows 11')
            ->and($machine->device_type)->toBe('desktop')
            ->and($machine->model)->toBe('OptiPlex 7090')
            ->and($machine->status)->toBe(MachineStatus::NeedsWork);
    });

    it('needs only a serial', function () {
        actingAs(User::factory()->create())->post('/machines', ['serial' => 'ABC123'])->assertSessionDoesntHaveErrors();

        $machine = Machine::query()->sole();
        expect($machine->identity_key)->toBe('serial:ABC123')
            ->and($machine->hardware_uuid)->toBeNull()
            ->and($machine->name)->toBeNull()
            ->and($machine->os)->toBeNull()
            ->and($machine->device_type)->toBeNull()
            ->and($machine->model)->toBeNull();
    });

    it('falls back to the hardware uuid, lowercased, when there is no serial', function () {
        actingAs(User::factory()->create())->post('/machines', [
            'hardware_uuid' => 'AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE',
        ])->assertSessionDoesntHaveErrors();

        $machine = Machine::query()->sole();
        expect($machine->identity_key)->toBe('uuid:aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee')
            ->and($machine->serial)->toBeNull();
    });

    it('keys a lowercase serial the way the importer does', function () {
        actingAs(User::factory()->create())->post('/machines', ['serial' => 'c02abc123xyz']);

        $machine = Machine::query()->sole();
        expect($machine->identity_key)->toBe('serial:C02ABC123XYZ')
            ->and($machine->serial)->toBe('c02abc123xyz');
    });

    it('keys non-ascii text by its uppercase form', function () {
        actingAs(User::factory()->create())->post('/machines', ['serial' => 'straße-é1', 'name' => '会議室のPC 💻']);

        $machine = Machine::query()->sole();
        expect($machine->identity_key)->toBe('serial:STRASSE-É1')
            ->and($machine->name)->toBe('会議室のPC 💻');
    });

    it('trims the values', function () {
        actingAs(User::factory()->create())->post('/machines', ['serial' => "  ABC123\t", 'name' => '  Desk  ']);

        $machine = Machine::query()->sole();
        expect($machine->serial)->toBe('ABC123')
            ->and($machine->name)->toBe('Desk');
    });

    it('rejects a form with neither a serial nor a hardware uuid', function (array $input) {
        actingAs(User::factory()->create())->post('/machines', ['name' => 'Mystery box', ...$input])
            ->assertSessionHasErrors(['serial']);

        assertDatabaseCount('machines', 0);
    })->with([
        'both missing' => [[]],
        'both empty' => [['serial' => '', 'hardware_uuid' => '']],
        'both spaces' => [['serial' => '   ', 'hardware_uuid' => "\t "]],
        'non-breaking spaces' => [['serial' => "\u{00A0}\u{2003}", 'hardware_uuid' => null]],
    ]);

    it('rejects a placeholder serial', function (string $serial) {
        actingAs(User::factory()->create())->post('/machines', ['serial' => $serial])
            ->assertSessionHasErrors(['serial']);

        assertDatabaseCount('machines', 0);
    })->with([
        'oem' => 'To Be Filled By O.E.M.',
        'oem uppercase' => 'TO BE FILLED BY O.E.M.',
        'default string' => 'Default string',
        'system serial' => 'System Serial Number',
        'none' => 'None',
        'digits' => '123456789',
        'repeated zeros' => '0000000000',
        'repeated letters' => 'FFFFFFFF',
        'windows not defined' => 'Not Defined',
        'windows oem' => 'O.E.M.',
        'windows serialnumber' => 'SerialNumber',
    ]);

    it('rejects a placeholder uuid', function (string $uuid) {
        actingAs(User::factory()->create())->post('/machines', ['hardware_uuid' => $uuid])
            ->assertSessionHasErrors(['hardware_uuid']);

        assertDatabaseCount('machines', 0);
    })->with([
        'all zeros' => '00000000-0000-0000-0000-000000000000',
        'all fs' => 'FFFFFFFF-FFFF-FFFF-FFFF-FFFFFFFFFFFF',
        'shared board uuid' => '03000200-0400-0500-0006-000700080009',
        'other shared board uuid' => '00020003-0004-0005-0006-000700080009',
    ]);

    it('rejects a placeholder uuid next to a usable serial', function () {
        actingAs(User::factory()->create())->post('/machines', [
            'serial' => 'ABC123',
            'hardware_uuid' => '00000000-0000-0000-0000-000000000000',
        ])->assertSessionHasErrors(['hardware_uuid']);

        assertDatabaseCount('machines', 0);
    });

    it('rejects a placeholder even when the other identity is usable', function () {
        actingAs(User::factory()->create())->post('/machines', [
            'serial' => 'Default string',
            'hardware_uuid' => '11111111-2222-3333-4444-555555555555',
        ])->assertSessionHasErrors(['serial']);

        assertDatabaseCount('machines', 0);
    });

    it('rejects values that are too long', function (string $field, int $length) {
        actingAs(User::factory()->create())->post('/machines', [
            'serial' => 'ABC123',
            $field => str_repeat('a', $length),
        ])->assertSessionHasErrors([$field]);

        assertDatabaseCount('machines', 0);
    })->with([
        'serial' => ['serial', 101],
        'uuid' => ['hardware_uuid', 101],
        'name' => ['name', 256],
        'os' => ['os', 101],
        'model' => ['model', 256],
    ]);

    it('accepts values at the length limit', function () {
        actingAs(User::factory()->create())->post('/machines', [
            'serial' => str_repeat('a1', 50),
            'hardware_uuid' => str_repeat('b', 99).'c',
            'name' => str_repeat('n', 255),
            'os' => str_repeat('o', 100),
            'model' => str_repeat('m', 255),
        ])->assertSessionDoesntHaveErrors();

        assertDatabaseCount('machines', 1);
    });

    it('counts length in characters, not bytes', function () {
        actingAs(User::factory()->create())->post('/machines', [
            'serial' => str_repeat('é1', 50),
            'name' => str_repeat('会', 255),
        ])->assertSessionDoesntHaveErrors();

        assertDatabaseCount('machines', 1);
    });

    it('rejects a device type that is not laptop or desktop', function (mixed $value) {
        actingAs(User::factory()->create())->post('/machines', ['serial' => 'ABC123', 'device_type' => $value])
            ->assertSessionHasErrors(['device_type']);

        assertDatabaseCount('machines', 0);
    })->with(['tablet', 'LAPTOP', 'toaster', [['laptop']], '<script>']);

    it('accepts laptop and desktop', function (string $type) {
        actingAs(User::factory()->create())->post('/machines', ['serial' => 'ABC123', 'device_type' => $type]);

        expect(Machine::query()->sole()->device_type)->toBe($type);
    })->with(['laptop', 'desktop']);

    it('rejects non-string values', function () {
        actingAs(User::factory()->create())->post('/machines', ['serial' => ['ABC123'], 'name' => ['x']])
            ->assertSessionHasErrors(['serial', 'name']);

        assertDatabaseCount('machines', 0);
    });

    it('stores markup as plain text', function () {
        actingAs(User::factory()->create())->post('/machines', ['serial' => 'ABC123', 'name' => '<script>alert(1)</script>']);

        expect(Machine::query()->sole()->name)->toBe('<script>alert(1)</script>');
    });

    it('ignores a user id, status or identity key in the input', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();

        actingAs($user)->post('/machines', [
            'serial' => 'ABC123',
            'user_id' => $other->id,
            'status' => 'ok',
            'identity_key' => 'serial:HACKED',
        ]);

        $machine = Machine::query()->sole();
        expect($machine->user_id)->toBe($user->id)
            ->and($machine->status)->toBe(MachineStatus::NeedsWork)
            ->and($machine->identity_key)->toBe('serial:ABC123');
    });
});

describe('an identity that is already registered', function () {
    it('opens the existing machine and changes nothing', function () {
        $user = User::factory()->create();
        $existing = Machine::factory()->for($user)->create([
            'identity_key' => 'serial:ABC123',
            'serial' => 'ABC123',
            'name' => 'Original',
            'status' => MachineStatus::Ok,
        ]);

        actingAs($user)->post('/machines', ['serial' => 'abc123', 'name' => 'Changed', 'os' => 'Plan 9'])
            ->assertRedirect("/machines/{$existing->id}")
            ->assertSessionHas('inertia.flash_data.toast.type', 'info');

        assertDatabaseCount('machines', 1);
        $fresh = $existing->fresh();
        expect($fresh?->name)->toBe('Original')
            ->and($fresh?->os)->toBe($existing->os)
            ->and($fresh?->status)->toBe(MachineStatus::Ok)
            ->and($fresh?->updated_at?->equalTo($existing->updated_at))->toBeTrue();
    });

    it('treats the same serial submitted twice as one machine', function () {
        $user = User::factory()->create();

        actingAs($user)->post('/machines', ['serial' => 'ABC123']);
        actingAs($user)->post('/machines', ['serial' => 'ABC123']);

        assertDatabaseCount('machines', 1);
    });

    it('matches a machine the importer created from the same uuid', function () {
        $user = User::factory()->create();
        $existing = Machine::factory()->for($user)->create(['identity_key' => 'uuid:aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee']);

        actingAs($user)->post('/machines', ['hardware_uuid' => 'AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE'])
            ->assertRedirect("/machines/{$existing->id}");

        assertDatabaseCount('machines', 1);
    });

    it('creates the user’s own machine when another user owns the identity', function () {
        $other = User::factory()->create();
        $theirs = Machine::factory()->for($other)->create([
            'identity_key' => 'serial:ABC123',
            'serial' => 'ABC123',
            'name' => 'Theirs',
        ]);
        $user = User::factory()->create();

        actingAs($user)->post('/machines', ['serial' => 'ABC123', 'name' => 'Mine'])
            ->assertSessionHas('inertia.flash_data.toast.type', 'success');

        assertDatabaseCount('machines', 2);
        $mine = Machine::query()->where('user_id', $user->id)->sole();
        expect($mine->id)->not->toBe($theirs->id)
            ->and($mine->name)->toBe('Mine')
            ->and($theirs->fresh()?->name)->toBe('Theirs')
            ->and($theirs->fresh()?->user_id)->toBe($other->id);
    });

    it('restores a deleted machine, as importing a report does', function () {
        $user = User::factory()->create();
        $existing = Machine::factory()->for($user)->create([
            'identity_key' => 'serial:ABC123',
            'serial' => 'ABC123',
            'name' => 'Old name',
            'os' => 'macOS',
            'status' => MachineStatus::Reject,
        ]);
        $existing->delete();

        actingAs($user)->post('/machines', ['serial' => 'ABC123', 'name' => 'New name'])
            ->assertRedirect("/machines/{$existing->id}")
            ->assertSessionHas('inertia.flash_data.toast.type', 'success');

        assertDatabaseCount('machines', 1);
        $fresh = Machine::query()->sole();
        expect($fresh->id)->toBe($existing->id)
            ->and($fresh->name)->toBe('New name')
            ->and($fresh->os)->toBe('macOS')
            ->and($fresh->status)->toBe(MachineStatus::Reject)
            ->and($fresh->trashed())->toBeFalse();
    });
});

describe('CreateManualMachine', function () {
    it('survives a concurrent request creating the same machine', function () {
        $user = User::factory()->create();
        $raced = false;

        Machine::creating(function () use ($user, &$raced): void {
            if ($raced) {
                return;
            }

            $raced = true;
            DB::table('machines')->insert([
                'user_id' => $user->id,
                'identity_key' => 'serial:ABC123',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $machine = (new CreateManualMachine)->handle($user, 'ABC123', null, null, null, null, null);

        expect($raced)->toBeTrue()
            ->and($machine->identity_key)->toBe('serial:ABC123');
        assertDatabaseCount('machines', 1);
    });

    it('trims and drops blank values when called directly', function () {
        $user = User::factory()->create();

        $machine = (new CreateManualMachine)->handle($user, '  ', ' AA-1 ', '', "\u{00A0}", ' laptop ', null);

        expect($machine->identity_key)->toBe('uuid:aa-1')
            ->and($machine->serial)->toBeNull()
            ->and($machine->name)->toBeNull()
            ->and($machine->os)->toBeNull()
            ->and($machine->device_type)->toBe('laptop');
    });

    it('throws a validation error without a usable identity', function () {
        (new CreateManualMachine)->handle(User::factory()->create(), null, null, null, null, null, null);
    })->throws(ValidationException::class);
});
