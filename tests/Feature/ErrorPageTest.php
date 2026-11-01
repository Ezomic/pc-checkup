<?php

declare(strict_types=1);

use App\Models\Note;
use App\Models\User;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

it('renders the branded page for a missing route', function (): void {
    $this->get('/no-such-page')
        ->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Error')
            ->where('status', 404));
});

it('renders the branded page when a policy denies the request', function (): void {
    $note = Note::factory()->for(User::factory())->create();

    $this->actingAs(User::factory()->create())
        ->get(route('notes.edit', $note))
        ->assertForbidden()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Error')
            ->where('status', 403));
});

it('renders the branded page when the session token has expired', function (): void {
    Route::middleware('web')->get('/throws-419', function (): void {
        throw new TokenMismatchException;
    });

    $this->get('/throws-419')
        ->assertStatus(419)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Error')
            ->where('status', 419));
});

it('renders the branded page when the application throws', function (): void {
    Route::middleware('web')->get('/throws-500', function (): void {
        throw new RuntimeException('Something broke.');
    });

    $this->get('/throws-500')
        ->assertStatus(500)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Error')
            ->where('status', 500));
});

it('leaves a JSON caller with JSON rather than an Inertia page', function (): void {
    $this->getJson('/no-such-page')
        ->assertNotFound()
        ->assertHeader('content-type', 'application/json');
});

it('leaves statuses outside the branded set alone', function (): void {
    Route::middleware('web')->get('/throws-418', function (): void {
        abort(418);
    });

    $this->get('/throws-418')->assertStatus(418);

    expect($this->get('/throws-418')->headers->get('x-inertia'))->toBeNull();
});

it('does not intercept a successful response', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Welcome'));
});
