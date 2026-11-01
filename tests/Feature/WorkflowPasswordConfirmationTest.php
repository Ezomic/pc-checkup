<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Contracts\Factory as Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Symfony\Component\HttpFoundation\Response;
use Thijssensoftware\IdClient\Http\Middleware\EnsureSsoSessionIsActive;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertAuthenticated;
use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\get;
use function Pest\Laravel\mock;
use function Pest\Laravel\post;
use function Pest\Laravel\travel;
use function Pest\Laravel\withCookie;

/**
 * id-client provisions workflow-mode users with a null password, so the confirmation in
 * front of the token pages is a round trip through ID instead. Before WEB-28 nothing
 * handled it and no SSO user could ever create an API token.
 */
function ssoUser(int $signedInSecondsAgo = 60): User
{
    config(['workflow.enabled' => true]);

    $user = User::create(['name' => 'SSO User', 'email' => 'sso@example.test']);
    $user->forceFill(['email_verified_at' => now()])->save();

    actingAs($user);
    session()->put(EnsureSsoSessionIsActive::AUTHENTICATED_AT, now()->getTimestamp() - $signedInSecondsAgo);

    return $user;
}

/**
 * @return TestResponse<Response>
 */
function idSignsInAgain(): TestResponse
{
    $idUser = (new SocialiteUser)->map(['id' => 'idp-1', 'email' => 'sso@example.test', 'name' => 'SSO User']);
    $idUser->setToken('id-access-token');

    mock(Socialite::class)->shouldReceive('driver->user')->andReturn($idUser);

    return get(route('sso.callback'))->assertRedirect();
}

it('asks a user without a password to confirm before the token pages', function () {
    ssoUser();

    get(route('api-tokens.index'))->assertRedirect(route('password.confirm'));
});

it('sends the user through ID even right after signing in', function () {
    ssoUser(signedInSecondsAgo: 5);

    get(route('password.confirm'))
        ->assertRedirect(route('sso.redirect'))
        ->assertSessionMissing('auth.password_confirmed_at');
});

it('answers an Inertia visit with a full-page location, since ID is another origin', function () {
    ssoUser();
    $version = app(HandleInertiaRequests::class)->version(Request::create('/'));

    get(route('password.confirm'), ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', route('sso.redirect'));
});

it('reaches the token page once ID has signed the user in again', function () {
    ssoUser();

    get(route('api-tokens.index'))->assertRedirect(route('password.confirm'));
    get(route('password.confirm'))->assertRedirect(route('sso.redirect'));

    travel(5)->seconds();
    idSignsInAgain();

    get(route('api-tokens.index'))->assertRedirect(route('password.confirm'));
    get(route('password.confirm'))
        ->assertRedirect(route('api-tokens.index'))
        ->assertSessionHas('auth.password_confirmed_at');
    get(route('api-tokens.index'))->assertOk();
});

it('does not count a sign-in from before the confirmation was asked for', function () {
    ssoUser();

    get(route('password.confirm'))->assertRedirect(route('sso.redirect'));
    travel(5)->seconds();

    get(route('password.confirm'))
        ->assertRedirect(route('sso.redirect'))
        ->assertSessionMissing('auth.password_confirmed_at');
});

/**
 * A signed-out user who deep-links to the token page signs in, lands back on it and is
 * asked to confirm all within one second. Timestamps alone cannot tell that sign-in from
 * a fresh one, so the sign-in current at the request is remembered and never counts.
 */
it('does not count the sign-in current at the request, even in the same second', function () {
    ssoUser(signedInSecondsAgo: 0);

    get(route('password.confirm'))->assertRedirect(route('sso.redirect'));
    travel(1)->hour();

    get(route('password.confirm'))
        ->assertRedirect(route('sso.redirect'))
        ->assertSessionMissing('auth.password_confirmed_at');
});

/**
 * id-client stamps a session the remember cookie restores as though it had just signed in
 * through ID. The cookie lasts 400 days and carries no ID session, so a restore must not
 * stand in for the round trip, neither on the request that restores nor later in the
 * session it restored. THI-368.
 */
it('does not take a remember-me restore for a sign-in through ID', function () {
    config(['workflow.enabled' => true]);
    [, $recaller] = signInThroughIdRemembered();
    travel(2)->days();

    returnWithOnlyTheRememberCookie($recaller, route('password.confirm'))->assertRedirect(route('sso.redirect'));
    assertAuthenticated();

    travel(5)->seconds();
    Auth::forgetGuards();

    get(route('password.confirm'))
        ->assertRedirect(route('sso.redirect'))
        ->assertSessionMissing('auth.password_confirmed_at');
    post(route('api-tokens.store'), ['name' => 'Laptop CLI'])->assertRedirect(route('password.confirm'));
    assertDatabaseCount('personal_access_tokens', 0);
});

/**
 * The round trip a remembered browser makes: restored on the token page, sent through ID,
 * and back on the callback in the restored session with the remember cookie still attached.
 * Each request gets a fresh guard, as a real one does.
 */
it('confirms once a remembered browser has been back through ID', function () {
    config(['workflow.enabled' => true]);
    [, $recaller] = signInThroughIdRemembered();
    travel(2)->days();

    returnWithOnlyTheRememberCookie($recaller, route('api-tokens.index'))->assertRedirect(route('password.confirm'));
    Auth::forgetGuards();
    get(route('password.confirm'))->assertRedirect(route('sso.redirect'));

    travel(5)->seconds();
    Auth::forgetGuards();
    withCookie(Auth::guard()->getRecallerName(), $recaller)
        ->get(route('sso.callback'))
        ->assertRedirect(route('password.confirm'));

    Auth::forgetGuards();
    get(route('api-tokens.index'))->assertRedirect(route('password.confirm'));
    Auth::forgetGuards();
    get(route('password.confirm'))
        ->assertRedirect(route('api-tokens.index'))
        ->assertSessionHas('auth.password_confirmed_at');
    Auth::forgetGuards();
    get(route('api-tokens.index'))->assertOk();
});

/**
 * id-client's callback lands on the intended URL, which used to be the token page, where
 * RequirePassword sent the user to the confirmation once more before it completed. The
 * trip through ID now comes back to the confirmation itself, which completes and hands
 * over to the page that asked. WEB-36.
 */
it('comes back through the confirmation, which lands on the token page confirmed', function () {
    ssoUser();

    get(route('api-tokens.index'))->assertRedirect(route('password.confirm'));
    get(route('password.confirm'))->assertRedirect(route('sso.redirect'));

    travel(5)->seconds();
    idSignsInAgain()->assertRedirect(route('password.confirm'));

    get(route('password.confirm'))
        ->assertRedirect(route('api-tokens.index'))
        ->assertSessionHas('auth.password_confirmed_at');
    get(route('api-tokens.index'))->assertOk();
});

/**
 * Leaving ID halfway and opening the confirmation again must not make the confirmation
 * its own destination, or completing it would start another trip through ID.
 */
it('still returns to the page that asked after a trip through ID was abandoned', function () {
    ssoUser();

    get(route('api-tokens.index'))->assertRedirect(route('password.confirm'));
    get(route('password.confirm'))->assertRedirect(route('sso.redirect'));
    get(route('password.confirm'))->assertRedirect(route('sso.redirect'));

    travel(5)->seconds();
    idSignsInAgain()->assertRedirect(route('password.confirm'));

    get(route('password.confirm'))->assertRedirect(route('api-tokens.index'));
});

/**
 * A Delete or a token form is not a page to return to, so RequirePassword takes the
 * Referer instead. That header is the client's to write, and it only counts when it
 * names a page of this app that answers a GET; anything else falls back to the
 * dashboard rather than becoming an open redirect.
 */
it('falls back to the dashboard when the page that asked is not one of ours', function (string $referer) {
    ssoUser();

    $this->from($referer)->post(route('api-tokens.store'), ['name' => 'Laptop CLI'])
        ->assertRedirect(route('password.confirm'));
    get(route('password.confirm'))->assertRedirect(route('sso.redirect'));

    travel(5)->seconds();
    idSignsInAgain()->assertRedirect(route('password.confirm'));

    get(route('password.confirm'))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('auth.password_confirmed_at');
})->with([
    'another origin' => fn () => 'https://evil.example/settings/api-tokens',
    'a lookalike host' => fn () => url('/').'.evil.example/settings/api-tokens',
    'a host hidden behind userinfo' => fn () => url('/').'@evil.example/settings/api-tokens',
    'a path this app takes no GET on' => fn () => url('settings/password'),
    'a path this app does not have' => fn () => url('no-such-page'),
    'the confirmation itself' => fn () => route('password.confirm'),
]);
