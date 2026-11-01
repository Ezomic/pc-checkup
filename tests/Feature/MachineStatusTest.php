<?php

declare(strict_types=1);

use App\Actions\Machines\ImportReport;
use App\Enums\MachineStatus;
use App\Models\Machine;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('starts as checking', function () {
    $machine = Machine::factory()->for(User::factory())->create();

    expect($machine->fresh()?->status)->toBe(MachineStatus::Checking);
});

it('defaults to checking for a machine created without a status', function () {
    $user = User::factory()->create();
    $machine = (new Machine)->forceFill(['user_id' => $user->id, 'identity_key' => 'serial:X1'])->saveQuietly();

    expect($machine)->toBeTrue()
        ->and(Machine::query()->sole()->status)->toBe(MachineStatus::Checking);
});

it('shows the status on the machine page and the list', function () {
    $user = User::factory()->create();
    $machine = Machine::factory()->for($user)->create(['status' => MachineStatus::NeedsWork]);

    actingAs($user)->get("/machines/{$machine->id}")
        ->assertInertia(fn ($page) => $page->where('machine.status', 'needs_work'));
    actingAs($user)->get('/machines')
        ->assertInertia(fn ($page) => $page->where('machines.data.0.status', 'needs_work'));
});

describe('PUT /machines/{machine}/status', function () {
    it('changes the status', function (string $status) {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();

        actingAs($user)->put("/machines/{$machine->id}/status", ['status' => $status])
            ->assertRedirect("/machines/{$machine->id}");

        expect($machine->fresh()?->status->value)->toBe($status);
    })->with(['checking', 'ready', 'needs_work']);

    it('rejects a missing status', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();

        actingAs($user)->put("/machines/{$machine->id}/status", [])->assertSessionHasErrors('status');
        actingAs($user)->put("/machines/{$machine->id}/status", ['status' => null])->assertSessionHasErrors('status');

        expect($machine->fresh()?->status)->toBe(MachineStatus::Checking);
    });

    it('rejects an unknown or malformed status', function (mixed $status) {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create(['status' => MachineStatus::Ready]);

        actingAs($user)->put("/machines/{$machine->id}/status", ['status' => $status])->assertSessionHasErrors('status');

        expect($machine->fresh()?->status)->toBe(MachineStatus::Ready);
    })->with(['reject', 'READY', '', [['ready']], 1]);

    it('forbids another user’s machine', function () {
        $machine = Machine::factory()->create();

        actingAs(User::factory()->create())->put("/machines/{$machine->id}/status", ['status' => 'ready'])->assertForbidden();

        expect($machine->fresh()?->status)->toBe(MachineStatus::Checking);
    });

    it('does not find a deleted machine', function () {
        $user = User::factory()->create();
        $machine = Machine::factory()->for($user)->create();
        $machine->delete();

        actingAs($user)->put("/machines/{$machine->id}/status", ['status' => 'ready'])->assertNotFound();
    });

    it('requires authentication', function () {
        $machine = Machine::factory()->create();

        $this->put("/machines/{$machine->id}/status", ['status' => 'ready'])->assertRedirect('/login');

        expect($machine->fresh()?->status)->toBe(MachineStatus::Checking);
    });
});

it('leaves the status alone when a report is imported for the machine', function () {
    $user = User::factory()->create();
    $markdown = "# Mac checkup\n\n- Format: 1\n- OS: macOS\n- Device type: laptop\n- Serial: C02TESTSERIAL1\n- Model: MacBook\n";

    $first = (new ImportReport)->handle($user, $markdown);
    $machine = $first->machine;
    $machine->forceFill(['status' => MachineStatus::Ready])->save();

    (new ImportReport)->handle($user, $markdown."\nExtra line\n");

    expect($machine->fresh()?->status)->toBe(MachineStatus::Ready);
});
