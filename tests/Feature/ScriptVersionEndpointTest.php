<?php

declare(strict_types=1);

use App\Enums\CheckupScript;
use Illuminate\Support\Facades\RateLimiter;

it('answers with the current version of each script and nothing else', function (CheckupScript $script) {
    $response = $this->get('/scripts/version/'.$script->value)->assertOk();

    expect($response->getContent())->toBe($script->version())
        ->and($response->getContent())->toMatch('/\A[0-9a-f]{8}\z/')
        ->and($response->headers->get('content-type'))->toStartWith('text/plain')
        ->and($response->headers->get('x-content-type-options'))->toBe('nosniff');
})->with([CheckupScript::Windows, CheckupScript::Mac]);

it('is the version the download itself carries', function (CheckupScript $script) {
    $version = $this->get('/scripts/version/'.$script->value)->getContent();

    expect($this->get(route('scripts.download', $script))->getContent())->toContain($version);
})->with([CheckupScript::Windows, CheckupScript::Mac]);

it('is never stored by a browser or a proxy', function () {
    $response = $this->get('/scripts/version/mac')->assertOk();

    $directives = array_map(trim(...), explode(',', (string) $response->headers->get('cache-control')));

    expect($directives)->toContain('no-store')->not->toContain('public')
        ->and($response->headers->has('etag'))->toBeFalse();
});

it('starts no session and sets no cookie', function () {
    $response = $this->withHeader('Cookie', 'appearance=dark; laravel_session=abc')->get('/scripts/version/windows')->assertOk();

    expect($response->headers->getCookies())->toBe([])
        ->and($response->headers->has('set-cookie'))->toBeFalse()
        ->and(session()->isStarted())->toBeFalse();
});

it('answers a guest, without any account', function () {
    $this->get('/scripts/version/mac')->assertOk();
});

it('does not know any other platform', function (string $platform) {
    $this->get('/scripts/version/'.$platform)->assertNotFound();
})->with(['linux', 'WINDOWS', 'macos', '..%2F.env', 'mac-checkup.sh', 'windows.ps1']);

it('answers nothing for a request with a body or another method', function () {
    $this->post('/scripts/version/mac')->assertStatus(405);
});

it('is throttled', function () {
    RateLimiter::clear('127.0.0.1');

    foreach (range(1, 60) as $ignored) {
        $this->get('/scripts/version/mac')->assertOk();
    }

    $this->get('/scripts/version/mac')->assertStatus(429);
});
