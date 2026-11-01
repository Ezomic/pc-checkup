<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\get;

/**
 * id-client signs every workflow-mode user in with remember: true, and the user it
 * provisions has a null password. Laravel 13.32 refuses a remember cookie whenever
 * getAuthPassword() is not a string, so the cookie was set on every sign-in and
 * honoured on none, and a user lasted only as long as the idle session. Honouring it
 * is only safe on id-client 0.3.1 or later, which is what makes a sign-out at ID
 * reach a session the cookie restored. THI-368.
 */
beforeEach(function () {
    config(['workflow.enabled' => true]);
});

/**
 * The next request from a session that is still live. A fresh guard, as on a real
 * request, so the user is read back with the stamp ID's sign-out left on the row.
 *
 * @return TestResponse<Response>
 */
function nextRequestInTheSameSession(): TestResponse
{
    Auth::forgetGuards();

    return get(route('dashboard'));
}

it('restores the session from the remember cookie alone', function () {
    [$user, $recaller] = signInThroughIdRemembered();

    returnWithOnlyTheRememberCookie($recaller)->assertOk();

    assertAuthenticatedAs($user);
    expect(Auth::guard()->viaRemember())->toBeTrue();
});

it('refuses the remember cookie once ID signs the user out', function () {
    [$user, $recaller] = signInThroughIdRemembered();
    returnWithOnlyTheRememberCookie($recaller)->assertOk();

    signedIdEvent('logout', $user)->assertOk()->assertJson(['status' => 'ok']);

    returnWithOnlyTheRememberCookie($recaller)->assertRedirect(route('login'));
    assertGuest();
});

it('ends a session the remember cookie restored once ID signs the user out', function () {
    [$user, $recaller] = signInThroughIdRemembered();
    returnWithOnlyTheRememberCookie($recaller)->assertOk();

    signedIdEvent('logout', $user)->assertOk();

    nextRequestInTheSameSession()->assertRedirect('/');
    assertGuest();
});

/**
 * The template does not cast sso_logged_out_at, and id-client 0.3.0 called
 * getTimestamp() on it, so the stamp a sign-out leaves on the row turned every later
 * request from that user into a 500 rather than a sign-out.
 */
it('signs a session from ID out on its next request instead of failing', function () {
    [$user] = signInThroughIdRemembered();

    signedIdEvent('logout', $user)->assertOk();

    nextRequestInTheSameSession()->assertRedirect('/');
    assertGuest();
});
