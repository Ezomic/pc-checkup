<?php

declare(strict_types=1);

use App\Actions\ApiTokens\CreateApiToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Contracts\Factory as Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Symfony\Component\HttpFoundation\Response;

/*
 * WEB-38. nginx already drops names it does not serve; this is the app refusing
 * them too, so a clone does not depend on that setup to keep a forged Host out
 * of the links, redirects and same-origin checks it builds from the request.
 */

const APP_HOST = 'app.example.com';

beforeEach(function () {
    config(['app.url' => 'https://'.APP_HOST]);

    // Laravel stands the host check down under unit tests and in local, so
    // run these requests the way production sees them.
    app()->detectEnvironment(fn (): string => 'production');
});

afterEach(function () {
    // The trusted list is static on Symfony's Request and would otherwise
    // follow every later test in this process.
    Request::setTrustedHosts([]);
});

/**
 * A back-channel event from ID, signed the way id-client's LogoutController checks it,
 * sent to the given host rather than to wherever route() points.
 *
 * @return TestResponse<Response>
 */
function signedIdEventTo(string $host, User $user): TestResponse
{
    config(['id-client.logout_secret' => 'test-logout-secret']);

    $body = (string) json_encode(['event' => 'access.revoked', 'sub' => $user->getAttribute('idp_id'), 'issued_at' => now()->getTimestamp()]);

    return test()->call('POST', "https://{$host}/auth/sso/logout", server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_ID_SIGNATURE' => hash_hmac('sha256', $body, 'test-logout-secret'),
    ], content: $body);
}

it('serves requests on its own host', function (string $path) {
    $this->get('https://'.APP_HOST.$path)->assertOk();
})->with(['/', '/login', '/up', '/health']);

it('refuses a request for another host', function (string $host) {
    $this->get("https://{$host}/login")->assertBadRequest();
})->with([
    'another name' => 'evil.example',
    'a subdomain' => 'www.'.APP_HOST,
    'its own name as a prefix' => APP_HOST.'.evil.example',
    'a dot read as any character' => 'appxexample.com',
]);

it('refuses an API request for another host', function () {
    $plain = app(CreateApiToken::class)->handle(User::factory()->create(), 'Script');

    $this->withToken($plain)->getJson('https://'.APP_HOST.'/api/user')->assertOk();
    $this->withToken($plain)->getJson('https://evil.example/api/user')->assertBadRequest();
});

describe('in workflow mode', function () {
    beforeEach(function () {
        config(['workflow.enabled' => true]);
    });

    it('signs a user in through ID on its own host only', function () {
        $idUser = (new SocialiteUser)->map(['id' => 'idp-1', 'email' => 'sso@example.test', 'name' => 'SSO User']);
        $idUser->setToken('id-access-token');
        $this->mock(Socialite::class)->shouldReceive('driver->user')->andReturn($idUser);

        $this->get('https://evil.example/auth/sso/callback')->assertBadRequest();
        $this->assertGuest();

        $this->get('https://'.APP_HOST.'/auth/sso/callback')->assertRedirect();
        $this->assertAuthenticated();
    });

    it('accepts the back-channel logout from ID on its own host only', function () {
        $user = User::factory()->create();
        $user->forceFill(['idp_id' => 'idp-1'])->save();
        app(CreateApiToken::class)->handle($user, 'Script');

        signedIdEventTo('evil.example', $user)->assertBadRequest();
        expect($user->tokens()->count())->toBe(1);

        signedIdEventTo(APP_HOST, $user)->assertOk();
        expect($user->tokens()->count())->toBe(0);
    });
});
