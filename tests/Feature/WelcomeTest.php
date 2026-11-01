<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

it('shows the landing page to a guest', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Welcome'));
});

it('sends a signed-in visitor to the app instead of the front door', function () {
    actingAs(User::factory()->create(['email_verified_at' => now()]));

    $this->get('/')->assertRedirect(route('dashboard'));
});

// The whole reason this ticket exists: the stock page advertised Laracasts and
// Laravel Cloud from the public root of every app, and pulled a stylesheet from
// a third-party host on every visit.
it('carries no third-party marketing links or external assets', function () {
    $markup = file_get_contents(resource_path('js/pages/Welcome.vue'));

    expect($markup)
        ->not->toContain('laravel.com')
        ->not->toContain('laracasts.com')
        ->not->toContain('cloud.laravel.com')
        ->not->toContain('rsms.me')
        ->not->toContain('https://');
});
