<?php

declare(strict_types=1);

use App\Actions\Auth\ConsumeLoginLink;
use App\Actions\Auth\IssueLoginLink;
use App\Enums\LoginLinkOutcome;
use App\Mail\LoginLinkMail;
use App\Models\LoginLink;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\withServerVariables;
use function Pest\Laravel\withSession;

const NEUTRAL = 'If that address has an account, a link is on its way.';

/**
 * @return array{0: User, 1: string}
 */
function userWithLink(array $attributes = []): array
{
    $user = User::factory()->create($attributes);

    Mail::fake();
    post(route('login.link.request'), ['email' => $user->email]);

    return [$user, tokenFromLastMail()];
}

function tokenFromLastMail(): string
{
    $mail = Mail::sent(LoginLinkMail::class)->last();
    expect($mail)->not->toBeNull();

    return basename((string) parse_url($mail?->url, PHP_URL_PATH));
}

function linkOf(string $token): LoginLink
{
    return LoginLink::query()->where('token_hash', hash('sha256', $token))->sole();
}

describe('requesting a link', function () {
    it('answers an unknown address exactly like a known one and sends nothing', function () {
        Mail::fake();
        $known = User::factory()->create();

        $unknown = post(route('login.link.request'), ['email' => 'nobody@example.com']);
        $unknownBody = $unknown->getContent();
        $mine = post(route('login.link.request'), ['email' => $known->email]);

        $unknown->assertRedirect(route('login'))->assertSessionHas('status', NEUTRAL);
        $mine->assertRedirect(route('login'))->assertSessionHas('status', NEUTRAL);
        expect($mine->status())->toBe($unknown->status())
            ->and($mine->getContent())->toBe($unknownBody);
        Mail::assertSent(LoginLinkMail::class, 1);
        expect(LoginLink::query()->count())->toBe(1);
    });

    it('mails one link and stores only its hash', function () {
        [$user, $token] = userWithLink();

        Mail::assertSent(LoginLinkMail::class, fn (LoginLinkMail $mail) => $mail->hasTo($user->email)
            && $mail->hasSubject('Your PC Checkup login link'));
        expect($token)->toHaveLength(64);

        $link = linkOf($token);
        expect($link->user_id)->toBe($user->id)
            ->and($link->used_at)->toBeNull()
            ->and($link->expires_at->between(now()->addMinutes(14), now()->addMinutes(15)->addSecond()))->toBeTrue();

        foreach (LoginLink::query()->get() as $row) {
            foreach ($row->getAttributes() as $value) {
                expect((string) $value)->not->toContain($token);
            }
        }
    });

    it('states the expiry and an ignore notice in a plain text mail', function () {
        [, $token] = userWithLink();

        Mail::assertSent(LoginLinkMail::class, function (LoginLinkMail $mail) use ($token) {
            $mail->assertSeeInText('expires in 15 minutes');
            $mail->assertSeeInText('If you did not ask for this, ignore this email');
            $mail->assertSeeInText($mail->url);

            return str_ends_with($mail->url, '/login/link/'.$token);
        });
    });

    it('builds the link from APP_URL whatever Host the request carries', function () {
        config(['app.url' => 'https://pc-checkup.example']);
        $user = User::factory()->create();
        Mail::fake();

        withServerVariables(['HTTP_HOST' => 'evil.test'])
            ->post(route('login.link.request'), ['email' => $user->email]);

        Mail::assertSent(LoginLinkMail::class, fn (LoginLinkMail $mail) => str_starts_with($mail->url, 'https://pc-checkup.example/login/link/'));
    });

    it('treats case and surrounding whitespace like the login form', function () {
        $user = User::factory()->create(['email' => 'robbin@example.com']);
        Mail::fake();

        post(route('login.link.request'), ['email' => '  Robbin@EXAMPLE.com ']);

        Mail::assertSent(LoginLinkMail::class, fn (LoginLinkMail $mail) => $mail->hasTo($user->email));
    });

    it('rejects a missing, malformed, array or very long email without sending', function (mixed $email) {
        Mail::fake();

        post(route('login.link.request'), ['email' => $email])->assertSessionHasErrors('email');

        Mail::assertNothingSent();
    })->with([
        'missing' => [null],
        'malformed' => ['not-an-email'],
        'array' => [['a@example.com']],
        'very long' => [str_repeat('a', 5000).'@example.com'],
    ]);

    it('invalidates earlier links when a new one is requested', function () {
        [$user, $first] = userWithLink();
        post(route('login.link.request'), ['email' => $user->email]);
        $second = tokenFromLastMail();

        expect($second)->not->toBe($first)
            ->and(LoginLink::query()->count())->toBe(1);

        post(route('login.link.consume', $first))->assertRedirect(route('login.link.invalid'));
        assertGuest();
        post(route('login.link.consume', $second))->assertRedirect(route('dashboard'));
    });

    it('drops the expired links of that user when issuing a new one', function () {
        $user = User::factory()->create();
        $other = LoginLink::factory()->expired()->for(User::factory())->create();
        LoginLink::factory()->expired()->for($user)->create();
        Mail::fake();

        post(route('login.link.request'), ['email' => $user->email]);

        expect(LoginLink::query()->where('user_id', $user->id)->count())->toBe(1)
            ->and(LoginLink::query()->whereKey($other->id)->exists())->toBeTrue();
    });

    it('throttles to five a minute per address and ip, however the case is varied', function () {
        Mail::fake();
        $user = User::factory()->create(['email' => 'robbin@example.com']);
        $variants = ['robbin@example.com', 'ROBBIN@example.com', 'Robbin@Example.com', ' robbin@example.com', 'robbin@EXAMPLE.com'];

        foreach ($variants as $email) {
            post(route('login.link.request'), ['email' => $email])->assertRedirect(route('login'));
        }

        post(route('login.link.request'), ['email' => 'rObBiN@example.com'])->assertTooManyRequests();
        post(route('login.link.request'), ['email' => $user->email])->assertTooManyRequests();
        post(route('login.link.request'), ['email' => 'someone-else@example.com'])->assertRedirect(route('login'));
    });

    it('caps one address at ten an hour across every ip', function () {
        Mail::fake();
        $user = User::factory()->create();

        foreach (range(1, 10) as $i) {
            withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$i])
                ->post(route('login.link.request'), ['email' => $user->email])
                ->assertRedirect(route('login'));
        }

        withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
            ->post(route('login.link.request'), ['email' => strtoupper($user->email)])
            ->assertTooManyRequests();
    });

    it('is closed to people who are already signed in', function () {
        Mail::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('login.link.request'), ['email' => $user->email])->assertRedirect();

        Mail::assertNothingSent();
    });

    it('is unavailable in workflow mode', function () {
        config(['workflow.enabled' => true]);

        post(route('login.link.request'), ['email' => 'a@example.com'])->assertNotFound();
        get(route('login.link.show', 'x'))->assertNotFound();
        post(route('login.link.consume', 'x'))->assertNotFound();
    });
});

describe('opening the link', function () {
    it('shows a confirm page and neither signs in nor consumes on GET', function () {
        [$user, $token] = userWithLink();

        get(route('login.link.show', $token))
            ->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Cache-Control')
            ->assertInertia(fn (Assert $page) => $page->component('auth/ConfirmLoginLink')->where('token', $token)->where('email', fn (string $email) => str_contains($email, '*') && str_ends_with($email, strstr($user->email, '@'))));

        assertGuest();
        expect(linkOf($token)->used_at)->toBeNull();
    });

    it('answers a prefetcher the same way over and over', function () {
        [, $token] = userWithLink();

        foreach (range(1, 3) as $ignored) {
            get(route('login.link.show', $token))->assertOk();
        }
        assertGuest();

        post(route('login.link.consume', $token))->assertRedirect(route('dashboard'));
    });

    it('shows the generic page for an unusable token on GET', function (string $token) {
        get(route('login.link.show', $token))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/LoginLinkInvalid'));
    })->with(['unknown' => [str_repeat('a', 64)], 'short' => ['abc'], 'space' => [' ']]);

    it('renders the generic page when sent there', function () {
        get(route('login.link.invalid'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/LoginLinkInvalid'));
    });

    it('shows the same generic page for an expired or used link on GET', function () {
        [, $expiredToken] = userWithLink();
        linkOf($expiredToken)->forceFill(['expires_at' => now()->subSecond()])->save();
        [, $usedToken] = userWithLink();
        linkOf($usedToken)->forceFill(['used_at' => now()])->save();

        foreach ([$expiredToken, $usedToken] as $token) {
            get(route('login.link.show', $token))
                ->assertInertia(fn (Assert $page) => $page->component('auth/LoginLinkInvalid'));
        }
    });
});

describe('confirming the link', function () {
    it('signs in once, remembers the user and sends them to the dashboard', function () {
        [$user, $token] = userWithLink();

        $response = post(route('login.link.consume', $token));

        $response->assertRedirect(route('dashboard'));
        assertAuthenticatedAs($user);
        expect($response->getCookie(Auth::guard()->getRecallerName()))->not->toBeNull()
            ->and(linkOf($token)->used_at)->not->toBeNull();
    });

    it('refuses a second use of the same link', function () {
        [, $token] = userWithLink();
        post(route('login.link.consume', $token))->assertRedirect(route('dashboard'));

        post(route('logout'));
        post(route('login.link.consume', $token))->assertRedirect(route('login.link.invalid'));

        assertGuest();
    });

    it('refuses an expired link, on the exact second too', function () {
        [, $token] = userWithLink();
        $this->travel(15)->minutes();
        $this->travel(1)->second();

        post(route('login.link.consume', $token))->assertRedirect(route('login.link.invalid'));

        assertGuest();
    });

    it('refuses tampered, truncated, extended and nonsense tokens alike', function (callable $mutate) {
        [, $token] = userWithLink();

        post(route('login.link.consume', $mutate($token)))->assertRedirect(route('login.link.invalid'));

        assertGuest();
        expect(linkOf($token)->used_at)->toBeNull();
    })->with([
        'flipped char' => [fn (string $t) => ($t[0] === 'a' ? 'b' : 'a').substr($t, 1)],
        'truncated' => [fn (string $t) => substr($t, 0, 63)],
        'extended' => [fn (string $t) => $t.'x'],
        'uppercased' => [fn (string $t) => strtoupper($t) === $t ? strtolower($t) : strtoupper($t)],
        'short' => [fn (string $t) => 'abc'],
        'blank' => [fn (string $t) => ' '],
        'very long' => [fn (string $t) => str_repeat('a', 2000)],
        'the stored hash' => [fn (string $t) => hash('sha256', $t)],
    ]);

    it('never signs anyone in as somebody other than the user it was issued to', function () {
        [$alice, $aliceToken] = userWithLink();
        [$bob, $bobToken] = userWithLink();

        post(route('login.link.consume', $aliceToken));
        assertAuthenticatedAs($alice);
        post(route('logout'));

        post(route('login.link.consume', $bobToken));
        assertAuthenticatedAs($bob);
    });

    it('refuses the link of a user who was deleted after asking', function () {
        [$user, $token] = userWithLink();
        $user->delete();

        post(route('login.link.consume', $token))->assertRedirect(route('login.link.invalid'));

        assertGuest();
    });

    it('sends a user with two-factor to the challenge instead of signing them in', function () {
        [$user, $token] = userWithLink(['two_factor_secret' => encrypt('secret'), 'two_factor_recovery_codes' => encrypt('[]'), 'two_factor_confirmed_at' => now()]);

        post(route('login.link.consume', $token))
            ->assertRedirect(route('two-factor.login'))
            ->assertSessionHas('login.id', $user->id)
            ->assertSessionHas('login.remember', true);

        assertGuest();
        get(route('two-factor.login'))->assertOk();
        expect(linkOf($token)->used_at)->not->toBeNull();
    });

    it('does not challenge a user whose two-factor was never confirmed', function () {
        [$user, $token] = userWithLink(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => null]);

        post(route('login.link.consume', $token))->assertRedirect(route('dashboard'));

        assertAuthenticatedAs($user);
    });

    it('honours the intended url that the auth middleware stored', function () {
        [, $token] = userWithLink();

        withSession(['url.intended' => route('machines.index')])
            ->post(route('login.link.consume', $token))
            ->assertRedirect(route('machines.index'));
    });

    it('ignores every client supplied redirect', function () {
        [, $token] = userWithLink();

        post(route('login.link.consume', $token).'?redirect=https://evil.test&next=https://evil.test', [
            'redirect' => 'https://evil.test',
            'intended' => 'https://evil.test',
            'redirect_to' => '//evil.test',
            'url' => 'https://evil.test',
        ])->assertRedirect(route('dashboard'));
    });

    it('regenerates the session id so a planted one is worthless', function (array $attributes) {
        [, $token] = userWithLink($attributes);
        $store = app('session.store');
        $store->start();
        $planted = $store->getId();
        $request = Request::create('/');
        $request->setLaravelSession($store);

        app(ConsumeLoginLink::class)->handle($token, $request);

        expect($store->getId())->not->toBe($planted);
    })->with([
        'password-only user' => [[]],
        'two-factor user' => [['two_factor_secret' => 'x', 'two_factor_confirmed_at' => '2026-01-01']],
    ]);
});

describe('the actions', function () {
    it('treats an empty token as invalid', function () {
        $request = Request::create('/');
        $request->setLaravelSession(app('session.store'));

        expect(app(ConsumeLoginLink::class)->handle('', $request))->toBe(LoginLinkOutcome::Invalid);
    });

    it('does nothing for an address that has no account', function () {
        Mail::fake();

        app(IssueLoginLink::class)->handle('ghost@example.com');

        Mail::assertNothingSent();
        expect(LoginLink::query()->count())->toBe(0);
    });

    it('generates unguessable tokens', function () {
        Mail::fake();
        $user = User::factory()->create();

        app(IssueLoginLink::class)->handle($user->email);
        $first = tokenFromLastMail();
        app(IssueLoginLink::class)->handle($user->email);

        expect(Str::length($first))->toBeGreaterThanOrEqual(64)
            ->and(tokenFromLastMail())->not->toBe($first);
    });
});
