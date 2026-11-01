<?php

declare(strict_types=1);

use App\Enums\ChecklistOutcome;
use App\Enums\MachineStatus;
use App\Models\ChecklistEntry;
use App\Models\Machine;
use App\Models\MachineNote;
use App\Models\Photo;
use App\Models\Report;
use App\Models\User;
use App\Services\Checklist\ChecklistResolver;

use function Pest\Laravel\actingAs;

it('requires authentication', function () {
    $machine = Machine::factory()->create();

    $this->get("/machines/{$machine->id}/summary")->assertRedirect('/login');
});

it('forbids another user’s machine', function () {
    $machine = Machine::factory()->create();

    actingAs(User::factory()->create())->get("/machines/{$machine->id}/summary")->assertForbidden();
});

it('does not find a deleted machine', function () {
    $user = User::factory()->create();
    $machine = Machine::factory()->for($user)->create();
    $machine->delete();

    actingAs($user)->get("/machines/{$machine->id}/summary")->assertNotFound();
});

it('shows the machine details and status', function () {
    $user = User::factory()->create();
    $machine = Machine::factory()->for($user)->create([
        'name' => 'Demo', 'serial' => 'C02ABC', 'os' => 'macOS', 'device_type' => 'laptop',
        'model' => 'MacBook Pro', 'hardware_uuid' => 'uuid-1', 'status' => MachineStatus::Reject,
    ]);

    actingAs($user)->get("/machines/{$machine->id}/summary")
        ->assertInertia(fn ($page) => $page
            ->component('machines/Summary')
            ->where('machine.id', $machine->id)
            ->where('machine.name', 'Demo')
            ->where('machine.serial', 'C02ABC')
            ->where('machine.hardware_uuid', 'uuid-1')
            ->where('machine.os', 'macOS')
            ->where('machine.device_type', 'laptop')
            ->where('machine.model', 'MacBook Pro')
            ->where('machine.status', 'reject'));
});

it('shows the flags of the latest report without the raw markdown', function () {
    $user = User::factory()->create();
    $machine = Machine::factory()->for($user)->create();
    Report::factory()->for($machine)->create(['flags' => ['old flag'], 'created_at' => '2026-10-01 10:00:00']);
    $latest = Report::factory()->for($machine)->create(['markdown' => "# secret body\n", 'flags' => ['FileVault is OFF.'], 'created_at' => '2026-10-05 10:00:00']);

    actingAs($user)->get("/machines/{$machine->id}/summary")
        ->assertInertia(fn ($page) => $page
            ->where('report.flags', ['FileVault is OFF.'])
            ->where('report.created_at', fn ($value) => str_starts_with((string) $value, '2026-10-05'))
            ->missing('report.markdown')
            ->missing('report.id')
            ->etc());

    expect($latest->exists)->toBeTrue();
});

it('handles a machine with no report, no checklist entries and no notes', function () {
    $user = User::factory()->create();
    $machine = Machine::factory()->for($user)->create(['os' => 'macOS', 'device_type' => 'laptop']);
    $total = count((new ChecklistResolver)->for($machine));

    actingAs($user)->get("/machines/{$machine->id}/summary")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('report', null)
            ->where('notes', [])
            ->has('checklist.fail', 0)
            ->has('checklist.pass', 0)
            ->has('checklist.na', 0)
            ->has('checklist.unchecked', $total));
});

it('groups checklist results with fails first, then passes, n/a and unchecked', function () {
    $user = User::factory()->create();
    $machine = Machine::factory()->for($user)->create(['os' => 'macOS', 'device_type' => 'laptop']);
    $items = (new ChecklistResolver)->for($machine);
    [$a, $b, $c, $d] = array_column($items, 'key');
    ChecklistEntry::factory()->for($machine)->create(['item_key' => $a, 'outcome' => ChecklistOutcome::Pass, 'note' => 'fine']);
    ChecklistEntry::factory()->for($machine)->create(['item_key' => $b, 'outcome' => ChecklistOutcome::Fail, 'note' => 'cracked']);
    ChecklistEntry::factory()->for($machine)->create(['item_key' => $c, 'outcome' => ChecklistOutcome::NotApplicable, 'note' => null]);

    actingAs($user)->get("/machines/{$machine->id}/summary")
        ->assertInertia(fn ($page) => $page
            ->where('checklist.fail.0.key', $b)
            ->where('checklist.fail.0.note', 'cracked')
            ->has('checklist.fail.0.label')
            ->has('checklist.fail.0.group')
            ->where('checklist.pass.0.key', $a)
            ->where('checklist.pass.0.note', 'fine')
            ->where('checklist.na.0.key', $c)
            ->where('checklist.na.0.note', null)
            ->where('checklist.unchecked.0.key', $d)
            ->has('checklist.unchecked', count($items) - 3));
});

it('ignores checklist entries from another machine', function () {
    $user = User::factory()->create();
    $machine = Machine::factory()->for($user)->create(['os' => 'macOS', 'device_type' => 'laptop']);
    $key = (new ChecklistResolver)->for($machine)[0]['key'];
    ChecklistEntry::factory()->create(['item_key' => $key, 'outcome' => ChecklistOutcome::Fail]);

    actingAs($user)->get("/machines/{$machine->id}/summary")
        ->assertInertia(fn ($page) => $page->has('checklist.fail', 0));
});

it('lists only this machine’s notes and no photos', function () {
    $user = User::factory()->create();
    $machine = Machine::factory()->for($user)->create();
    MachineNote::factory()->for($machine)->create(['body' => 'Scratch on the lid']);
    MachineNote::factory()->create(['body' => 'someone else']);
    Photo::factory()->for($machine)->create();

    actingAs($user)->get("/machines/{$machine->id}/summary")
        ->assertInertia(fn ($page) => $page
            ->has('notes', 1)
            ->where('notes.0.body', 'Scratch on the lid')
            ->missing('photos')
            ->etc());
});
