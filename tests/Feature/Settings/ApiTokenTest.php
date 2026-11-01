<?php

declare(strict_types=1);

use App\Actions\ApiTokens\CreateApiToken;
use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

/**
 * The pages sit behind RequirePassword, so every test confirms the password
 * first. That middleware is itself asserted separately below.
 */
function tokenUser(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);

    actingAs($user);
    session()->put('auth.password_confirmed_at', time());

    return $user;
}

it('requires authentication', function () {
    $this->get('/settings/api-tokens')->assertRedirect('/login');
});

it('requires a recently confirmed password', function () {
    actingAs(User::factory()->create(['email_verified_at' => now()]));

    $this->get('/settings/api-tokens')->assertRedirect(route('password.confirm'));
});

it('lists the acting user’s tokens and nobody else’s', function () {
    $user = tokenUser();
    $user->createToken('Mine');
    User::factory()->create()->createToken('Theirs');

    $this->get('/settings/api-tokens')
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Tokens')
            ->has('tokens', 1)
            ->where('tokens.0.name', 'Mine'));
});

it('creates a token and reveals the plaintext exactly once', function () {
    tokenUser();

    $this->post('/settings/api-tokens', ['name' => 'Laptop CLI'])
        ->assertRedirect('/settings/api-tokens');

    assertDatabaseHas('personal_access_tokens', ['name' => 'Laptop CLI']);

    // First render: the plaintext is there to be copied.
    $plain = null;

    $this->get('/settings/api-tokens')
        ->assertInertia(function (Assert $page) use (&$plain) {
            $plain = $page->toArray()['props']['createdToken'];

            return $page->where('createdToken', fn ($t) => is_string($t) && $t !== '');
        });

    expect($plain)->toBeString()->not->toBeEmpty();

    // Second render: gone, because it lived in a session flash.
    $this->get('/settings/api-tokens')
        ->assertInertia(fn (Assert $page) => $page->where('createdToken', null));
});

it('never stores the plaintext token', function () {
    $user = tokenUser();

    $this->post('/settings/api-tokens', ['name' => 'Laptop CLI']);

    $plain = (string) session('createdToken');
    $stored = (string) $user->tokens()->sole()->token;

    expect($plain)->not->toBeEmpty()
        ->and($stored)->not->toBe($plain)
        ->and(str_contains($plain, $stored))->toBeFalse();
});

it('validates the token name', function () {
    tokenUser();

    $this->post('/settings/api-tokens', ['name' => ''])->assertSessionHasErrors('name');
    $this->post('/settings/api-tokens', ['name' => str_repeat('a', 256)])->assertSessionHasErrors('name');
});

it('revokes a token the user owns', function () {
    $user = tokenUser();
    $token = $user->createToken('Laptop CLI')->accessToken;

    $this->delete("/settings/api-tokens/{$token->getKey()}")
        ->assertRedirect('/settings/api-tokens');

    assertDatabaseMissing('personal_access_tokens', ['id' => $token->getKey()]);
});

// The whole point of scoping the delete to the user's own tokens.
it('cannot revoke another user’s token', function () {
    tokenUser();
    $theirs = User::factory()->create()->createToken('Theirs')->accessToken;

    $this->delete("/settings/api-tokens/{$theirs->getKey()}")->assertNotFound();

    assertDatabaseHas('personal_access_tokens', ['id' => $theirs->getKey()]);
});

it('404s on a token that does not exist', function () {
    tokenUser();

    $this->delete('/settings/api-tokens/99999')->assertNotFound();
});

/**
 * No actingAs in the API tests, and the session is flushed before the call.
 * auth:sanctum falls back to the web guard, so a lingering session would
 * authenticate the request and the assertion would pass without the token
 * doing anything at all.
 */
it('authenticates a real API call with the token it minted', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $plain = $user->createToken('Laptop CLI')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$plain}")
        ->getJson('/api/user')
        ->assertOk()
        ->assertJson(['id' => $user->id, 'email' => $user->email]);
});

it('rejects a token that was never issued', function () {
    User::factory()->create();

    $this->withHeader('Authorization', 'Bearer 1|totallyMadeUpTokenValue')
        ->getJson('/api/user')
        ->assertUnauthorized();
});

it('rejects the API call once the token is revoked', function () {
    $user = tokenUser();
    $created = $user->createToken('Laptop CLI');

    $this->delete("/settings/api-tokens/{$created->accessToken->getKey()}")
        ->assertRedirect('/settings/api-tokens');

    $this->flushSession();
    $this->app['auth']->forgetGuards();

    $this->withHeader('Authorization', "Bearer {$created->plainTextToken}")
        ->getJson('/api/user')
        ->assertUnauthorized();
});

it('rejects the API without a token at all', function () {
    $this->getJson('/api/user')->assertUnauthorized();
});

/**
 * ID does not yet send access.revoked for every way a user can lose access (ID-89), so
 * an app cannot count on hearing about it, and expiry is what bounds how long a token
 * can outlive a revoked grant. WEB-32.
 */
it('expires a token 90 days after it is minted', function () {
    $this->freezeSecond();
    $user = tokenUser();

    $this->post('/settings/api-tokens', ['name' => 'Laptop CLI'])->assertRedirect('/settings/api-tokens');

    expect($user->tokens()->sole()->expires_at?->getTimestamp())->toBe(now()->addDays(90)->getTimestamp());
});

it('refuses a token once it has expired', function () {
    $plain = app(CreateApiToken::class)->handle(User::factory()->create(), 'Laptop CLI');

    $this->travel(90)->days();
    $this->travel(-1)->minutes();
    $this->withToken($plain)->getJson('/api/user')->assertOk();

    $this->app['auth']->forgetGuards();
    $this->travel(2)->minutes();
    $this->withToken($plain)->getJson('/api/user')->assertUnauthorized();
});

it('refuses a token minted without an expiry once it is 90 days old', function () {
    $plain = User::factory()->create()->createToken('Direct')->plainTextToken;

    $this->travel(89)->days();
    $this->withToken($plain)->getJson('/api/user')->assertOk();

    $this->app['auth']->forgetGuards();
    $this->travel(2)->days();
    $this->withToken($plain)->getJson('/api/user')->assertUnauthorized();
});

it('shows when each token expires, and that it has', function () {
    $this->freezeSecond();
    $user = tokenUser();
    app(CreateApiToken::class)->handle($user, 'Old');
    $this->travel(30)->days();
    app(CreateApiToken::class)->handle($user, 'New');
    $this->travel(61)->days();
    session()->put('auth.password_confirmed_at', now()->getTimestamp());

    $this->get('/settings/api-tokens')
        ->assertInertia(fn (Assert $page) => $page
            ->where('tokens.0.name', 'New')
            ->where('tokens.0.expires_at_diff', '4 weeks from now')
            ->where('tokens.0.expired', false)
            ->where('tokens.1.name', 'Old')
            ->where('tokens.1.expires_at_diff', '1 day ago')
            ->where('tokens.1.expired', true));
});

// Secret scanners find a leaked token by its prefix, and the app's name tells whose it is.
it('prefixes the plaintext with the app name', function () {
    $plain = app(CreateApiToken::class)->handle(User::factory()->create(), 'Laptop CLI');
    [, $secret] = explode('|', $plain, 2);

    expect(config('sanctum.token_prefix'))->toBe(Str::slug((string) config('app.name'), '_').'_')
        ->and($secret)->toStartWith((string) config('sanctum.token_prefix'));
});

it('throttles each token on its own', function () {
    $user = User::factory()->create();
    $script = $user->createToken('Script')->plainTextToken;
    $laptop = $user->createToken('Laptop CLI')->plainTextToken;

    foreach (range(1, 60) as $ignored) {
        $this->withToken($script)->getJson('/api/user')->assertOk();
    }

    $this->withToken($script)->getJson('/api/user')->assertTooManyRequests();

    $this->app['auth']->forgetGuards();
    $this->withToken($laptop)->getJson('/api/user')->assertOk();
});
