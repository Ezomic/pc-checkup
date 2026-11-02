<?php

declare(strict_types=1);

use App\Models\Machine;
use App\Models\Report;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('requires authentication', function () {
    $this->get('/machines')->assertRedirect('/login');
});

it('lists only the current user’s machines', function () {
    $user = User::factory()->create();
    $mine = Machine::factory()->for($user)->create();
    Machine::factory()->create();

    actingAs($user)->get('/machines')
        ->assertInertia(fn ($page) => $page
            ->component('machines/Index')
            ->has('machines.data', 1)
            ->where('machines.data.0.id', $mine->id));
});

it('hides deleted machines', function () {
    $user = User::factory()->create();
    Machine::factory()->for($user)->create()->delete();

    actingAs($user)->get('/machines')
        ->assertInertia(fn ($page) => $page->has('machines.data', 0));
});

it('shows how many reports a machine has and when the last one came in', function () {
    $user = User::factory()->create();
    $machine = Machine::factory()->for($user)->create();
    Report::factory()->for($machine)->create(['created_at' => '2026-10-01 10:00:00']);
    Report::factory()->for($machine)->create(['created_at' => '2026-10-05 10:00:00']);
    Report::factory()->for($machine)->create()->delete();

    actingAs($user)->get('/machines')
        ->assertInertia(fn ($page) => $page
            ->where('machines.data.0.reports_count', 2)
            ->where('machines.data.0.last_report_at', fn (string $at) => str_starts_with($at, '2026-10-05')));
});

it('does not count another machine’s reports', function () {
    $user = User::factory()->create();
    $machine = Machine::factory()->for($user)->create();
    Report::factory()->create();

    actingAs($user)->get('/machines')
        ->assertInertia(fn ($page) => $page->where('machines.data.0.id', $machine->id)->where('machines.data.0.reports_count', 0));
});

it('leads with the worst problem of the newest report', function () {
    $user = User::factory()->create();
    $machine = Machine::factory()->for($user)->create();
    Report::factory()->for($machine)->create([
        'created_at' => '2026-10-01 10:00:00',
        'flags' => ['Secure Boot is OFF.'],
    ]);
    Report::factory()->for($machine)->create([
        'created_at' => '2026-10-05 10:00:00',
        'flags' => ['17 programs start with Windows (slows boot).', 'Only about 5% of drive C: is free.'],
    ]);

    actingAs($user)->get('/machines')
        ->assertInertia(fn ($page) => $page
            ->where('machines.data.0.issue_count', 2)
            ->where('machines.data.0.top_issue.severity', 'high')
            ->where('machines.data.0.top_issue.headline', 'Drive C: is almost full, only 5% left')
            ->missing('machines.data.0.newest_report'));
});

it('has no top issue for a machine without a report', function () {
    $user = User::factory()->create();
    Machine::factory()->for($user)->create();

    actingAs($user)->get('/machines')
        ->assertInertia(fn ($page) => $page
            ->where('machines.data.0.issue_count', 0)
            ->where('machines.data.0.top_issue', null));
});

it('paginates at the configured page size', function () {
    config()->set('pagination.per_page', 5);

    $user = User::factory()->create();
    Machine::factory()->count(12)->for($user)->create();

    actingAs($user)->get('/machines')
        ->assertInertia(fn ($page) => $page
            ->has('machines.data', 5)
            ->where('machines.total', 12));
});

it('lists the machine imported most recently first', function () {
    $user = User::factory()->create();
    $old = Machine::factory()->for($user)->create(['created_at' => '2026-01-01 00:00:00']);
    $new = Machine::factory()->for($user)->create(['created_at' => '2026-06-01 00:00:00']);

    actingAs($user)->get('/machines')
        ->assertInertia(fn ($page) => $page
            ->where('machines.data.0.id', $new->id)
            ->where('machines.data.1.id', $old->id));
});
