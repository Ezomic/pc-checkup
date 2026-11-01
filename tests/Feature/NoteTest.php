<?php

declare(strict_types=1);

use App\Models\Note;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

it('requires authentication', function () {
    $this->get('/notes')->assertRedirect('/login');
});

it('lists only the current user’s notes', function () {
    $user = User::factory()->create();
    $mine = Note::factory()->for($user)->create(['title' => 'Mine']);
    Note::factory()->create(['title' => 'Theirs']);

    actingAs($user)->get('/notes')
        ->assertInertia(fn ($page) => $page
            ->component('notes/Index')
            ->has('notes.data', 1)
            ->where('notes.data.0.id', $mine->id));
});

it('paginates the list at the configured page size', function () {
    config()->set('pagination.per_page', 5);

    $user = User::factory()->create();
    Note::factory()->count(12)->for($user)->create();

    actingAs($user)->get('/notes')
        ->assertInertia(fn ($page) => $page
            ->has('notes.data', 5)
            ->where('notes.total', 12)
            ->where('notes.last_page', 3)
            ->where('notes.current_page', 1));
});

it('serves the requested page', function () {
    config()->set('pagination.per_page', 5);

    $user = User::factory()->create();
    Note::factory()->count(12)->for($user)->create();

    actingAs($user)->get('/notes?page=3')
        ->assertInertia(fn ($page) => $page
            ->has('notes.data', 2)
            ->where('notes.current_page', 3));
});

// withQueryString(), so a filter or sort on the page is not silently dropped
// the moment someone clicks page 2.
it('keeps the rest of the query string on the paginator links', function () {
    config()->set('pagination.per_page', 5);

    $user = User::factory()->create();
    Note::factory()->count(8)->for($user)->create();

    actingAs($user)->get('/notes?sort=title')
        ->assertInertia(fn ($page) => $page
            ->where('notes.next_page_url', fn (string $url) => str_contains($url, 'sort=title')));
});

it('reports a single page when there is little to show', function () {
    $user = User::factory()->create();
    Note::factory()->count(2)->for($user)->create();

    actingAs($user)->get('/notes')
        ->assertInertia(fn ($page) => $page
            ->where('notes.last_page', 1)
            ->where('notes.total', 2));
});

it('renders the create page', function () {
    actingAs(User::factory()->create())->get('/notes/create')
        ->assertInertia(fn ($page) => $page->component('notes/Create'));
});

it('stores a note for the current user', function () {
    $user = User::factory()->create();

    actingAs($user)->post('/notes', ['title' => 'Buy milk', 'body' => 'Two litres'])
        ->assertRedirect('/notes');

    assertDatabaseHas('notes', [
        'user_id' => $user->id,
        'title' => 'Buy milk',
        'body' => 'Two litres',
    ]);
});

it('validates when storing', function () {
    actingAs(User::factory()->create())->post('/notes', ['title' => '', 'body' => ''])
        ->assertSessionHasErrors(['title', 'body']);

    expect(Note::count())->toBe(0);
});

it('lets an owner edit their note', function () {
    $user = User::factory()->create();
    $note = Note::factory()->for($user)->create();

    actingAs($user)->get("/notes/{$note->id}/edit")
        ->assertInertia(fn ($page) => $page->component('notes/Edit')->where('note.id', $note->id));
});

it('forbids editing another user’s note', function () {
    $note = Note::factory()->create();

    actingAs(User::factory()->create())->get("/notes/{$note->id}/edit")->assertForbidden();
});

it('updates an owned note', function () {
    $user = User::factory()->create();
    $note = Note::factory()->for($user)->create();

    actingAs($user)->put("/notes/{$note->id}", ['title' => 'Updated', 'body' => 'New body'])
        ->assertRedirect('/notes');

    assertDatabaseHas('notes', ['id' => $note->id, 'title' => 'Updated']);
});

it('forbids updating another user’s note', function () {
    $note = Note::factory()->create(['title' => 'Original']);

    actingAs(User::factory()->create())->put("/notes/{$note->id}", ['title' => 'Hacked', 'body' => 'x'])
        ->assertForbidden();

    assertDatabaseHas('notes', ['id' => $note->id, 'title' => 'Original']);
});

it('deletes an owned note', function () {
    $user = User::factory()->create();
    $note = Note::factory()->for($user)->create();

    actingAs($user)->delete("/notes/{$note->id}")->assertRedirect('/notes');

    assertDatabaseMissing('notes', ['id' => $note->id]);
});

it('forbids deleting another user’s note', function () {
    $note = Note::factory()->create();

    actingAs(User::factory()->create())->delete("/notes/{$note->id}")->assertForbidden();

    assertDatabaseHas('notes', ['id' => $note->id]);
});
