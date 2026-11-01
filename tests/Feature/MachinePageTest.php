<?php

declare(strict_types=1);

use App\Models\Machine;
use App\Models\Report;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('requires authentication', function () {
    $machine = Machine::factory()->create();

    $this->get("/machines/{$machine->id}")->assertRedirect('/login');
});

it('shows the machine with its latest report', function () {
    $user = User::factory()->create();
    $machine = Machine::factory()->for($user)->create(['name' => 'Demo', 'os' => 'macOS', 'device_type' => 'laptop']);
    Report::factory()->for($machine)->create(['markdown' => "# old\n", 'flags' => ['old flag'], 'created_at' => '2026-10-01 10:00:00']);
    $latest = Report::factory()->for($machine)->create(['markdown' => "# new\n", 'flags' => ['FileVault is OFF.'], 'created_at' => '2026-10-05 10:00:00']);

    actingAs($user)->get("/machines/{$machine->id}")
        ->assertInertia(fn ($page) => $page
            ->component('machines/Show')
            ->where('machine.id', $machine->id)
            ->where('machine.name', 'Demo')
            ->where('machine.os', 'macOS')
            ->where('report.id', $latest->id)
            ->where('report.flags', ['FileVault is OFF.'])
            ->where('report.markdown', "# new\n"));
});

it('shows no report for a machine without one', function () {
    $user = User::factory()->create();
    $machine = Machine::factory()->for($user)->create();

    actingAs($user)->get("/machines/{$machine->id}")
        ->assertInertia(fn ($page) => $page->where('report', null));
});

it('ignores a deleted report', function () {
    $user = User::factory()->create();
    $machine = Machine::factory()->for($user)->create();
    Report::factory()->for($machine)->create()->delete();

    actingAs($user)->get("/machines/{$machine->id}")
        ->assertInertia(fn ($page) => $page->where('report', null));
});

it('forbids another user’s machine', function () {
    $machine = Machine::factory()->create();

    actingAs(User::factory()->create())->get("/machines/{$machine->id}")->assertForbidden();
});

it('hides a deleted machine', function () {
    $user = User::factory()->create();
    $machine = Machine::factory()->for($user)->create();
    $machine->delete();

    actingAs($user)->get("/machines/{$machine->id}")->assertNotFound();
});

it('does not expose the other user’s identity key or another machine’s report', function () {
    $user = User::factory()->create();
    $machine = Machine::factory()->for($user)->create();
    Report::factory()->create(['markdown' => 'someone else']);

    actingAs($user)->get("/machines/{$machine->id}")
        ->assertInertia(fn ($page) => $page->missing('machine.identity_key')->where('report', null));
});
