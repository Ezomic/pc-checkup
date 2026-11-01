<?php

declare(strict_types=1);

use App\Models\Machine;
use App\Models\MachineNote;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

it('lists notes newest first on the machine page', function () {
    $user = User::factory()->create();
    $machine = Machine::factory()->for($user)->create();
    $old = MachineNote::factory()->for($machine)->create(['created_at' => '2026-10-01 10:00:00']);
    $new = MachineNote::factory()->for($machine)->create(['created_at' => '2026-10-05 10:00:00']);
    MachineNote::factory()->create();

    actingAs($user)->get("/machines/{$machine->id}")
        ->assertInertia(fn ($page) => $page
            ->has('notes', 2)
            ->where('notes.0.id', $new->id)
            ->where('notes.1.id', $old->id));
});

it('adds a note', function () {
    $user = User::factory()->create();
    $machine = Machine::factory()->for($user)->create();

    actingAs($user)->post("/machines/{$machine->id}/notes", ['body' => 'Fan is loud under load.'])
        ->assertRedirect("/machines/{$machine->id}");

    assertDatabaseHas('machine_notes', ['machine_id' => $machine->id, 'body' => 'Fan is loud under load.']);
});

it('rejects an empty, whitespace only, oversized or non text note', function (mixed $body) {
    $user = User::factory()->create();
    $machine = Machine::factory()->for($user)->create();

    actingAs($user)->post("/machines/{$machine->id}/notes", ['body' => $body])->assertSessionHasErrors('body');

    assertDatabaseCount('machine_notes', 0);
})->with([
    'missing' => [null],
    'empty' => [''],
    'whitespace' => ["  \n\t "],
    'too long' => [fn () => str_repeat('a', 5001)],
    'array' => [['x']],
]);

it('keeps markup in a note as plain text', function () {
    $user = User::factory()->create();
    $machine = Machine::factory()->for($user)->create();

    actingAs($user)->post("/machines/{$machine->id}/notes", ['body' => '<script>alert(1)</script>']);

    expect(MachineNote::query()->sole()->body)->toBe('<script>alert(1)</script>');
});

it('forbids adding a note to another user’s machine', function () {
    $machine = Machine::factory()->create();

    actingAs(User::factory()->create())->post("/machines/{$machine->id}/notes", ['body' => 'x'])->assertForbidden();

    assertDatabaseCount('machine_notes', 0);
});

it('deletes a note', function () {
    $user = User::factory()->create();
    $machine = Machine::factory()->for($user)->create();
    $note = MachineNote::factory()->for($machine)->create();

    actingAs($user)->delete("/machines/{$machine->id}/notes/{$note->id}")->assertRedirect("/machines/{$machine->id}");

    assertDatabaseMissing('machine_notes', ['id' => $note->id]);
});

it('forbids deleting a note on another user’s machine', function () {
    $machine = Machine::factory()->create();
    $note = MachineNote::factory()->for($machine)->create();

    actingAs(User::factory()->create())->delete("/machines/{$machine->id}/notes/{$note->id}")->assertForbidden();

    assertDatabaseHas('machine_notes', ['id' => $note->id]);
});

it('cannot delete a note through a different machine of the same user', function () {
    $user = User::factory()->create();
    $mine = Machine::factory()->for($user)->create();
    $other = Machine::factory()->for($user)->create();
    $note = MachineNote::factory()->for($other)->create();

    actingAs($user)->delete("/machines/{$mine->id}/notes/{$note->id}")->assertNotFound();

    assertDatabaseHas('machine_notes', ['id' => $note->id]);
});

it('requires authentication', function () {
    $machine = Machine::factory()->create();

    $this->post("/machines/{$machine->id}/notes", ['body' => 'x'])->assertRedirect('/login');
});
