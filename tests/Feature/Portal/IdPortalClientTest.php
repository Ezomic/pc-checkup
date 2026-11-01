<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Portal\IdPortalClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();

    config(['services.thijssensoftware' => [
        'base_url' => 'https://id.test',
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'slug' => 'web',
        'portal_cache_ttl' => 300,
    ]]);
});

function portalUser(): User
{
    return User::factory()->make(['email' => 'user@example.com']);
}

it('returns an empty list when not configured', function () {
    config(['services.thijssensoftware.client_id' => null]);

    expect(app(IdPortalClient::class)->appsFor(portalUser()))->toBe([]);
});

it('fetches apps and marks the current one', function () {
    Http::fake([
        'id.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
        'id.test/api/portal/apps' => Http::response(['applications' => [
            ['slug' => 'web', 'name' => 'Web', 'initials' => 'W', 'accent' => null, 'launch_url' => 'https://web.test'],
            ['slug' => 'billr', 'name' => 'Billr', 'initials' => 'B', 'accent' => '#fff', 'launch_url' => 'https://billr.test'],
        ]]),
    ]);

    $apps = app(IdPortalClient::class)->appsFor(portalUser());

    expect($apps)->toHaveCount(2)
        ->and($apps[0]['current'])->toBeTrue()
        ->and($apps[1]['current'])->toBeFalse();
});

it('serves apps from the cache without re-fetching', function () {
    Cache::put('portal-apps:'.sha1('user@example.com'), [
        ['slug' => 'billr', 'name' => 'Billr', 'initials' => 'B', 'accent' => null, 'launch_url' => 'https://billr.test'],
    ], 300);

    Http::fake();

    $apps = app(IdPortalClient::class)->appsFor(portalUser());

    expect($apps)->toHaveCount(1)->and($apps[0]['current'])->toBeFalse();
    Http::assertNothingSent();
});

it('reuses a cached client token', function () {
    Cache::put('portal-client-token', 'cached-tok', 600);
    Http::fake([
        'id.test/api/portal/apps' => Http::response(['applications' => []]),
    ]);

    app(IdPortalClient::class)->appsFor(portalUser());

    Http::assertSent(fn ($request) => $request->url() === 'https://id.test/api/portal/apps'
        && $request->hasHeader('Authorization', 'Bearer cached-tok'));
});

it('fails soft when the token endpoint fails', function () {
    Http::fake(['id.test/oauth/token' => Http::response('', 500)]);

    expect(app(IdPortalClient::class)->appsFor(portalUser()))->toBe([]);
});

it('fails soft when the token response has no access token', function () {
    Http::fake(['id.test/oauth/token' => Http::response(['token_type' => 'Bearer'])]);

    expect(app(IdPortalClient::class)->appsFor(portalUser()))->toBe([]);
});

it('fails soft when the portal endpoint fails', function () {
    Http::fake([
        'id.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
        'id.test/api/portal/apps' => Http::response('', 503),
    ]);

    expect(app(IdPortalClient::class)->appsFor(portalUser()))->toBe([]);
});

it('fails soft when the request throws', function () {
    Http::fake(fn () => throw new ConnectionException('down'));

    expect(app(IdPortalClient::class)->appsFor(portalUser()))->toBe([]);
});
