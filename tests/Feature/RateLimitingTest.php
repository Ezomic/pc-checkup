<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\RateLimiter;

function requestWithSession(array $session = [], array $query = []): Request
{
    $request = Request::create('/', 'POST', $query);
    $store = new Store('testing', new ArraySessionHandler(120));
    $store->put($session);
    $request->setLaravelSession($store);

    return $request;
}

it('throttles two factor attempts by the login id in session', function () {
    $limiter = RateLimiter::limiter('two-factor');

    expect($limiter(requestWithSession(['login.id' => 42])))
        ->toBeInstanceOf(Limit::class);
});

it('throttles login attempts by username and ip', function () {
    $limiter = RateLimiter::limiter('login');

    expect($limiter(requestWithSession([], ['email' => 'User@Example.com'])))
        ->toBeInstanceOf(Limit::class);
});

it('throttles passkey attempts by credential id', function () {
    $limiter = RateLimiter::limiter('passkeys');

    expect($limiter(requestWithSession([], ['credential' => ['id' => 'cred_1']])))
        ->toBeInstanceOf(Limit::class);
});

it('falls back to the session id when no passkey credential is present', function () {
    $limiter = RateLimiter::limiter('passkeys');

    expect($limiter(requestWithSession()))
        ->toBeInstanceOf(Limit::class);
});

/**
 * auth:sanctum falls back to the web guard, so a browser session reaches the API with no
 * token of its own, and an app may add a route that needs no sign-in at all. Neither may
 * share one bucket with every other caller.
 */
it('throttles the API per token, else per user, else per address', function () {
    $limiter = RateLimiter::limiter('api');
    $user = User::factory()->create();
    $token = $user->createToken('Script')->accessToken;
    $request = Request::create('/api/user', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9']);

    expect($limiter($request)->key)->toBe('ip:203.0.113.9');

    $request->setUserResolver(fn (): User => $user);

    expect($limiter($request)->key)->toBe("user:{$user->id}");

    $user->withAccessToken($token);

    expect($limiter($request)->key)->toBe("token:{$token->id}");
});
