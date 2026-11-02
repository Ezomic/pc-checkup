<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\URL;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

const RUSTDESK_KEY = 'AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcdefg=';

function configureRustDesk(array $overrides = []): void
{
    config()->set('rustdesk', [
        'host' => 'rd.example.test',
        'relay' => null,
        'key' => RUSTDESK_KEY,
        'link_minutes' => 60,
        ...$overrides,
    ]);
}

it('gives a signed-in user the installer with the server values filled in', function () {
    configureRustDesk();
    actingAs(User::factory()->create());

    $response = get(route('tools.rustdesk.download'))->assertOk();

    expect($response->headers->get('Content-Disposition'))->toContain('rustdesk-install.ps1')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');

    $body = $response->getContent();

    expect($body)->toContain('rd.example.test')
        ->and($body)->toContain(RUSTDESK_KEY)
        ->and($body)->not->toContain('{{');
});

it('uses the relay host when one is set, and the server host otherwise', function () {
    configureRustDesk(['relay' => 'relay.example.test']);
    actingAs(User::factory()->create());

    expect(get(route('tools.rustdesk.download'))->getContent())->toContain('relay.example.test');

    configureRustDesk();

    expect(substr_count(get(route('tools.rustdesk.download'))->getContent(), 'rd.example.test'))->toBeGreaterThan(1);
});

it('never puts a password in the script', function () {
    configureRustDesk();
    actingAs(User::factory()->create());

    expect(get(route('tools.rustdesk.download'))->getContent())
        ->toContain('[string]$Password')
        ->and(get(route('tools.rustdesk.download'))->getContent())->not->toMatch('/\$Password\s*=\s*[\'"]/');
});

it('serves the installer through a valid temporary link without signing in', function () {
    configureRustDesk();

    get(URL::temporarySignedRoute('tools.rustdesk.download', now()->addMinutes(5)))->assertOk();
});

it('refuses an expired temporary link', function () {
    configureRustDesk();
    $url = URL::temporarySignedRoute('tools.rustdesk.download', now()->addMinutes(5));

    $this->travel(6)->minutes();

    get($url)->assertNotFound();
});

it('refuses a tampered temporary link', function () {
    configureRustDesk();
    $url = URL::temporarySignedRoute('tools.rustdesk.download', now()->addMinutes(5));

    get($url.'x')->assertNotFound();
});

it('hides the installer from a guest without a link', function () {
    configureRustDesk();

    get(route('tools.rustdesk.download'))->assertNotFound();
});

it('hides the installer from a user who has not verified their email', function () {
    configureRustDesk();
    actingAs(User::factory()->unverified()->create());

    get(route('tools.rustdesk.download'))->assertNotFound();
});

it('does not offer the installer until the server is configured', function (array $overrides) {
    configureRustDesk($overrides);
    actingAs(User::factory()->create());

    get(route('tools.rustdesk.download'))->assertNotFound();
})->with([
    'no host' => [['host' => null]],
    'no key' => [['key' => null]],
    'host with a quote' => [['host' => "rd.example.test'; calc; '"]],
    'host with a space' => [['host' => 'rd example']],
    'key with a quote' => [['key' => "abcdefghijklmnopqrstuvwxyz'; calc"]],
    'key too short' => [['key' => 'abc']],
    'relay with a quote' => [['relay' => "x'; calc; '"]],
]);

it('shows the tools page with a fresh temporary link', function () {
    configureRustDesk();
    actingAs(User::factory()->create());

    get(route('tools.index'))->assertInertia(fn ($page) => $page
        ->component('tools/Index')
        ->where('rustdesk.configured', true)
        ->where('rustdesk.link_minutes', 60)
        ->where('rustdesk.temporary_url', fn (string $url) => str_contains($url, 'signature=')
            && str_contains($url, 'expires=')));
});

it('tells the owner when the installer is not set up', function () {
    configureRustDesk(['host' => null]);
    actingAs(User::factory()->create());

    get(route('tools.index'))->assertInertia(fn ($page) => $page
        ->where('rustdesk.configured', false)
        ->where('rustdesk.temporary_url', null));
});

it('keeps the tools page for signed-in users', function () {
    get(route('tools.index'))->assertRedirect(route('login'));
});
