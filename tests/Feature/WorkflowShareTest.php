<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

it('shares workflow disabled and no portal apps in base mode', function () {
    // Set rather than assumed. This asserted false without ever setting it, so
    // it only passed because the ambient config happened to say so, and it
    // failed on the first clone that turned workflow mode on.
    config(['workflow.enabled' => false]);

    get('/')->assertInertia(fn (Assert $page) => $page
        ->where('workflow.enabled', false)
        ->where('portalApps', []));
});

it('does not resolve portal apps for a guest in workflow mode', function () {
    config(['workflow.enabled' => true]);

    get('/')->assertInertia(fn (Assert $page) => $page
        ->where('workflow.enabled', true)
        ->where('portalApps', []));
});

it('shares portal apps for an authenticated user in workflow mode', function () {
    config([
        'workflow.enabled' => true,
        'services.thijssensoftware' => [
            'base_url' => 'https://id.test',
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'slug' => 'web',
            'portal_cache_ttl' => 300,
        ],
    ]);

    Http::fake([
        'id.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
        'id.test/api/portal/apps' => Http::response(['applications' => [
            ['slug' => 'billr', 'name' => 'Billr', 'initials' => 'B', 'accent' => null, 'launch_url' => 'https://billr.test'],
        ]]),
    ]);

    $user = User::factory()->create(['email_verified_at' => now()]);

    actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('workflow.enabled', true)
        ->has('portalApps', 1)
        ->where('portalApps.0.slug', 'billr'));
});
