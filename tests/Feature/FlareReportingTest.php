<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Thijssensoftware\FlareClient\Enums\Source;
use Thijssensoftware\FlareClient\FlareClientServiceProvider;
use Thijssensoftware\FlareClient\Reporter;
use Thijssensoftware\RequestId\RequestIdContext;
use Thijssensoftware\RequestId\RequestIdServiceProvider;

/**
 * The reporting itself lives in thijssensoftware/flare-client and is tested
 * there. What is worth pinning down here is the wiring a clone inherits: that
 * the packages are discovered, that the template ships them switched off, and
 * that they actually fire once an app turns them on.
 */
function enableFlare(): void
{
    config()->set('flare-client.enabled', true);
    config()->set('flare-client.key', 'test-key');
    config()->set('flare-client.spool.enabled', false);
}

it('discovers both packages without any registration in the app', function (): void {
    expect(app()->getLoadedProviders())
        ->toHaveKey(FlareClientServiceProvider::class)
        ->toHaveKey(RequestIdServiceProvider::class);
});

it('ships disabled, so a fresh clone reports nothing', function (): void {
    // Asserted against the shipped example rather than config(), since
    // phpunit.xml pins it off and would make this pass either way.
    expect(file_get_contents(base_path('.env.example')))
        ->toContain('FLARE_ENABLED=false')
        ->toContain('FLARE_KEY=');
});

it('sends nothing while it is disabled', function (): void {
    Http::fake();

    Route::middleware('web')->get('/flare-off', fn () => throw new RuntimeException('Nobody should hear this.'));

    $this->get('/flare-off')->assertStatus(500);

    Http::assertNothingSent();
});

it('sends nothing when enabled without a key', function (): void {
    Http::fake();
    config()->set('flare-client.enabled', true);
    config()->set('flare-client.key', '');

    app(Reporter::class)->report(new RuntimeException('No key, no report.'), Source::Console);

    Http::assertNothingSent();
});

it('reports an uncaught exception once the app switches it on', function (): void {
    Http::fake(['*' => Http::response(['status' => 'ok'])]);
    enableFlare();

    Route::middleware('web')->get('/flare-on', fn () => throw new RuntimeException('This one should land.'));

    $this->get('/flare-on')->assertStatus(500);

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/ingest')
        && $request['exception']['message'] === 'This one should land.');
});

// Flow control, not bugs. A 404 on every scanned URL would otherwise be the
// loudest thing in the tracker.
it('does not report a 404', function (): void {
    Http::fake();
    enableFlare();

    $this->get('/no-such-page')->assertNotFound();

    Http::assertNothingSent();
});

it('stamps a request id on the response', function (): void {
    $response = $this->get('/');

    expect($response->headers->get('X-Request-Id'))->not->toBeEmpty();
});

it('adopts the request id an upstream app sent', function (): void {
    $upstream = (string) Str::ulid();

    $response = $this->withHeader('X-Request-Id', $upstream)->get('/');

    expect($response->headers->get('X-Request-Id'))->toBe($upstream);
});

// Only a ULID or UUID is adopted. An arbitrary client string would end up in
// log files, alert mail and the flare UI, so a junk value is replaced rather
// than trusted.
it('refuses a request id that is not one it would have produced', function (): void {
    $response = $this->withHeader('X-Request-Id', '<script>alert(1)</script>')->get('/');

    $id = (string) $response->headers->get('X-Request-Id');

    expect($id)->not->toBe('<script>alert(1)</script>')
        ->and(Str::isUlid($id))->toBeTrue();
});

// The join key snag uses to trace a human bug report back to the exception
// behind it, so the id on the response has to be the id on the report.
it('reports under the same request id it echoed back', function (): void {
    Http::fake(['*' => Http::response(['status' => 'ok'])]);
    enableFlare();

    $upstream = (string) Str::ulid();

    Route::middleware('web')->get('/flare-correlated', fn () => throw new RuntimeException('Correlate me.'));

    $response = $this->withHeader('X-Request-Id', $upstream)->get('/flare-correlated');

    expect($response->headers->get('X-Request-Id'))->toBe($upstream);

    Http::assertSent(fn ($request) => ($request['request_id'] ?? null) === $upstream);
});

it('carries the request id into console runs too', function (): void {
    expect(app(RequestIdContext::class)->current())->not->toBeEmpty();
});
