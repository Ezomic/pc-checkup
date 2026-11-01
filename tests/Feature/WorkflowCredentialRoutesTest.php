<?php

declare(strict_types=1);

use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * The reason this matters: id-client provisions a local user with a null
 * password on first SSO sign-in. A reset link would let that user mint one and
 * sign in locally from then on, in a session established without ID, which
 * outlives revoking their grant and ignores back-channel logout. See WEB-23.
 */
it('blocks the local credential routes in workflow mode', function (string $method, string $route) {
    config(['workflow.enabled' => true]);

    $response = $method === 'get' ? get(route($route, ['token' => 'x'])) : post(route($route));

    $response->assertNotFound();
})->with([
    ['get', 'password.request'],
    ['post', 'password.email'],
    ['get', 'password.reset'],
    ['post', 'password.update'],
    ['post', 'login.store'],
    ['get', 'passkey.login-options'],
    ['post', 'passkey.login'],
    ['get', 'two-factor.login'],
    ['post', 'two-factor.login.store'],
    ['get', 'well-known.passkeys'],
]);

/**
 * The rest need a signed-in user whose password counts as confirmed, which is what
 * confirming through ID gives a workflow user (WEB-28). A passkey enrolled from there
 * would sign them in without ID, and a password or two-factor secret set there would be
 * a second identity the app keeps next to ID's, so all of it stays out of reach.
 */
it('blocks managing local credentials in workflow mode', function (string $method, string $route, array $parameters) {
    config(['workflow.enabled' => true]);

    actingAs(User::factory()->create());
    session()->put('auth.password_confirmed_at', time());

    $this->call($method, route($route, $parameters))->assertNotFound();
})->with([
    ['POST', 'password.confirm.store', []],
    ['GET', 'passkey.registration-options', []],
    ['POST', 'passkey.store', []],
    ['GET', 'passkey.confirm-options', []],
    ['POST', 'passkey.confirm', []],
    ['POST', 'two-factor.enable', []],
    ['DELETE', 'two-factor.disable', []],
    ['POST', 'two-factor.confirm', []],
    ['GET', 'two-factor.qr-code', []],
    ['GET', 'two-factor.secret-key', []],
    ['GET', 'two-factor.recovery-codes', []],
    ['POST', 'two-factor.regenerate-recovery-codes', []],
    ['GET', 'security.edit', []],
    ['PUT', 'user-password.update', []],
]);

it('leaves the local credential routes alone for a standalone app', function () {
    config(['workflow.enabled' => false]);

    get(route('password.request'))->assertOk();
});

/**
 * The login page itself has to stay reachable in workflow mode: it is what
 * renders the "Sign in with Thijssensoftware" button.
 */
it('keeps the login page reachable in workflow mode', function () {
    config(['workflow.enabled' => true]);

    get(route('login'))->assertOk();
});
