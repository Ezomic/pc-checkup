<?php

declare(strict_types=1);

use App\Actions\ApiTokens\CreateApiToken;
use App\Models\User;
use Illuminate\Foundation\Auth\User as FrameworkUser;
use Illuminate\Support\Facades\Exceptions;

/**
 * id-client only ends the web session when ID revokes a user's access to the app, so
 * the API tokens they had minted kept working for as long as they existed. WEB-32.
 *
 * @return array{User, string}
 */
function ssoUserWithToken(string $idpId): array
{
    $user = User::factory()->create();
    $user->forceFill(['idp_id' => $idpId])->save();

    return [$user, app(CreateApiToken::class)->handle($user, 'Script')];
}

it('deletes every API token of the user when ID revokes their access', function () {
    [$user, $plain] = ssoUserWithToken('idp-1');
    app(CreateApiToken::class)->handle($user, 'Laptop CLI');
    [$someoneElse] = ssoUserWithToken('idp-2');

    $this->withToken($plain)->getJson('/api/user')->assertOk();

    signedIdEvent('access.revoked', $user)->assertOk();

    app('auth')->forgetGuards();
    $this->withToken($plain)->getJson('/api/user')->assertUnauthorized();

    expect($user->tokens()->count())->toBe(0)
        ->and($someoneElse->tokens()->count())->toBe(1);
});

/**
 * ID sends a logout per session, so signing out on one machine must not kill a script's
 * token on another. Only a lost grant should.
 */
it('keeps the tokens on a plain ID logout', function () {
    [$user, $plain] = ssoUserWithToken('idp-1');

    signedIdEvent('logout', $user)->assertOk();

    $this->withToken($plain)->getJson('/api/user')->assertOk();
});

/**
 * A 500 alone proves nothing: without the guard, calling tokens() on the framework user
 * throws a BadMethodCallException, which is a 500 too and even a LogicException. So the
 * test pins the listener's own exception by exact class and message. WEB-37.
 */
it('fails the delivery, so ID retries it, when id-client is not pointed at the app user model', function () {
    Exceptions::fake();
    [$user] = ssoUserWithToken('idp-1');
    config(['id-client.user_model' => FrameworkUser::class]);

    signedIdEvent('access.revoked', $user)->assertServerError();

    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (LogicException $e): bool => $e->getMessage() === 'id-client.user_model is not '.User::class.'.');
    expect($user->tokens()->count())->toBe(1);
});
