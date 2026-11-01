<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Contracts\Factory as Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

use function Pest\Laravel\call;
use function Pest\Laravel\get;
use function Pest\Laravel\mock;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * A back-channel event from ID, signed the way id-client's LogoutController checks it.
 *
 * @return TestResponse<Response>
 */
function signedIdEvent(string $event, User $user): TestResponse
{
    config(['id-client.logout_secret' => 'test-logout-secret']);

    $body = (string) json_encode(['event' => $event, 'sub' => $user->getAttribute('idp_id'), 'issued_at' => now()->getTimestamp()]);

    return call('POST', route('sso.logout'), server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_ID_SIGNATURE' => hash_hmac('sha256', $body, 'test-logout-secret'),
    ], content: $body);
}

/**
 * Signs in through a faked ID and hands back the remember cookie exactly as the browser
 * received it.
 *
 * @return array{User, string}
 */
function signInThroughIdRemembered(): array
{
    $idUser = (new SocialiteUser)->map(['id' => 'idp-1', 'email' => 'sso@example.test', 'name' => 'SSO User']);
    $idUser->setToken('id-access-token');

    mock(Socialite::class)->shouldReceive('driver->user')->andReturn($idUser);

    $recaller = get(route('sso.callback'))->assertRedirect()->getCookie(Auth::guard()->getRecallerName());

    expect($recaller)->not->toBeNull();

    return [User::query()->where('idp_id', 'idp-1')->sole(), (string) $recaller?->getValue()];
}

/**
 * A browser whose session has expired, so the remember cookie is all it still carries.
 *
 * @return TestResponse<Response>
 */
function returnWithOnlyTheRememberCookie(string $recaller, ?string $url = null): TestResponse
{
    test()->flushSession();
    Auth::forgetGuards();

    return test()->withCookie(Auth::guard()->getRecallerName(), $recaller)->get($url ?? route('dashboard'));
}
